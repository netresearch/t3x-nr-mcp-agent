<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\WriteCompleteness;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
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
use Netresearch\NrMcpAgent\Enum\DenyReason;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use Netresearch\NrMcpAgent\Service\RunActivityRecorder;
use Netresearch\NrMcpAgent\Service\RunRecord;
use Netresearch\NrMcpAgent\Service\RunRecordNotes;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * A later turn reads what earlier runs did from the chat's record (ADR-030).
 *
 * A turn is a new run, and a new run gets only the transcript's text. Asked
 * "was ist hier passiert?" three days after an SEO tour that saved six
 * fields, the model saw its own proposals and nothing that said they were
 * applied, and answered that nothing had happened.
 */
#[CoversClass(ChatService::class)]
#[CoversClass(RunRecord::class)]
#[CoversClass(RunRecordNotes::class)]
#[CoversClass(Conversation::class)]
final class ChatRunRecordTest extends TestCase
{
    private const SECRET = 'geheimer-Wert-4711';

    private ?AgentRunRequest $capturedRequest = null;

    private function service(AgentRunResult|RuntimeException $runAnswer, AgentRunResult|RuntimeException|null $approveAnswer = null): ChatService
    {
        $answer = static function (AgentRunResult|RuntimeException $answer): AgentRunResult {
            if ($answer instanceof RuntimeException) {
                throw $answer;
            }

            return $answer;
        };
        $agentRuntime = $this->createMock(AgentRuntimeInterface::class);
        $agentRuntime->method('run')->willReturnCallback(function (AgentRunRequest $request) use ($runAnswer, $answer): AgentRunResult {
            $this->capturedRequest = $request;

            return $answer($runAnswer);
        });
        $agentRuntime->method('approve')->willReturnCallback(static fn (): AgentRunResult => $answer($approveAnswer ?? new RuntimeException('no approval expected')));

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
     * @param list<RunStep> $steps
     */
    private function completed(string $text, array $steps = [], AgentRunOutcome $outcome = AgentRunOutcome::COMPLETED): AgentRunResult
    {
        return new AgentRunResult($outcome, 'run-1', $steps, new ToolLoopResult($text, [], 1, false, new UsageStatistics(1, 1, 2)));
    }

    /**
     * The transcript and card outcomes of the demo conversation, copied from
     * the chat API (Tests/Fixtures/conversation-tour-then-question.json).
     */
    private function tourThenQuestion(): Conversation
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/conversation-tour-then-question.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        $row = [
            'messages'      => json_encode($fixture['messages'], JSON_THROW_ON_ERROR),
            'message_count' => count($fixture['messages']),
            'card_outcomes' => json_encode($fixture['cardOutcomes'], JSON_THROW_ON_ERROR),
            'be_user'       => 1,
        ];

        return Conversation::fromRow($row);
    }

