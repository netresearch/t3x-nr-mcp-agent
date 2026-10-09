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
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\Exception\StaleApprovalTurnException;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\DenyReason;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\OpenPoint\OpenPointTarget;
use Netresearch\NrMcpAgent\Service\OpenPoint\OpenPointTrackerInterface;
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use Netresearch\NrMcpAgent\Service\RunActivityRecorder;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Where the worker records and closes open points (ADR-022): the card's
 * target is read before the decision goes to nr-llm, and the outcome is
 * settled only once nr-llm has accepted the decision.
 */
#[CoversClass(ChatService::class)]
final class ChatOpenPointTest extends TestCase
{
    /** @var list<string> */
    private array $order = [];

    private function view(): WaitingRunView
    {
        return new WaitingRunView('run-1', WaitingRunView::MODE_APPROVAL, 0, 'Chat', 'digest-abc', []);
    }

    private function waiting(): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Check the page');
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid('run-1');

        return $conversation;
    }

    private function settledResult(): AgentRunResult
    {
        return new AgentRunResult(AgentRunOutcome::COMPLETED, 'run-1', [], new ToolLoopResult('Next point.', [], 1, false, new UsageStatistics(1, 1, 2)));
    }

    private function service(?OpenPointTrackerInterface $tracker, AgentRunResult|RuntimeException|null $answer = null, ?PendingApprovalReaderInterface $reader = null): ChatService
    {
        $answer ??= $this->settledResult();
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->method('approve')->willReturnCallback(function () use ($answer): AgentRunResult {
            $this->order[] = 'approve';
            if ($answer instanceof RuntimeException) {
                throw $answer;
            }

            return $answer;
        });

        if ($reader === null) {
            $reader = $this->createMock(PendingApprovalReaderInterface::class);
            $reader->method('read')->willReturnCallback(function (): WaitingRunView {
                $this->order[] = 'read';

                return $this->view();
            });
        }

        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('updateIf')->willReturn(true);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);

        return new ChatService(
            $repository,
            $config,
            $runtime,
            $reader,
            $this->createMock(AgentRunRepositoryInterface::class),
            $this->createMock(TaskRepository::class),
            $this->createMock(ProviderAdapterRegistryInterface::class),
            $this->createMock(ResourceFactory::class),
            $this->createMock(SiteFinder::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $this->createMock(UserContextPrompt::class),
            $this->createMock(RunActivityRecorder::class),
            openPoints: $tracker,
        );
    }

    #[Test]
    public function theCardIsReadBeforeTheDecisionAndSettledAfterIt(): void
    {
        $target = new OpenPointTarget('tt_content', 12, ['bodytext']);
        $tracker = $this->createMock(OpenPointTrackerInterface::class);
        $tracker->expects(self::once())->method('cardTarget')
            ->with(self::isInstanceOf(Conversation::class), self::callback(fn(?WaitingRunView $view): bool => $view?->turnDigest === 'digest-abc'))
            ->willReturnCallback(function () use ($target): OpenPointTarget {
                $this->order[] = 'cardTarget';

                return $target;
            });
        $tracker->expects(self::once())->method('settle')
            ->with(self::isInstanceOf(Conversation::class), false, DenyReason::Skip, $target, self::isInstanceOf(AgentRunResult::class))
            ->willReturnCallback(function (): void {
                $this->order[] = 'settle';
            });

        $conversation = $this->waiting();
        $service = $this->service($tracker);
        $service->recordDecision($conversation, false, 'digest-abc', DenyReason::Skip, 'Überspringen');
        $service->processConversation($conversation);

        self::assertSame(['read', 'cardTarget', 'approve', 'settle'], $this->order);
    }

    #[Test]
    public function anApprovalIsSettledAsAnApproval(): void
    {
        $tracker = $this->createMock(OpenPointTrackerInterface::class);
        $tracker->method('cardTarget')->willReturn(new OpenPointTarget('pages', 3, ['title']));
        $tracker->expects(self::once())->method('settle')->with(self::anything(), true, null, self::anything(), self::anything());

        $conversation = $this->waiting();
        $service = $this->service($tracker);
        $service->recordDecision($conversation, true, 'digest-abc');
        $service->processConversation($conversation);
    }

    /** A refused decision hands the card back: nothing was skipped or applied. */
    #[Test]
    public function aRefusedDecisionSettlesNothing(): void
    {
        $tracker = $this->createMock(OpenPointTrackerInterface::class);
        $tracker->method('cardTarget')->willReturn(new OpenPointTarget('tt_content', 12, ['bodytext']));
        $tracker->expects(self::never())->method('settle');

        $conversation = $this->waiting();
        $service = $this->service($tracker, new StaleApprovalTurnException('run-1', 'The review is stale'));
        $service->recordDecision($conversation, false, 'digest-abc', DenyReason::Skip, 'Überspringen');
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::AwaitingApproval, $conversation->getStatus());
    }

    #[Test]
    public function aFailedContinuationSettlesNothing(): void
    {
        $tracker = $this->createMock(OpenPointTrackerInterface::class);
        $tracker->expects(self::never())->method('settle');

        $conversation = $this->waiting();
        $service = $this->service($tracker, new RuntimeException('provider down'));
        $service->recordDecision($conversation, false, 'digest-abc', DenyReason::Skip, 'Überspringen');
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
    }

    /** An open point that cannot be stored costs the open point, not the answer. */
    #[Test]
    public function aFailingTrackerDoesNotFailTheConversation(): void
    {
        $tracker = $this->createMock(OpenPointTrackerInterface::class);
        $tracker->method('settle')->willThrowException(new RuntimeException('database gone'));

        $conversation = $this->waiting();
        $service = $this->service($tracker);
        $service->recordDecision($conversation, false, 'digest-abc', DenyReason::Skip, 'Überspringen');
        $service->processConversation($conversation);

        self::assertNotSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertStringContainsString('Next point.', json_encode($conversation->getMessages(), JSON_THROW_ON_ERROR));
    }

    /** Without open points the worker does not read the card a second time. */
    #[Test]
    public function withoutATrackerTheCardIsNotRead(): void
    {
        $reader = $this->createMock(PendingApprovalReaderInterface::class);
        $reader->expects(self::never())->method('read');

        $conversation = $this->waiting();
        $service = $this->service(null, reader: $reader);
        $service->recordDecision($conversation, true, 'digest-abc');
        $service->processConversation($conversation);
    }
}
