<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
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
 * A run nr-llm stopped at its round cap (ADR-023, amendment of 2026-10-22).
 *
 * Run 496abb77 on the demo: five rounds of reads and the overview, then the
 * final call without tools returned no text. The chat stored the empty
 * answer and showed an empty bubble, and nothing said the step had stopped.
 */
#[CoversClass(ChatService::class)]
final class ChatRoundLimitTest extends TestCase
{
    private const RUN = '496abb77-f332-43a1-9b37-34ef53be1d01';

    private const OVERVIEW = "**Probleme**\n\n1. Meta Description fehlt\n2. Kein Vorschaubild";

    private ?AgentRunRequest $capturedRequest = null;

    private function service(AgentRunResult $runAnswer): ChatService
    {
        $agentRuntime = $this->createMock(AgentRuntimeInterface::class);
        $agentRuntime->method('run')->willReturnCallback(function (AgentRunRequest $request) use ($runAnswer): AgentRunResult {
            $this->capturedRequest = $request;

            return $runAnswer;
        });

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
            $this->createMock(ConversationRepository::class),
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

    private function completed(string $text, AgentRunTerminationReason $reason, array $steps = []): AgentRunResult
    {
        return new AgentRunResult(
            AgentRunOutcome::COMPLETED,
            self::RUN,
            $steps,
            new ToolLoopResult($text, [], 5, $reason === AgentRunTerminationReason::MAX_ITERATIONS, new UsageStatistics(10, 20, 30), $reason),
        );
    }

    private function conversation(): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Diese Seite prüfen');

        return $conversation;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assistantMessages(Conversation $conversation): array
    {
        return array_values(array_filter(
            $conversation->getDecodedMessages(),
            static fn (array $message): bool => ($message['role'] ?? null) === MessageRole::Assistant->value,
        ));
    }

    /** The run of the demo: rounds with text beside the tools, then an empty final call. */
    #[Test]
    public function aRunStoppedAtTheCapWithoutTextShowsNoEmptyMessageAndSaysItStopped(): void
    {
        $conversation = $this->conversation();
        $result       = $this->completed('', AgentRunTerminationReason::MAX_ITERATIONS, [
            $this->round(1, '**Prüfe „Zwilling Airfryer“ …**', 'read_records', 'get_page_content', 'chat_list_open_points'),
            $this->round(2, '', 'read_records'),
            $this->round(3, '', 'search_fal_files'),
            $this->round(4, '', 'search_fal_files'),
            $this->round(5, self::OVERVIEW, 'chat_set_progress'),
            $this->round(6, ''),
        ]);

        $this->service($result)->processConversation($conversation);

        $assistant = $this->assistantMessages($conversation);
        foreach ($assistant as $message) {
            self::assertNotSame('', trim(is_string($message['content'] ?? null) ? $message['content'] : ''), 'no empty assistant message');
        }

        $last = end($assistant);
        self::assertIsArray($last);
        self::assertSame(ChatService::NOTICE_ROUND_LIMIT, $last['notice'] ?? null);
        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
    }

    /** An answer the cap did not cut off, but which says nothing, is no message either. */
    #[Test]
    public function aCompletedRunWithoutTextAddsNoMessage(): void
    {
        $conversation = $this->conversation();

        $this->service($this->completed(" \n", AgentRunTerminationReason::COMPLETED))->processConversation($conversation);

        self::assertSame([], $this->assistantMessages($conversation));
        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
    }

    /** The cap with an answer: the answer stays, the line follows it. */
    #[Test]
    public function aRunStoppedAtTheCapWithTextKeepsItsAnswerBeforeTheLine(): void
    {
        $conversation = $this->conversation();

        $this->service($this->completed('Die Übersicht steht oben.', AgentRunTerminationReason::MAX_ITERATIONS))->processConversation($conversation);

        $assistant = $this->assistantMessages($conversation);
        self::assertCount(2, $assistant);
        self::assertSame('Die Übersicht steht oben.', $assistant[0]['content']);
        self::assertArrayNotHasKey('notice', $assistant[0]);
        self::assertSame(ChatService::NOTICE_ROUND_LIMIT, $assistant[1]['notice'] ?? null);
    }

    /** A run that finished on its own gets no line. */
    #[Test]
    public function aRunThatFinishedGetsNoLine(): void
    {
        $conversation = $this->conversation();

        $this->service($this->completed('Fertig.', AgentRunTerminationReason::COMPLETED))->processConversation($conversation);

        foreach ($this->assistantMessages($conversation) as $message) {
            self::assertNotSame(ChatService::NOTICE_ROUND_LIMIT, $message['notice'] ?? null);
        }
    }

    /** The line is the reader's: the next turn does not send it to the model. */
    #[Test]
    public function theModelIsNotSentTheLine(): void
    {
        $conversation = $this->conversation();
        $conversation->appendTextBesideToolCall(self::OVERVIEW);
        $conversation->appendMessage(MessageRole::Assistant, '[The run stopped at its round limit before it finished.]', ChatService::NOTICE_ROUND_LIMIT);
        $conversation->appendMessage(MessageRole::User, 'Weiter');

        $this->service($this->completed('Gut.', AgentRunTerminationReason::COMPLETED))->processConversation($conversation);

        self::assertNotNull($this->capturedRequest);
        $wire = array_values(array_filter(
            $this->capturedRequest->messages,
            static fn (mixed $message): bool => !$message instanceof ChatMessage,
        ));
        self::assertSame(
            [
                ['user', 'Diese Seite prüfen'],
                ['assistant', self::OVERVIEW],
                ['user', 'Weiter'],
            ],
            array_map(static fn (array $message): array => [$message['role'], $message['content']], $wire),
        );
    }
}