    /**
     * The wire messages after the system prompt, as role and text.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function wire(): array
    {
        self::assertNotNull($this->capturedRequest);
        $wire = [];
        foreach ($this->capturedRequest->messages as $message) {
            if ($message instanceof ChatMessage) {
                continue;
            }

            self::assertIsArray($message);
            $wire[] = [(string) $message['role'], is_string($message['content']) ? $message['content'] : json_encode($message['content'], JSON_THROW_ON_ERROR)];
        }

        return $wire;
    }

    private function wireText(): string
    {
        return implode("\n", array_map(static fn (array $m): string => $m[1], $this->wire()));
    }

    #[Test]
    public function aQuestionAfterAFinishedTourReadsTheSixSavedFields(): void
    {
        $conversation = $this->tourThenQuestion();

        $this->service($this->completed('Sechs Felder wurden gespeichert.'))->processConversation($conversation);

        $wire = $this->wire();
        // One message per turn as before: the record joins the turn it belongs to.
        self::assertSame(['user', 'assistant', 'user', 'assistant', 'user'], array_column($wire, 0));
        self::assertSame('was ist hier passiert?', $wire[4][1]);
        foreach (['seo_title', 'og_title', 'og_description', 'twitter_title', 'twitter_description'] as $field) {
            self::assertStringContainsString(
                'proposal update_page_metadata on pages:157 (fields: ' . $field . ') — approved by the editor and saved.',
                $wire[3][1],
            );
        }

        self::assertStringContainsString('proposal set_page_social_image on pages:157 (fields: og_image) — approved by the editor and saved.', $wire[3][1]);
        self::assertSame(6, substr_count($wire[3][1], 'approved by the editor and saved'));
        self::assertStringContainsString('written by the chat from the run, not by the assistant', $wire[3][1]);
        // Never stored: the transcript keeps what the model wrote.
        self::assertStringNotContainsString('[Chat record', (string) json_encode($conversation->getDecodedMessages()));
    }

    #[Test]
    public function aTurnInWhichNothingRanIsRecordedAsSuch(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hallo');

        $service = $this->service($this->completed('Hallo! Wie kann ich helfen?'));
        $service->processConversation($conversation);
        self::assertStringNotContainsString('[Chat record', $this->wireText(), 'a conversation without runs gets no invented record');

        $conversation->appendMessage(MessageRole::User, 'Was hast du geändert?');
        $service->processConversation($conversation);

        $wire = $this->wire();
        self::assertSame(['user', 'assistant', 'user'], array_column($wire, 0));
        self::assertStringStartsWith(
            '[Chat record — written by the chat from the run, not by the assistant: no tool ran and nothing was written in this step.]',
            $wire[1][1],
        );
        self::assertStringEndsWith('Hallo! Wie kann ich helfen?', $wire[1][1]);
    }

    #[Test]
    public function aDeclinedProposalIsRecordedAsNotSaved(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Titel verbessern');
        $conversation->appendMessage(MessageRole::Assistant, 'Ich schlage einen neuen Titel vor.');
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid('run-1');

        $denied = $this->completed('Gut, der Titel bleibt.', [
            new RunStep(kind: RunStep::KIND_TOOL, round: 0, durationMs: 1.0, toolName: 'update_page_metadata', toolResult: 'Error: approval_denied', toolIsError: true),
        ]);
        $service = $this->service($this->completed('Nichts gespeichert.'), $denied);
        self::assertTrue($service->recordDecision($conversation, false, 'digest'));
        $service->processConversation($conversation);

        $conversation->appendMessage(MessageRole::User, 'Was ist passiert?');
        $service->processConversation($conversation);

        $text = $this->wireText();
        self::assertStringContainsString('the editor declined the waiting proposal, so it was NOT saved', $text);
        self::assertStringContainsString('nothing was written in this step', $text);
        self::assertStringNotContainsString('saved: ', $text);
    }

    #[Test]
    public function aKeptProposalIsRecordedAsNotSaved(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Seite prüfen');
        $conversation->appendMessage(MessageRole::Assistant, 'Ich schlage eine Beschreibung vor.');
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid('run-1');

        $service = $this->service($this->completed('Nichts.'), $this->completed('Nächster Punkt.'));
        $service->recordDecision($conversation, false, 'digest', DenyReason::Keep, 'Behalten', ['tool' => 'update_page_metadata', 'table' => 'pages', 'uid' => 3, 'fields' => ['description']]);
        $service->processConversation($conversation);

        $conversation->appendMessage(MessageRole::User, 'Was wurde gespeichert?');
        $service->processConversation($conversation);

        $text = $this->wireText();
        self::assertStringContainsString('proposal update_page_metadata on pages:3 (fields: description) — the editor kept the current value, NOT saved.', $text);
        // The card outcome says it; the record of the segment does not repeat the decision.
        self::assertStringNotContainsString('the editor kept the current value, so', $text);
    }

    #[Test]
    public function aRunThatFailedMidwayIsRecordedAsPartial(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Zwei Elemente anlegen');

        $failed = new AgentRunResult(AgentRunOutcome::FAILED, 'run-1', [
            new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 1.0, toolName: 'create_content_element_draft', toolIsError: false),
            new RunStep(kind: RunStep::KIND_WRITE, round: 1, durationMs: 1.0, writeTarget: new RecordReference('tt_content', 41), writeCompleteness: WriteCompleteness::COMPLETE),
            new RunStep(kind: RunStep::KIND_TOOL, round: 2, durationMs: 1.0, toolName: 'create_content_element_draft', toolIsError: true),
        ]);
        $service = $this->service($failed);
        $service->processConversation($conversation);
        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());

        $conversation->appendMessage(MessageRole::User, 'Was ist passiert?');
        $service->processConversation($conversation);

        $wire = $this->wire();
        self::assertSame(['user', 'assistant', 'user'], array_column($wire, 0), 'a run without an answer gets its record as the assistant turn between the two messages');
        self::assertSame(
            '[Chat record — written by the chat from the run, not by the assistant: tools run: create_content_element_draft ×2 (1 failed); saved: tt_content:41; the run ended as "failed" before it finished; only what is listed here happened.]',
            $wire[1][1],
        );
    }

    /** A write nr-llm reports as partial, or as not confirmed, is never called saved. */
    #[Test]
    public function aPartialOrUnconfirmedWriteIsNotCalledSaved(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Seite anpassen');

        $this->service($this->completed('Fertig.', [
            new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 1.0, toolName: 'update_page_metadata', toolIsError: false),
            new RunStep(kind: RunStep::KIND_WRITE, round: 1, durationMs: 1.0, writeTarget: new RecordReference('pages', 7), writeCompleteness: WriteCompleteness::PARTIAL),
            new RunStep(kind: RunStep::KIND_WRITE, round: 1, durationMs: 1.0, writeTarget: new RecordReference('pages', 8)),
        ]))->processConversation($conversation);

