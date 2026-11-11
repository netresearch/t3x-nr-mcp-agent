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
use Netresearch\NrMcpAgent\Service\GuardrailHintCapture;
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use Netresearch\NrMcpAgent\Service\RunActivityRecorder;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use Netresearch\NrMcpAgent\Tests\Unit\Service\Fixtures\StorableRefusal;
use Netresearch\NrMcpAgent\Tests\Unit\Service\Fixtures\StubHint;
use Netresearch\NrMcpAgent\Tests\Unit\Service\Fixtures\StubHintCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The hints of nr-llm's rule-set judge in the chat (nr-llm ADR-288): what a
 * run call reported goes into the transcript as a line after what that call
 * added, for the reader only; a refused write is stored without its passage.
 */
#[CoversClass(ChatService::class)]
final class ChatRuleHintsTest extends TestCase
{
    private const RUN = 'c0ffee00-0000-4000-8000-000000000288';

    private const VIOLATION = 'This passage may break the rule "No superlatives" (snippet "Legal"): "the best airfryer ever"';

    private const UNAVAILABLE = 'This text was not checked against the rule set, because the check gave no usable answer. Read it against the rules yourself.';

    private ?AgentRunRequest $capturedRequest = null;

    protected function setUp(): void
    {
        StubHintCollector::$queue    = [];
        StubHintCollector::$captures = 0;
    }

