<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use Netresearch\NrMcpAgent\Service\RunActivityRecorder;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Text the model writes in the same round as a tool call.
 *
 * The run's final content is only the last round's. A guided tour writes its
 * overview beside chat_set_progress, the explanation of a proposal beside the
 * write that becomes the card, and a status line beside the reads. The chat
 * showed none of it, only the final content.
 */
#[CoversClass(ChatService::class)]
#[CoversClass(Conversation::class)]
final class ChatToolRoundTextTest extends TestCase
{
    private const RUN = 'run-uuid-1234';

    private const OVERVIEW = "**Übersicht:** Drei Punkte auf dieser Seite.\n\n1. Meta Description\n2. Alternativtexte\n3. Überschriften";

    private const EXPLANATION = "**Meta Description:** Sie fehlt.\n\nWarum: Suchmaschinen zeigen sonst einen beliebigen Auszug.";

    private ?AgentRunRequest $capturedRequest = null;

    private function service(AgentRunResult $runAnswer, ?AgentRunResult $approveAnswer = null): ChatService
    {
        $agentRuntime = $this->createMock(AgentRuntimeInterface::class);
        $agentRuntime->method('run')->willReturnCallback(function (AgentRunRequest $request) use ($runAnswer): AgentRunResult {
            $this->capturedRequest = $request;

            return $runAnswer;
        });
        $agentRuntime->method('approve')->willReturn($approveAnswer ?? $this->completed('Erledigt.'));

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
            $this->createMock(RunActivityRecorder::class),
        );
    }

    /**
     * The response half of one model round, as nr-llm's RunTrace records it.
     */
    private function round(int $round, ?string $content, string ...$tools): RunStep
    {
        return new RunStep(
            kind: RunStep::KIND_LLM,
            round: $round,
            durationMs: 1.0,
            content: $content,
            finishReason: $tools === [] ? 'stop' : 'tool_calls',
            requestedToolCalls: $tools === [] ? null : array_map(
                static fn (string $name): array => ['id' => 'call-' . $name, 'name' => $name, 'arguments' => []],
                $tools,
            ),
        );
    }

    private function tool(int $round, string $name): RunStep
    {
        return new RunStep(kind: RunStep::KIND_TOOL, round: $round, durationMs: 1.0, toolName: $name, toolResult: 'ok', toolIsError: false);
    }

    /**
     * @param list<RunStep> $steps
     */
    private function completed(string $text, array $steps = []): AgentRunResult
    {
        return new AgentRunResult(
            AgentRunOutcome::COMPLETED,
            self::RUN,
            $steps,
            new ToolLoopResult($text, [], 1, false, new UsageStatistics(10, 20, 30)),
        );
    }

    private function conversation(): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Prüfe die Seite');

        return $conversation;
    }

    /**
     * @return list<string>
     */
    private function contents(Conversation $conversation): array
    {
        return array_map(
            static fn (array $message): string => is_string($message['content'] ?? null) ? $message['content'] : '',
            $conversation->getDecodedMessages(),
        );
    }

    /**
     * Runs 4702469e and 62f47bae: a status line beside the reads, then the
     * answer. Rounds without text add no message, and the last round is the
     * answer, not a second copy of it.
     */
    #[Test]
    public function aCompletedRunShowsTheTextOfItsToolRoundsBeforeTheAnswer(): void
    {
        $conversation = $this->conversation();
        $result       = $this->completed('Die Seite ist in Ordnung.', [
            $this->round(1, '**Prüfe „Batch-Test“ …**', 'read_records', 'get_page_content'),
            $this->tool(1, 'read_records'),
            $this->tool(1, 'get_page_content'),
            $this->round(2, '', 'chat_list_open_points'),
            $this->tool(2, 'chat_list_open_points'),
            $this->round(3, " \n ", 'chat_set_progress'),
            $this->tool(3, 'chat_set_progress'),
            $this->round(4, null, 'chat_set_progress'),
            $this->round(5, 'Die Seite ist in Ordnung.'),
        ]);

        $this->service($result)->processConversation($conversation);

        self::assertSame(
            ['Prüfe die Seite', '**Prüfe „Batch-Test“ …**', 'Die Seite ist in Ordnung.'],
            $this->contents($conversation),
        );
        $messages = $conversation->getDecodedMessages();
        self::assertSame('assistant', $messages[1]['role']);
        self::assertTrue($messages[1][Conversation::KEY_BESIDE_TOOL_CALL] ?? null);
        self::assertArrayNotHasKey('tool_calls', $messages[1], 'a stored tool_calls key reads as a pending call and hides the message');
        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
    }

    /**
     * Run cebe6c5b, round 7: the explanation beside update_page_metadata.
     * The card renders after the transcript, so the explanation is above it.
     */
    #[Test]
    public function aRunWaitingForApprovalShowsTheExplanationBeforeTheCard(): void
    {
        $conversation = $this->conversation();
        $result       = new AgentRunResult(AgentRunOutcome::AWAITING_APPROVAL, self::RUN, [
            $this->round(1, self::OVERVIEW, 'chat_set_progress'),
            $this->tool(1, 'chat_set_progress'),
            $this->round(2, self::EXPLANATION, 'update_page_metadata'),
        ]);

        $this->service($result)->processConversation($conversation);

        self::assertSame(ConversationStatus::AwaitingApproval, $conversation->getStatus());
        self::assertSame(['Prüfe die Seite', self::OVERVIEW, self::EXPLANATION], $this->contents($conversation));
    }

    /**
     * Run cebe6c5b, round 4: the overview beside chat_set_progress, before
     * the choice that starts the tour.
     */
    #[Test]
    public function aRunAskingForInputShowsTheOverviewBeforeTheQuestion(): void
    {
        $conversation = $this->conversation();
        $result       = new AgentRunResult(
            AgentRunOutcome::AWAITING_INPUT,
            self::RUN,
            [
                $this->round(1, self::OVERVIEW, 'chat_set_progress'),
                $this->tool(1, 'chat_set_progress'),
                $this->round(2, '', 'ask_choice'),
            ],
            suspendedState: new SuspendedRunState([], [], 1, 0, 0, inputToolName: 'ask_choice', inputSchema: [
                'type'       => 'object',
                'title'      => 'Sollen wir beginnen?',
                'properties' => ['choice' => ['oneOf' => [['const' => 'go', 'title' => "Los geht's"]]]],
            ]),
        );

        $this->service($result)->processConversation($conversation);

        self::assertSame(ConversationStatus::AwaitingInput, $conversation->getStatus());
        self::assertSame(['Prüfe die Seite', self::OVERVIEW, 'Sollen wir beginnen?'], $this->contents($conversation));
    }

    /**
     * The continuation after an approval: the outcome line of the decided
     * card comes first, then what the model wrote on its way to the answer.
     */
    #[Test]
    public function anApprovalContinuationShowsItsTextAfterTheOutcomeOfTheCard(): void
    {
        $conversation = $this->conversation();
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid(self::RUN);

        $continuation = $this->completed('Weiter mit den Alternativtexten?', [
            // The approved call runs first; the model rounds follow it.
            $this->tool(0, 'update_page_metadata'),
            $this->round(1, 'Übernommen.', 'chat_set_progress'),
            $this->tool(1, 'chat_set_progress'),
            $this->round(2, 'Weiter mit den Alternativtexten?'),
        ]);
        $service = $this->service($this->completed('unused'), $continuation);

        self::assertTrue($service->recordDecision($conversation, true, 'digest', card: ['tool' => 'update_page_metadata', 'table' => 'pages', 'uid' => 10005, 'fields' => ['description']]));
        $service->processConversation($conversation);

        self::assertSame(['Prüfe die Seite', 'Übernommen.', 'Weiter mit den Alternativtexten?'], $this->contents($conversation));
        // The chat renders an outcome line before the message at its index.
        $outcomes = $conversation->getCardOutcomes();
        self::assertCount(1, $outcomes);
        self::assertSame(1, $outcomes[0]['after']);
    }

    /**
     * A failed turn is retried over the transcript; text from it would stand
     * next to the retry's own.
     */
    #[Test]
    public function aFailedRunAddsNoText(): void
    {
        $conversation = $this->conversation();
        $result       = new AgentRunResult(AgentRunOutcome::FAILED, self::RUN, [
            $this->round(1, '**Prüfe „Batch-Test“ …**', 'read_records'),
        ]);

        $this->service($result)->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertSame(['Prüfe die Seite'], $this->contents($conversation));
    }

    /**
     * On later turns the model reads one assistant message per turn: the text
     * beside a tool call joined with the answer after it, or alone where no
     * assistant message follows. The mark itself does not go to the wire.
     */
    #[Test]
    public function theModelReadsTheTextJoinedWithTheAssistantMessageAfterIt(): void
    {
        $conversation = $this->conversation();
        $conversation->appendTextBesideToolCall(self::OVERVIEW);
        $conversation->appendMessage(MessageRole::Assistant, 'Sollen wir beginnen?');
        $conversation->appendMessage(MessageRole::User, 'Ja');
        $conversation->appendTextBesideToolCall(self::EXPLANATION);
        $conversation->appendMessage(MessageRole::User, 'Nein, anders');

        $this->service($this->completed('Gut.'))->processConversation($conversation);

        self::assertNotNull($this->capturedRequest);
        $wire = array_values(array_filter(
            $this->capturedRequest->messages,
            static fn (mixed $message): bool => !$message instanceof ChatMessage,
        ));
        self::assertSame(
            [
                ['user', 'Prüfe die Seite'],
                ['assistant', self::OVERVIEW . "\n\nSollen wir beginnen?"],
                ['user', 'Ja'],
                ['assistant', self::EXPLANATION],
                ['user', 'Nein, anders'],
            ],
            array_map(static fn (array $message): array => [$message['role'], $message['content']], $wire),
        );
        foreach ($wire as $message) {
            self::assertArrayNotHasKey(Conversation::KEY_BESIDE_TOOL_CALL, $message);
        }
    }

    /**
     * Text at the very end of the transcript, with nothing after it, still
     * reaches the model.
     */
    #[Test]
    public function theModelReadsTextThatEndsTheTranscript(): void
    {
        $conversation = $this->conversation();
        $conversation->appendTextBesideToolCall(self::EXPLANATION);

        $this->service($this->completed('Gut.'))->processConversation($conversation);

        self::assertNotNull($this->capturedRequest);
        $messages = $this->capturedRequest->messages;
        self::assertSame(['role' => 'assistant', 'content' => self::EXPLANATION], end($messages));
    }
}
