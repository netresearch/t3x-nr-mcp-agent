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
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * A card whose run is decided outside the chat, and a card the chat leaves
 * (nr-llm ADR-214, item 9).
 *
 * - Released or denied in the Agent Runs inbox and finished there: the card
 *   closes with a note of what the run wrote, and the conversation is idle.
 * - A new message while the card waits: the run is cancelled, unless it was
 *   decided elsewhere meanwhile.
 * - The three answers appear only for a write in a process run.
 */
#[CoversClass(ChatService::class)]
final class ChatServiceDecidedElsewhereTest extends TestCase
{
    private const RUN = 'run-uuid-1234';

    private AgentRuntimeInterface&MockObject $runtime;

    private ConversationRepository&MockObject $repository;

    /**
     * @param list<AgentRunEvent> $events
     */
    private function service(
        ?AgentRunStatus $status,
        array $events = [],
        ?ProcessRunDetectorInterface $processRuns = null,
        ?ToolEffectResolver $toolEffects = null,
        ?AgentRunStatus $statusAfterCancel = null,
    ): ChatService {
        $this->runtime = $this->createMock(AgentRuntimeInterface::class);
        $this->runtime->method('events')->willReturn($events);

        $runs = $this->createMock(AgentRunRepositoryInterface::class);
        $runs->method('findByUuid')->willReturnOnConsecutiveCalls(
            $status !== null ? $this->agentRun($status) : null,
            $statusAfterCancel !== null ? $this->agentRun($statusAfterCancel) : ($status !== null ? $this->agentRun($status) : null),
        );

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

    // ---- a card decided in the inbox --------------------------------------

    #[Test]
    public function aCardReleasedInTheInboxClosesWithWhatTheRunWrote(): void
    {
        $service = $this->service(AgentRunStatus::COMPLETED, [
            new AgentRunEvent(0, 45, 3, 'tool_write', 1, 0.0, ['writeTargetTable' => 'pages', 'writeTargetUid' => 10073], 0),
        ]);
        $conversation = $this->parked();
        $this->repository->expects(self::once())->method('updateIf')
            ->with($conversation, ConversationStatus::AwaitingApproval)->willReturn(true);

        self::assertTrue($service->reconcile($conversation));

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame('', $conversation->getApprovalRunUuid());
        $last = $conversation->getDecodedMessages()[1] ?? [];
        self::assertSame('assistant', $last['role'] ?? null);
        self::assertSame(
            '[The pending step was decided outside this chat and its run has finished. Records it wrote: pages:10073.]',
            $last['content'] ?? null,
        );
        self::assertSame(ChatService::NOTICE_RUN_FINISHED_OUTSIDE, $last['notice'] ?? null);
    }

    /**
     * @return iterable<string, array{AgentRunStatus}>
     */
    public static function runsNotSettled(): iterable
    {
        yield 'still waiting' => [AgentRunStatus::WAITING_FOR_APPROVAL];
        yield 'being carried on' => [AgentRunStatus::RUNNING];
    }

    #[Test]
    #[DataProvider('runsNotSettled')]
    public function aCardWhoseRunHasNotSettledStays(AgentRunStatus $status): void
    {
        $service = $this->service($status);
        $conversation = $this->parked();
        $this->repository->expects(self::never())->method('updateIf');

        self::assertFalse($service->reconcile($conversation));
        self::assertSame(ConversationStatus::AwaitingApproval, $conversation->getStatus());
        self::assertSame(1, $conversation->getMessageCount());
    }

    // ---- a new message while the card waits -------------------------------

    #[Test]
    public function aWaitingRunIsCancelled(): void
    {
        $service = $this->service(AgentRunStatus::WAITING_FOR_APPROVAL);
        $this->runtime->expects(self::once())->method('cancel')->with(self::anything(), self::RUN)->willReturn(true);

        self::assertTrue($service->releasePendingRun($this->parked()));
    }

    #[Test]
    public function aRunCarriedOnElsewhereIsNeitherCancelledNorLeft(): void
    {
        $service = $this->service(AgentRunStatus::RUNNING);
        $this->runtime->expects(self::never())->method('cancel');

        self::assertFalse($service->releasePendingRun($this->parked()));
    }

    /** Released in the inbox between the read and the cancel: that release wins. */
    #[Test]
    public function aCancelThatLosesTheRaceRefusesTheTurn(): void
    {
        $service = $this->service(AgentRunStatus::WAITING_FOR_APPROVAL, statusAfterCancel: AgentRunStatus::RUNNING);
        $this->runtime->expects(self::once())->method('cancel')->willReturn(false);

        self::assertFalse($service->releasePendingRun($this->parked()));
    }

    /** A run that cannot be read is no reason to keep the conversation stuck. */
    #[Test]
    public function aRunThatCannotBeReadDoesNotBlockTheTurn(): void
    {
        $service = $this->service(null);
        $this->runtime->expects(self::never())->method('cancel');

        self::assertTrue($service->releasePendingRun($this->parked()));
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

    private function view(string ...$tools): WaitingRunView
    {
        return new WaitingRunView(
            self::RUN,
            WaitingRunView::MODE_APPROVAL,
            0,
            'Chat',
            'digest',
            array_map(static fn(string $name): PendingCallView => new PendingCallView($name, '{}', true), $tools),
        );
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

        self::assertTrue($service->offersProcessAnswers($this->parked(), $this->view('tool_0')));
    }

    #[Test]
    public function aReadInAProcessRunKeepsApproveAndCancel(): void
    {
        $service = $this->service(null, processRuns: $this->processRun(true), toolEffects: $this->effects(ToolEffect::READ_ONLY));

        self::assertFalse($service->offersProcessAnswers($this->parked(), $this->view('tool_0')));
    }

    /** nr-llm resolves an unknown tool name as a write, and so does the card. */
    #[Test]
    public function anUnknownToolInAProcessRunCountsAsAWrite(): void
    {
        $service = $this->service(null, processRuns: $this->processRun(true), toolEffects: $this->effects(ToolEffect::READ_ONLY));

        self::assertTrue($service->offersProcessAnswers($this->parked(), $this->view('tool_removed')));
    }

    #[Test]
    public function aWriteOutsideAProcessRunKeepsApproveAndCancel(): void
    {
        $effects = $this->effects(ToolEffect::IDEMPOTENT_WRITE);

        self::assertFalse($this->service(null, processRuns: $this->processRun(false), toolEffects: $effects)->offersProcessAnswers($this->parked(), $this->view('tool_0')));
        self::assertFalse($this->service(null, toolEffects: $effects)->offersProcessAnswers($this->parked(), $this->view('tool_0')), 'no detector: no process run');
    }
}
