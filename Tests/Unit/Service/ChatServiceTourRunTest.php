<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\Inbox\PendingCallView;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use Netresearch\NrMcpAgent\Service\ProcessRunDetectorInterface;
use Netresearch\NrMcpAgent\Service\RunActivityRecorder;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use Netresearch\NrMcpAgent\Service\WaitingRunCancel;
use Netresearch\NrMcpAgent\Service\WaitingRunCancellerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The runs of a guided process (nr-llm ADR-214, item 9).
 *
 * - A new message while a tour's run waits on a card or a question cancels
 *   that run, unless it is being carried on or was decided meanwhile. An
 *   ordinary chat leaves it waiting.
 * - The three answers appear only for a write in a process run.
 */
#[CoversClass(ChatService::class)]
final class ChatServiceTourRunTest extends TestCase
{
    private const RUN = 'run-uuid-1234';

    private AgentRuntimeInterface&MockObject $runtime;

    private ConversationRepository&MockObject $repository;

    private function service(
        ?AgentRunStatus $status,
        ?ProcessRunDetectorInterface $processRuns = null,
        ?ToolEffectResolver $toolEffects = null,
        ?WaitingRunCancellerInterface $runCanceller = null,
    ): ChatService {
        $this->runtime = $this->createMock(AgentRuntimeInterface::class);

        $runs = $this->createMock(AgentRunRepositoryInterface::class);
        $runs->method('findByUuid')->willReturn($status !== null ? $this->agentRun($status) : null);

        $this->repository = $this->createMock(ConversationRepository::class);

        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);

        return new ChatService(
            $this->repository,
            $config,
            $this->runtime,
            $this->createMock(PendingApprovalReaderInterface::class),
            $runs,
            $this->createMock(TaskRepository::class),
            $this->createMock(ProviderAdapterRegistryInterface::class),
            $this->createMock(ResourceFactory::class),
            $this->createMock(SiteFinder::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $this->createMock(UserContextPrompt::class),
            $this->createMock(RunActivityRecorder::class),
            processRuns: $processRuns,
            toolEffects: $toolEffects,
            runCanceller: $runCanceller,
        );
    }

