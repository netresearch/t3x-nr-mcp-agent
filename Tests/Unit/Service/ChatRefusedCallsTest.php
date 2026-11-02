<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Closure;
use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
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
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use Netresearch\NrMcpAgent\Service\RefusedCalls;
use Netresearch\NrMcpAgent\Service\RunActivityRecorder;
use Netresearch\NrMcpAgent\Service\RunRecord;
use Netresearch\NrMcpAgent\Service\RunRecordNotes;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Calls nr-llm refused rather than ran, as the chat's activity list and its
 * run record (ADR-030) read them. The runtime below sends the steps through
 * the onStep callback as nr-llm does, so the activity list is the one the
 * chat writes, not a list built in the test.
 *
 * nr-llm records every refusal as a tool step with an error result and a
 * duration of exactly 0.0 (ToolLoopService::recordResumedRefusal() and
 * settleBesideProposal(), nr-llm 0.41.7 to 0.43.4).
 */
#[CoversClass(ChatService::class)]
#[CoversClass(RefusedCalls::class)]
#[CoversClass(RunActivityRecorder::class)]
#[CoversClass(RunRecord::class)]
final class ChatRefusedCallsTest extends TestCase
{
    /**
     * @param list<RunStep> $steps the steps the runtime sends, in order, and returns
     */
    private function service(array $steps, AgentRunOutcome $outcome = AgentRunOutcome::AWAITING_APPROVAL): ChatService
    {
        $result = new AgentRunResult($outcome, 'run-1', $steps, new ToolLoopResult('Weiter.', [], 1, false, new UsageStatistics(1, 1, 2)));
        $replay = static function (?Closure $onStep) use ($steps, $result): AgentRunResult {
            foreach ($steps as $step) {
                $onStep?->__invoke($step);
            }

            return $result;
        };
        $agentRuntime = $this->createMock(AgentRuntimeInterface::class);
        $agentRuntime->method('run')->willReturnCallback(static fn (mixed $request, ?Closure $onStep = null): AgentRunResult => $replay($onStep));
        $agentRuntime->method('approve')->willReturnCallback(static fn (mixed $a, mixed $b, mixed $c, ?Closure $onStep = null): AgentRunResult => $replay($onStep));
        $agentRuntime->method('submitInput')->willReturnCallback(static fn (mixed $a, mixed $b, mixed $c, ?Closure $onStep = null): AgentRunResult => $replay($onStep));

        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('updateIf')->willReturn(true);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $configuration = $this->createMock(LlmConfiguration::class);
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('getLlmModel')->willReturn($this->createMock(LlmModel::class));
        $task = $this->createMock(Task::class);
        $task->method('getConfiguration')->willReturn($configuration);
        $taskRepository = $this->createMock(TaskRepository::class);
        $taskRepository->method('findByUid')->willReturn($task);
        $adapterRegistry = $this->createMock(ProviderAdapterRegistryInterface::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn($this->createMock(ProviderInterface::class));

        return new ChatService(
            $repository,
            $config,
            $agentRuntime,
            $this->createMock(PendingApprovalReaderInterface::class),
            $this->createMock(AgentRunRepositoryInterface::class),
            $taskRepository,
            $adapterRegistry,
            $this->createMock(ResourceFactory::class),
            $this->createMock(SiteFinder::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $this->createMock(UserContextPrompt::class),
            new RunActivityRecorder($this->createMock(ConversationRepository::class)),
        );
    }

    private function waitingForApproval(): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Diese Seite prüfen');
        $conversation->appendMessage(MessageRole::Assistant, 'Ich schlage einen Titel vor.');
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid('run-1');

        return $conversation;
    }

    private function refused(string $tool, int $round): RunStep
    {
        return new RunStep(kind: RunStep::KIND_TOOL, round: $round, durationMs: 0.0, toolName: $tool, toolResult: 'Error: refused.', toolIsError: true);
    }

    private function round(int $round, string ...$requested): RunStep
    {
        return new RunStep(kind: RunStep::KIND_LLM, round: $round, durationMs: 1.0, requestedToolCalls: array_map(
            static fn (string $name): array => ['id' => 'c-' . $name, 'name' => $name, 'arguments' => []],
            array_values($requested),
        ));
    }

    /**
     * The tool entries of the activity list, without durations.
     *
     * @return list<array<string, bool|int|string>>
     */
    private function toolEntries(Conversation $conversation): array
    {
        $entries = [];
        foreach ($conversation->getActivity() as $entry) {
            if (($entry['kind'] ?? '') === 'tool') {
                unset($entry['ms'], $entry['round']);
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /** @return array<string, mixed> */
    private function lastRecord(Conversation $conversation): array
    {
        $records = $conversation->getRunRecords();

        return $records[array_key_last($records)] ?? [];
    }

    /**
     * @return iterable<string, array{0: ?DenyReason}>
     */
    public static function denials(): iterable
    {
        yield 'Behalten' => [DenyReason::Keep];
        yield 'Überspringen' => [DenyReason::Skip];
        yield 'Andere Variante' => [DenyReason::Variant];
        yield 'plain denial' => [null];
    }

    /**
     * Measured on the demo on 2026-11-02 (conversation 306): after "Behalten"
     * the list showed "Von dir abgelehnt" and below it "Werkzeug:
     * update_page_metadata fehlgeschlagen". The call never ran; the decision
     * is the entry, with the answer the editor gave. A tool that fails after
     * the model's round is still shown.
     */
    #[Test]
    #[DataProvider('denials')]
    public function aDeclinedCardShowsTheDecisionAndNoFailedTool(?DenyReason $reason): void
    {
        $conversation = $this->waitingForApproval();
        $service      = $this->service([
            $this->refused('update_page_metadata', 5),
            $this->round(6, 'search_fal_files'),
            new RunStep(kind: RunStep::KIND_TOOL, round: 6, durationMs: 4.0, toolName: 'search_fal_files', toolIsError: true),
            $this->round(7, 'update_page_metadata'),
        ]);
        $card = ['tool' => 'update_page_metadata', 'table' => 'pages', 'uid' => 3, 'fields' => ['og_title']];
        self::assertTrue($service->recordDecision($conversation, false, 'digest', $reason, $reason instanceof DenyReason ? 'Antwort' : '', $card));
        $service->processConversation($conversation);

        $activity = $conversation->getActivity();
        $decision = ['kind' => 'approval', 'approved' => false] + ($reason instanceof DenyReason ? ['reason' => $reason->value] : []);
        self::assertSame($decision, $activity[0] ?? null);
        self::assertSame([['kind' => 'tool', 'tool' => 'search_fal_files', 'error' => true]], $this->toolEntries($conversation));
    }

    /**
     * An approved call nr-llm no longer lets run: its tool was switched off
     * or made admin-only while the card waited. The change was not made, and
     * the editor is told why.
     */
    #[Test]
    public function anApprovedCallTheRuntimeNoLongerPermitsIsShownAsNotPermitted(): void
    {
        $conversation = $this->waitingForApproval();
        $service      = $this->service([$this->refused('update_page_metadata', 5), $this->round(6)], AgentRunOutcome::COMPLETED);
        self::assertTrue($service->recordDecision($conversation, true, 'digest'));
        $service->processConversation($conversation);

        self::assertSame(
            [['kind' => 'tool', 'tool' => 'update_page_metadata', 'error' => true, 'refusal' => RefusedCalls::NOT_PERMITTED]],
            $this->toolEntries($conversation),
        );
        $notes = implode("\n", array_column(RunRecordNotes::of($conversation), 'text'));
        self::assertStringContainsString('update_page_metadata ×1 (1 failed)', $notes);
    }

    /** An approved call that ran and failed stays a failure without a refusal. */
    #[Test]
    public function anApprovedCallThatRanAndFailedIsAPlainFailure(): void
    {
        $conversation = $this->waitingForApproval();
        $service      = $this->service([
            new RunStep(kind: RunStep::KIND_TOOL, round: 5, durationMs: 2.0, toolName: 'update_page_metadata', toolIsError: true),
            $this->round(6),
        ], AgentRunOutcome::COMPLETED);
        self::assertTrue($service->recordDecision($conversation, true, 'digest'));
        $service->processConversation($conversation);

        self::assertSame([['kind' => 'tool', 'tool' => 'update_page_metadata', 'error' => true]], $this->toolEntries($conversation));
    }

    /**
     * A second write the model asked for beside a proposal, held back by
     * nr-llm until the editor has decided ("one approval per turn"), or a
     * call that needs an answer while the run waits. It did not fail and
     * comes again; the record does not list it as a tool that ran.
     */
    #[Test]
    public function aCallHeldBackWhileTheRunWaitsIsNotAFailure(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Diese Seite prüfen');
        $conversation->setStatus(ConversationStatus::Processing);

        $service = $this->service([
            $this->round(1, 'update_page_metadata', 'update_content_element'),
            $this->refused('update_content_element', 1),
        ]);
        $service->processConversation($conversation);

        self::assertSame(
            [['kind' => 'tool', 'tool' => 'update_content_element', 'error' => false, 'refusal' => RefusedCalls::HELD]],
            $this->toolEntries($conversation),
        );
        self::assertSame([], $this->lastRecord($conversation)['tools'] ?? null);
    }

    /**
     * After an answer to a question nr-llm refuses a call of the waiting turn
     * it cannot run without a second answer or an approval; the model asks
     * for it again. Shown as not run, left out of the record.
     */
    #[Test]
    public function aCallRefusedAfterAnAnswerIsNotAFailure(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Diese Seite prüfen');
        $conversation->setStatus(ConversationStatus::AwaitingInput);
        $conversation->setApprovalRunUuid('run-1');

        $service = $this->service([
            new RunStep(kind: RunStep::KIND_TOOL, round: 3, durationMs: 3.0, toolName: 'ask_choice', toolIsError: false),
            $this->refused('update_page_metadata', 3),
            $this->round(4),
        ], AgentRunOutcome::COMPLETED);
        self::assertTrue($service->recordInput($conversation, ['choice' => 'Ohne Vorschaubild weiter'], 'digest', 'Ohne Vorschaubild weiter'));
        $service->processConversation($conversation);

        self::assertSame([
            ['kind' => 'tool', 'tool' => 'ask_choice', 'error' => false],
            ['kind' => 'tool', 'tool' => 'update_page_metadata', 'error' => false, 'refusal' => RefusedCalls::HELD],
        ], $this->toolEntries($conversation));
        self::assertSame([], $this->lastRecord($conversation)['tools'] ?? null);
    }

    /** A tool nr-llm ran and that failed in an ordinary turn stays a plain failure. */
    #[Test]
    public function aToolThatRanAndFailedInATurnIsAPlainFailure(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Suche');
        $conversation->setStatus(ConversationStatus::Processing);

        $service = $this->service([
            $this->round(1, 'search_records'),
            new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 3.0, toolName: 'search_records', toolIsError: true),
            $this->round(2),
        ], AgentRunOutcome::COMPLETED);
        $service->processConversation($conversation);

        self::assertSame([['kind' => 'tool', 'tool' => 'search_records', 'error' => true]], $this->toolEntries($conversation));
        self::assertSame([['tool' => 'search_records', 'calls' => 1, 'errors' => 1]], $this->lastRecord($conversation)['tools'] ?? null);
    }
}