    /**
     * @param list<AgentRunResult|Throwable> $runs      what run() answers or throws, in order
     * @param string                         $collector the collector class the capture resolves
     */
    private function service(array $runs, AgentRunResult|Throwable|null $approved = null, AgentRunResult|Throwable|null $answered = null, string $collector = StubHintCollector::class): ChatService
    {
        $answer  = static fn (AgentRunResult|Throwable|null $next, string $what): AgentRunResult => $next instanceof AgentRunResult ? $next : throw $next ?? new RuntimeException('no ' . $what . ' expected');
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->method('run')->willReturnCallback(function (AgentRunRequest $request) use (&$runs, $answer): AgentRunResult {
            $this->capturedRequest = $request;

            return $answer(array_shift($runs), 'run');
        });
        $runtime->method('approve')->willReturnCallback(static fn (): AgentRunResult => $answer($approved, 'approval'));
        $runtime->method('submitInput')->willReturnCallback(static fn (): AgentRunResult => $answer($answered, 'answer'));

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
            $runtime,
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
            hintCapture: new GuardrailHintCapture($collector),
        );
    }

    private function completed(string $text): AgentRunResult
    {
        return new AgentRunResult(
            AgentRunOutcome::COMPLETED,
            self::RUN,
            [],
            new ToolLoopResult($text, [], 1, false, new UsageStatistics(1, 1, 2), AgentRunTerminationReason::COMPLETED),
        );
    }

    private function conversation(): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Schreib einen Teaser');

        return $conversation;
    }

    /**
     * @return list<StubHint>
     */
    private function reported(): array
    {
        return [
            new StubHint('violation', self::VIOLATION, rule: 'No superlatives', passage: 'the best airfryer ever'),
            new StubHint('unavailable', self::UNAVAILABLE),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(Conversation $conversation): array
    {
        return array_values(array_filter(
            $conversation->getDecodedMessages(),
            static fn (array $message): bool => ($message['notice'] ?? null) === ChatService::NOTICE_RULE_HINTS,
        ));
    }

    /** The hints of a turn: a line after the answer, with kind and message of each and nothing else. */
    #[Test]
    public function theHintsOfATurnFollowItsAnswer(): void
    {
        StubHintCollector::$queue = [$this->reported()];
        $conversation             = $this->conversation();

        $this->service([$this->completed('Der Teaser ist vorgeschlagen.')])->processConversation($conversation);

        $messages = $conversation->getDecodedMessages();
        self::assertCount(3, $messages);
        self::assertSame('Der Teaser ist vorgeschlagen.', $messages[1]['content']);
        self::assertSame(MessageRole::Assistant->value, $messages[2]['role']);
        self::assertSame(ChatService::NOTICE_RULE_HINTS, $messages[2]['notice'] ?? null);
        self::assertSame(
            ['hints' => [
                ['kind' => 'violation', 'message' => self::VIOLATION],
                ['kind' => 'unavailable', 'message' => self::UNAVAILABLE],
            ]],
            $messages[2]['data'] ?? null,
        );
        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame(1, StubHintCollector::$captures);
    }

    /** A proposal without text beside it still gets the line, above the card. */
    #[Test]
    public function aProposalWithoutTextGetsTheLine(): void
    {
        StubHintCollector::$queue = [$this->reported()];
        $conversation             = $this->conversation();

        $this->service([new AgentRunResult(AgentRunOutcome::AWAITING_APPROVAL, self::RUN, [])])->processConversation($conversation);

        self::assertCount(1, $this->lines($conversation));
        self::assertSame(ConversationStatus::AwaitingApproval, $conversation->getStatus());
    }

    /** A run that asks the editor something gets the line too. */
    #[Test]
    public function aRunThatAsksGetsTheLine(): void
    {
        StubHintCollector::$queue = [$this->reported()];
        $conversation             = $this->conversation();

        $this->service([new AgentRunResult(AgentRunOutcome::AWAITING_INPUT, self::RUN, [])])->processConversation($conversation);

        self::assertCount(1, $this->lines($conversation));
        self::assertSame(ConversationStatus::AwaitingInput, $conversation->getStatus());
    }

    /** A turn without hints adds no line. */
    #[Test]
    public function aTurnWithoutHintsAddsNoLine(): void
    {
        $conversation = $this->conversation();

        $this->service([$this->completed('Fertig.')])->processConversation($conversation);

        self::assertSame([], $this->lines($conversation));
        self::assertCount(2, $conversation->getDecodedMessages());
    }

    /** Without nr-llm's collector (a release before ADR-288) the turn runs as before. */
    #[Test]
    public function withoutTheCollectorTheTurnRunsAsBefore(): void
    {
        StubHintCollector::$queue = [$this->reported()];
        $conversation             = $this->conversation();

        $this->service([$this->completed('Fertig.')], collector: 'Netresearch\\NrLlm\\Service\\Guardrail\\NoSuchCollector')->processConversation($conversation);

        self::assertSame([], $this->lines($conversation));
        self::assertSame('Fertig.', $conversation->getDecodedMessages()[1]['content']);
        self::assertSame(0, StubHintCollector::$captures);
    }

    /** The continuation after an approval reports in its own call: the line follows its answer. */
    #[Test]
    public function theHintsOfAnApprovalFollowItsAnswer(): void
    {
        StubHintCollector::$queue = [[new StubHint('violation', self::VIOLATION)]];
        $conversation             = $this->conversation();
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid(self::RUN);

        $service = $this->service([], approved: $this->completed('Gespeichert.'));

        $service->recordDecision($conversation, true, 'digest');
        $service->processConversation($conversation);

        $messages = $conversation->getDecodedMessages();
        $last     = $messages[count($messages) - 1];
        self::assertSame('Gespeichert.', $messages[count($messages) - 2]['content']);
        self::assertSame(ChatService::NOTICE_RULE_HINTS, $last['notice'] ?? null);
        self::assertSame(['hints' => [['kind' => 'violation', 'message' => self::VIOLATION]]], $last['data'] ?? null);
    }

    /** So does the continuation after an answer to a question. */
    #[Test]
    public function theHintsOfAnAnswerFollowItsContinuation(): void
    {
        StubHintCollector::$queue = [[new StubHint('unavailable', self::UNAVAILABLE)]];
        $conversation             = $this->conversation();
        $conversation->setStatus(ConversationStatus::AwaitingInput);
        $conversation->setApprovalRunUuid(self::RUN);

        $service = $this->service([], answered: new AgentRunResult(AgentRunOutcome::AWAITING_APPROVAL, self::RUN, []));

        self::assertTrue($service->recordInput($conversation, ['start' => 'teaser'], 'digest', 'Teaser'));
        $service->processConversation($conversation);

        $lines = $this->lines($conversation);
        self::assertCount(1, $lines);
        self::assertSame(['hints' => [['kind' => 'unavailable', 'message' => self::UNAVAILABLE]]], $lines[0]['data'] ?? null);
        self::assertSame(ConversationStatus::AwaitingApproval, $conversation->getStatus());
    }

    /** The line is the reader's: the next turn does not send it to the model. */
    #[Test]
    public function theModelIsNotSentTheLine(): void
    {
        $conversation = $this->conversation();
        $conversation->appendMessage(MessageRole::Assistant, 'Vorschlag steht.');
        $conversation->appendMessage(MessageRole::Assistant, '[The rule-set check reported on the change this run proposed.]', ChatService::NOTICE_RULE_HINTS, data: ['hints' => [['kind' => 'violation', 'message' => self::VIOLATION]]]);
        $conversation->appendMessage(MessageRole::User, 'Weiter');

        $this->service([$this->completed('Gut.')])->processConversation($conversation);

        self::assertNotNull($this->capturedRequest);
        $wire = array_values(array_filter(
            $this->capturedRequest->messages,
            static fn (mixed $message): bool => !$message instanceof ChatMessage,
        ));
        self::assertSame(
            [
                ['user', 'Schreib einen Teaser'],
                ['assistant', 'Vorschlag steht.'],
                ['user', 'Weiter'],
            ],
            array_map(static fn (array $message): array => [$message['role'], $message['content']], $wire),
        );
    }

    /**
     * The line sits where the run record of its proposal points (ADR-030):
     * the next turn's transcript is the same as without the line, notes of
     * the earlier runs included.
     *
     * @return list<array{0: mixed, 1: mixed}>
     */
    private function wireAfterAProposalAndItsApproval(bool $withHints): array
    {
        StubHintCollector::$queue = $withHints ? [$this->reported(), [], []] : [];
        $conversation             = $this->conversation();
        $service                  = $this->service(
            [new AgentRunResult(AgentRunOutcome::AWAITING_APPROVAL, self::RUN, []), $this->completed('Gut.')],
            approved: $this->completed('Gespeichert.'),
        );

        $service->processConversation($conversation);
        $service->recordDecision($conversation, true, 'digest');
        $service->processConversation($conversation);

        $conversation->appendMessage(MessageRole::User, 'Weiter');
        $service->processConversation($conversation);

        self::assertCount($withHints ? 1 : 0, $this->lines($conversation));
        self::assertNotNull($this->capturedRequest);
        $wire = array_values(array_filter(
            $this->capturedRequest->messages,
            static fn (mixed $message): bool => !$message instanceof ChatMessage,
        ));

        return array_map(static fn (array $message): array => [$message['role'] ?? null, $message['content'] ?? null], $wire);
    }

    #[Test]
    public function theLineLeavesTheRunRecordsOfLaterTurnsInPlace(): void
    {
        $without = $this->wireAfterAProposalAndItsApproval(false);
        $with    = $this->wireAfterAProposalAndItsApproval(true);

        self::assertSame($without, $with);
        self::assertStringContainsString('[', implode("\n", array_map(static fn (array $pair): string => is_string($pair[1]) ? $pair[1] : '', $without)), 'the transcript carries run notes');
    }

    /** A refused write is stored with the rule and without the passage of the refused text. */
    #[Test]
    public function aBlockStoresTheRefusalWithoutThePassage(): void
    {
        $conversation = $this->conversation();
        $refusal      = new StorableRefusal(
            'This change was not proposed. Its text breaks the rule set: rule "No superlatives" (snippet "Legal"): "the best airfryer ever"',
            'The rule-set judge found these rules broken: 1 "No superlatives" (snippet "Legal")',
        );

        $this->service([new AgentRunResult(AgentRunOutcome::GUARDRAIL_BLOCKED, self::RUN, [], guardrailClass: 'ruleSetJudge', error: $refusal)])->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertSame('The rule-set judge found these rules broken: 1 "No superlatives" (snippet "Legal")', $conversation->getErrorMessage());
        self::assertStringNotContainsString('airfryer', $conversation->getErrorMessage());
    }

    /** A refusal from an nr-llm without the storable form keeps its message, as before. */
    #[Test]
    public function aBlockWithoutAStorableFormKeepsItsMessage(): void
    {
        $conversation = $this->conversation();

        $this->service([new AgentRunResult(AgentRunOutcome::GUARDRAIL_BLOCKED, self::RUN, [], guardrailClass: 'terms', error: new RuntimeException('Blocked by the term list.'))])->processConversation($conversation);

        self::assertSame('Blocked by the term list.', $conversation->getErrorMessage());
    }

    private function refusal(): StorableRefusal
    {
        return new StorableRefusal('Refused: "the best airfryer ever"', 'Refused by rule 1 "No superlatives"');
    }

    /** A refusal thrown out of a turn is stored in its storable form. */
    #[Test]
    public function aTurnThatThrowsARefusalStoresItWithoutThePassage(): void
    {
        $conversation = $this->conversation();

        $this->service([$this->refusal()])->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertSame('Refused by rule 1 "No superlatives"', $conversation->getErrorMessage());
    }

    /** The same for the continuation after an approval. */
    #[Test]
    public function anApprovalThatThrowsARefusalStoresItWithoutThePassage(): void
    {
        $conversation = $this->conversation();
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid(self::RUN);

        $service = $this->service([], approved: $this->refusal());

        $service->recordDecision($conversation, true, 'digest');
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertSame('Refused by rule 1 "No superlatives"', $conversation->getErrorMessage());
    }

    /** And for the continuation after an answer. */
    #[Test]
    public function anAnswerThatThrowsARefusalStoresItWithoutThePassage(): void
    {
        $conversation = $this->conversation();
        $conversation->setStatus(ConversationStatus::AwaitingInput);
        $conversation->setApprovalRunUuid(self::RUN);

        $service = $this->service([], answered: $this->refusal());

        self::assertTrue($service->recordInput($conversation, ['start' => 'teaser'], 'digest', 'Teaser'));
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertSame('Refused by rule 1 "No superlatives"', $conversation->getErrorMessage());
    }
}