    private function agentRun(AgentRunStatus $status): AgentRun
    {
        $run = (new ReflectionClass(AgentRun::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionClass($run);
        foreach (['uuid' => self::RUN, 'beUser' => 1, 'status' => $status->value] as $name => $value) {
            $reflection->getProperty($name)->setValue($run, $value);
        }

        return $run;
    }

    private function parked(): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Prüfe die Meta Description');
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid(self::RUN);

        return $conversation;
    }

    // ---- a new message while a tour's run waits ---------------------------

    /**
     * @return iterable<string, array{AgentRunStatus}>
     */
    public static function waitingRuns(): iterable
    {
        yield 'on a card' => [AgentRunStatus::WAITING_FOR_APPROVAL];
        yield 'on a question' => [AgentRunStatus::WAITING_FOR_INPUT];
    }

    #[Test]
    #[DataProvider('waitingRuns')]
    public function aWaitingRunIsCancelled(AgentRunStatus $status): void
    {
        $service = $this->service($status, processRuns: $this->processRun(true));
        $this->runtime->expects(self::once())->method('cancel')->with(self::anything(), self::RUN)->willReturn(true);

        self::assertTrue($service->releasePendingRun($this->parked()));
    }

    /**
     * @return iterable<string, array{AgentRunStatus}>
     */
    public static function busyRuns(): iterable
    {
        yield 'running' => [AgentRunStatus::RUNNING];
        yield 'queued' => [AgentRunStatus::QUEUED];
    }

    #[Test]
    #[DataProvider('busyRuns')]
    public function aRunBeingCarriedOnIsNeitherCancelledNorLeft(AgentRunStatus $status): void
    {
        $service = $this->service($status, processRuns: $this->processRun(true));
        $this->runtime->expects(self::never())->method('cancel');

        self::assertFalse($service->releasePendingRun($this->parked()));
    }

    /** Decided on the card between the read and the cancel: that decision wins. */
    #[Test]
    public function aCancelThatLosesTheRaceRefusesTheTurn(): void
    {
        $service = $this->service(AgentRunStatus::WAITING_FOR_APPROVAL, processRuns: $this->processRun(true));
        $this->runtime->expects(self::once())->method('cancel')->willReturn(false);

        self::assertFalse($service->releasePendingRun($this->parked()));
    }

    /** A run that cannot be read is no reason to keep the conversation stuck. */
    #[Test]
    public function aRunThatCannotBeReadDoesNotBlockTheTurn(): void
    {
        $service = $this->service(null, processRuns: $this->processRun(true));
        $this->runtime->expects(self::never())->method('cancel');

        self::assertTrue($service->releasePendingRun($this->parked()));
    }

    /**
     * With nr-llm's guarded cancel the chat does not read and cancel itself:
     * the guard decides, and a run it did not cancel is judged by its state.
     *
     * The row's status is what the chat would read itself; the guarded result
     * must win over it, so the two disagree in every guarded case.
     *
     * @return iterable<string, array{WaitingRunCancel|null, AgentRunStatus, bool}>
     */
    public static function guardedCancels(): iterable
    {
        yield 'cancelled' => [new WaitingRunCancel(true, AgentRunStatus::CANCELLED), AgentRunStatus::RUNNING, true];
        yield 'not waiting, being carried on' => [new WaitingRunCancel(false, AgentRunStatus::RUNNING), AgentRunStatus::COMPLETED, false];
        yield 'handed back, still waiting' => [new WaitingRunCancel(false, AgentRunStatus::WAITING_FOR_APPROVAL), AgentRunStatus::COMPLETED, false];
        yield 'not waiting, decided and finished' => [new WaitingRunCancel(false, AgentRunStatus::COMPLETED), AgentRunStatus::RUNNING, true];
        yield 'unknown to nr-llm' => [new WaitingRunCancel(false, null), AgentRunStatus::RUNNING, true];
        // nr-llm without the guarded cancel: the status read and cancel()
        yield 'no guarded cancel, waiting' => [null, AgentRunStatus::WAITING_FOR_APPROVAL, true];
    }

    #[Test]
    #[DataProvider('guardedCancels')]
    public function theGuardedCancelDecidesWhenNrLlmHasIt(?WaitingRunCancel $guarded, AgentRunStatus $statusRead, bool $released): void
    {
        $canceller = $this->createMock(WaitingRunCancellerInterface::class);
        $canceller->expects(self::once())->method('cancelIfWaiting')->with(self::anything(), self::RUN)->willReturn($guarded);
        $service = $this->service($statusRead, processRuns: $this->processRun(true), runCanceller: $canceller);
        $this->runtime->expects($guarded === null ? self::once() : self::never())->method('cancel')->willReturn(true);

        self::assertSame($released, $service->releasePendingRun($this->parked()));
    }

    /** An ordinary chat leaves the run waiting in the inbox, as before. */
    #[Test]
    public function outsideAGuidedProcessTheRunKeepsWaiting(): void
    {
        $service = $this->service(AgentRunStatus::WAITING_FOR_APPROVAL, processRuns: $this->processRun(false));
        $this->runtime->expects(self::never())->method('cancel');

        self::assertTrue($service->releasePendingRun($this->parked()));
        self::assertTrue($this->service(AgentRunStatus::RUNNING)->releasePendingRun($this->parked()), 'no detector: no process run, no 409');
    }

    // ---- which answers the card offers ------------------------------------

    private function effects(ToolEffect ...$effects): ToolEffectResolver
    {
        $tools = [];
        foreach ($effects as $index => $effect) {
            $tool = $this->createMockForIntersectionOfInterfaces([ToolInterface::class, ToolEffectInterface::class]);
            $tool->method('getSpec')->willReturn(new ToolSpec('tool_' . $index, 'A tool.', ['type' => 'object', 'properties' => []]));
            $tool->method('getEffect')->willReturn($effect);
            $tools[] = $tool;
        }

        return new ToolEffectResolver(new ToolRegistry($tools));
    }

    private static function viewHasDeclaresWrite(): bool
    {
        return property_exists(PendingCallView::class, 'declaresWrite');
    }

    /**
     * A card with one pending call. `$declaresWrite` is what nr-llm 0.41's
     * view factory states for the call; an earlier nr-llm's view has no such
     * member, and the chat resolves the effect by name instead.
     */
    private function view(string $tool, bool $declaresWrite): WaitingRunView
    {
        $call = self::viewHasDeclaresWrite()
            ? new PendingCallView($tool, '{}', true, declaresWrite: $declaresWrite)
            : new PendingCallView($tool, '{}', true);

        return new WaitingRunView(self::RUN, WaitingRunView::MODE_APPROVAL, 0, 'Chat', 'digest', [$call]);
    }

    private function processRun(bool $isProcess): ProcessRunDetectorInterface
    {
        $detector = $this->createStub(ProcessRunDetectorInterface::class);
        $detector->method('isProcessRun')->willReturn($isProcess);

        return $detector;
    }

    #[Test]
    public function aWriteInAProcessRunOffersTheThreeAnswers(): void
    {
        $service = $this->service(null, processRuns: $this->processRun(true), toolEffects: $this->effects(ToolEffect::IDEMPOTENT_WRITE));

        self::assertTrue($service->offersProcessAnswers($this->parked(), $this->view('tool_0', declaresWrite: true)));
    }

    #[Test]
    public function aReadInAProcessRunKeepsApproveAndCancel(): void
    {
        $service = $this->service(null, processRuns: $this->processRun(true), toolEffects: $this->effects(ToolEffect::READ_ONLY));

        self::assertFalse($service->offersProcessAnswers($this->parked(), $this->view('tool_0', declaresWrite: false)));
    }

    /** nr-llm resolves an unknown tool name as a write, and so does the card. */
    #[Test]
    public function anUnknownToolInAProcessRunCountsAsAWrite(): void
    {
        $service = $this->service(null, processRuns: $this->processRun(true), toolEffects: $this->effects(ToolEffect::READ_ONLY));

        self::assertTrue($service->offersProcessAnswers($this->parked(), $this->view('tool_removed', declaresWrite: true)));
    }

    #[Test]
    public function aWriteOutsideAProcessRunKeepsApproveAndCancel(): void
    {
        $effects = $this->effects(ToolEffect::IDEMPOTENT_WRITE);

        self::assertFalse($this->service(null, processRuns: $this->processRun(false), toolEffects: $effects)->offersProcessAnswers($this->parked(), $this->view('tool_0', declaresWrite: true)));
        self::assertFalse($this->service(null, toolEffects: $effects)->offersProcessAnswers($this->parked(), $this->view('tool_0', declaresWrite: true)), 'no detector: no process run');
    }

    /**
     * With nr-llm 0.41 the card follows what nr-llm's view declares, in both
     * directions, and not the chat's own resolution by name.
     */
    #[Test]
    public function theViewsDeclarationWinsOverTheResolver(): void
    {
        if (!self::viewHasDeclaresWrite()) {
            self::markTestSkipped('Needs nr-llm 0.41 (PendingCallView::$declaresWrite, nr-llm PR 1024).');
        }

        $readsByName = $this->service(null, processRuns: $this->processRun(true), toolEffects: $this->effects(ToolEffect::READ_ONLY));
        $writesByName = $this->service(null, processRuns: $this->processRun(true), toolEffects: $this->effects(ToolEffect::IDEMPOTENT_WRITE));

        self::assertTrue($readsByName->offersProcessAnswers($this->parked(), $this->view('tool_0', declaresWrite: true)));
        self::assertFalse($writesByName->offersProcessAnswers($this->parked(), $this->view('tool_0', declaresWrite: false)));
    }
}
