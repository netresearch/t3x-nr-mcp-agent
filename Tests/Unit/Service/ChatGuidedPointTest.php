<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\PendingWriteTarget;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\Inbox\PendingCallView;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Domain\Repository\RunStateRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\DenyReason;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\GuidedStateLinker;
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use Netresearch\NrMcpAgent\Service\ProcessRunDetectorInterface;
use Netresearch\NrMcpAgent\Service\RunActivityRecorder;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Where the chat learns that a point of a guided process is settled and
 * that a run paused on the next proposal (ADR-020, amendment of
 * 2026-10-31): the editor's decision marks the point, and the pause on a
 * process proposal moves the header on by one when the model reported no
 * progress of its own.
 */
#[CoversClass(ChatService::class)]
final class ChatGuidedPointTest extends TestCase
{
    private const LABEL = 'Über uns · Deutsch';

    /** The conversation at point 2 of 5 of a running tour, parked on a proposal. */
    private function waiting(): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setViewContext(20, 'web_layout');
        $conversation->appendMessage(MessageRole::User, 'Diese Seite prüfen');
        $conversation->setGuidedState(['label' => self::LABEL, 'current' => 2, 'total' => 5, 'completed' => false], null);
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid('run-1');

        return $conversation;
    }

    private function proposalView(bool $declaresWrite = true): WaitingRunView
    {
        return new WaitingRunView('run-2', WaitingRunView::MODE_APPROVAL, 0, 'Chat', 'digest-next', [
            new PendingCallView(
                'update_page_metadata',
                '{"uid":20,"description":"…"}',
                true,
                pendingTarget: PendingWriteTarget::fromArguments('pages', 20, ['description']),
                declaresWrite: $declaresWrite,
            ),
        ]);
    }

    private function service(AgentRunResult $answer, ?WaitingRunView $next = null, bool $processRun = true): ChatService
    {
        $runtime = $this->createStub(AgentRuntimeInterface::class);
        $runtime->method('approve')->willReturn($answer);

        $reader = $this->createStub(PendingApprovalReaderInterface::class);
        $reader->method('read')->willReturnCallback(
            static fn (mixed $actor, string $runUuid): ?WaitingRunView => $runUuid === 'run-2' ? $next : new WaitingRunView('run-1', WaitingRunView::MODE_APPROVAL, 0, 'Chat', 'digest-abc', []),
        );

        $runs = $this->createStub(ProcessRunDetectorInterface::class);
        $runs->method('isProcessRun')->willReturn($processRun);

        $runState = $this->createStub(RunStateRepository::class);
        $runState->method('find')->willReturn(null);

        $repository = $this->createStub(ConversationRepository::class);
        $repository->method('updateIf')->willReturn(true);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);

        return new ChatService(
            $repository,
            $config,
            $runtime,
            $reader,
            $this->createStub(AgentRunRepositoryInterface::class),
            $this->createStub(TaskRepository::class),
            $this->createStub(ProviderAdapterRegistryInterface::class),
            $this->createStub(ResourceFactory::class),
            $this->createStub(SiteFinder::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $this->createStub(UserContextPrompt::class),
            $this->createStub(RunActivityRecorder::class),
            processRuns: $runs,
            guidedState: new GuidedStateLinker($runState),
        );
    }

    private function completed(): AgentRunResult
    {
        return new AgentRunResult(AgentRunOutcome::COMPLETED, 'run-1', [], new ToolLoopResult('Weiter.', [], 1, false, new UsageStatistics(1, 1, 2)));
    }

    private function pausedOnTheNextProposal(): AgentRunResult
    {
        return new AgentRunResult(AgentRunOutcome::AWAITING_APPROVAL, 'run-2', []);
    }

    /**
     * @return iterable<string, array{DenyReason|null, bool}>
     */
    public static function decisions(): iterable
    {
        yield 'Behalten' => [DenyReason::Keep, true];
        yield 'Überspringen' => [DenyReason::Skip, true];
        yield 'Andere Variante' => [DenyReason::Variant, false];
    }

    /** "Behalten" and "Überspringen" settle the point; "Andere Variante" stays on it. */
    #[Test]
    #[DataProvider('decisions')]
    public function aDenialSettlesThePointUnlessItAsksForAVariant(?DenyReason $reason, bool $settled): void
    {
        $conversation = $this->waiting();
        $service      = $this->service($this->completed());
        $service->recordDecision($conversation, false, 'digest-abc', $reason, 'x');
        $service->processConversation($conversation);

        self::assertSame($settled, $conversation->isGuidedPointSettled());
    }

    /**
     * A decision settles the point, the run pauses on the next proposal, and
     * the header moves to point 3: nothing the model had to call.
     */
    #[Test]
    public function thePauseOnTheNextProposalMovesThePointOn(): void
    {
        $conversation = $this->waiting();
        $service      = $this->service($this->pausedOnTheNextProposal(), $this->proposalView());
        $service->recordDecision($conversation, false, 'digest-abc', DenyReason::Keep, 'Behalten');
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::AwaitingApproval, $conversation->getStatus());
        self::assertSame(3, $conversation->getGuidedState()['progress']['current'] ?? null);
        self::assertFalse($conversation->isGuidedPointSettled(), 'the move used the decision up');
    }

    /** A variant is another proposal for the same point. */
    #[Test]
    public function aVariantStaysOnThePoint(): void
    {
        $conversation = $this->waiting();
        $service      = $this->service($this->pausedOnTheNextProposal(), $this->proposalView());
        $service->recordDecision($conversation, false, 'digest-abc', DenyReason::Variant, 'Andere Variante');
        $service->processConversation($conversation);

        self::assertSame(2, $conversation->getGuidedState()['progress']['current'] ?? null);
    }

    /**
     * Only a pause on a proposal of a process run moves the point: a pending
     * call that writes nothing, or a run outside a process, does not.
     *
     * @return iterable<string, array{bool, bool}>
     */
    public static function pausesThatAreNoProcessProposal(): iterable
    {
        yield 'a pending call that writes nothing' => [false, true];
        yield 'a run outside a process' => [true, false];
    }

    #[Test]
    #[DataProvider('pausesThatAreNoProcessProposal')]
    public function aPauseThatIsNoProcessProposalLeavesThePoint(bool $declaresWrite, bool $processRun): void
    {
        $conversation = $this->waiting();
        $service      = $this->service($this->pausedOnTheNextProposal(), $this->proposalView($declaresWrite), $processRun);
        $service->recordDecision($conversation, false, 'digest-abc', DenyReason::Keep, 'Behalten');
        $service->processConversation($conversation);

        self::assertSame(2, $conversation->getGuidedState()['progress']['current'] ?? null);
        self::assertTrue($conversation->isGuidedPointSettled(), 'the settled point waits for the next proposal');
    }
}