        self::assertSame(
            '[Chat record — written by the chat from the run, not by the assistant: tools run: update_page_metadata ×1; only partly saved: pages:7; written, completeness not confirmed: pages:8.]',
            RunRecordNotes::of($conversation)[0]['text'] ?? '',
        );
    }

    #[Test]
    public function aTurnThatFailedBeforeItsStepsCameBackSaysItIsNotKnown(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Titel ändern');

        $service = $this->service(new RuntimeException('provider down'));
        $service->processConversation($conversation);
        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertSame('unknown', $conversation->getRunRecords()[0]['outcome'] ?? null);
        self::assertStringContainsString('what it did, if anything, is not known', RunRecordNotes::of($conversation)[0]['text'] ?? '');
    }

    #[Test]
    public function noStoredValueReachesTheRecord(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Titel setzen');

        $result = $this->completed('Gesetzt.', [
            new RunStep(kind: RunStep::KIND_LLM, round: 1, durationMs: 1.0, requestedToolCalls: [['id' => 'c1', 'name' => 'update_page_metadata', 'arguments' => ['seo_title' => self::SECRET]]]),
            new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 1.0, toolName: 'update_page_metadata', toolArguments: ['seo_title' => self::SECRET], toolResult: 'Saved ' . self::SECRET, toolIsError: false),
            new RunStep(kind: RunStep::KIND_WRITE, round: 1, durationMs: 1.0, writeTarget: new RecordReference('pages', 5), writeCompleteness: WriteCompleteness::COMPLETE),
        ]);
        $service = $this->service($result);
        $service->processConversation($conversation);

        self::assertStringNotContainsString(self::SECRET, (string) json_encode($conversation->getRunRecords()));
        $conversation->appendMessage(MessageRole::User, 'Und?');
        $service->processConversation($conversation);

        $text = $this->wireText();
        self::assertStringContainsString('tools run: update_page_metadata ×1; saved: pages:5.]', $text);
        // The answer itself is the model's text and carries no secret here.
        self::assertStringNotContainsString(self::SECRET, $text);
    }

    #[Test]
    public function aProposalWaitingForApprovalIsRecordedAsNotSavedYet(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Titel verbessern');

        $waiting = new AgentRunResult(AgentRunOutcome::AWAITING_APPROVAL, 'run-1', [
            new RunStep(kind: RunStep::KIND_LLM, round: 1, durationMs: 1.0, requestedToolCalls: [['id' => 'c1', 'name' => 'update_page_metadata', 'arguments' => []]]),
        ]);
        $this->service($waiting)->processConversation($conversation);

        self::assertSame(
            "[Chat record — written by the chat from the run, not by the assistant: no tool ran and nothing was written in this step; proposed update_page_metadata, waiting for the editor's decision — not saved yet.]",
            RunRecordNotes::of($conversation)[0]['text'] ?? '',
        );
    }

    #[Test]
    public function olderEntriesBeyondTheBudgetAreSaidToExist(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        for ($i = 0; $i < RunRecordNotes::MAX_NOTES + 5; ++$i) {
            $conversation->appendMessage(MessageRole::User, 'Frage ' . $i);
            $conversation->appendRunRecord(RunRecord::unknown());
            $conversation->appendMessage(MessageRole::Assistant, 'Antwort ' . $i);
        }

        $notes = RunRecordNotes::of($conversation);
        $kept  = count($notes);
        self::assertLessThanOrEqual(RunRecordNotes::MAX_NOTES, $kept);
        self::assertLessThanOrEqual(RunRecordNotes::MAX_CHARS + 200, mb_strlen(implode('', array_column($notes, 'text'))));
        $omitted = RunRecordNotes::MAX_NOTES + 5 - $kept;
        self::assertGreaterThanOrEqual(5, $omitted);
        self::assertStringContainsString($omitted . ' earlier entries of this conversation exist but are not shown here', $notes[0]['text']);
        self::assertSame(2 * $omitted + 1, $notes[0]['at'], 'the newest notes are kept, the oldest left out');
        self::assertSame(2 * (RunRecordNotes::MAX_NOTES + 4) + 1, $notes[$kept - 1]['at']);
    }
}
