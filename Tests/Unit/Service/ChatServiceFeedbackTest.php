<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
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
use Netresearch\NrMcpAgent\Service\ChatApprovalInterface;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use Netresearch\NrMcpAgent\Service\UnavailableToolsReaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The chat's side of NEXT-167 (ADR-017), each case taken from a conversation
 * on the Netresearch demo installation.
 */
#[CoversClass(ChatService::class)]
final class ChatServiceFeedbackTest extends TestCase
{
    /**
     * Demo conversation 92, U29 → run 112: the answer the model gave in a run
     * that called no tool at all. Both uids are made up.
     */
    private const FALSE_SUCCESS = "Erledigt:\n\n- Seite bearbeitet: `pages:10041`\n"
        . "- Seitentitel in Englisch geändert auf: `Corporate Partnerships and Projects`\n"
        . "- Genau ein neues Text-Element als Entwurf erstellt:\n  - Inhaltselement: `tt_content:10162`\n"
        . "  - Sprache: Englisch (`sys_language_uid=0`)\n  - Status: verborgen, zur Freigabe\n\nIch halte hier zur Freigabe an.";

    private ?AgentRunRequest $capturedRequest = null;

    /**
     * @param list<array{name: string, reason: string}>|null $unavailable null = no reader wired
     * @param list<AgentRunEvent>                           $events
     */
    private function createChatService(
        ?AgentRunResult $result = null,
        ?TaskRepository $taskRepository = null,
        ?array $unavailable = null,
        ?AgentRun $run = null,
        array $events = [],
    ): ChatService {
        $agentRuntime = $this->createMock(AgentRuntimeInterface::class);
        $agentRuntime->method('run')->willReturnCallback(
            function (AgentRunRequest $request) use ($result): AgentRunResult {
                $this->capturedRequest = $request;

                return $result ?? $this->completed('Hallo.');
            },
        );
        $agentRuntime->method('events')->willReturn($events);

        if ($taskRepository === null) {
            $configuration = $this->createMock(LlmConfiguration::class);
            $configuration->method('getSystemPrompt')->willReturn('');
            $configuration->method('getLlmModel')->willReturn($this->createMock(LlmModel::class));

            $task = $this->createMock(Task::class);
            $task->method('getConfiguration')->willReturn($configuration);
            $task->method('getPromptTemplate')->willReturn('');

            $taskRepository = $this->createMock(TaskRepository::class);
            $taskRepository->method('findByUid')->willReturn($task);
        }

        $adapterRegistry = $this->createMock(ProviderAdapterRegistryInterface::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn($this->createMock(ProviderInterface::class));

        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);

        $runRepository = $this->createMock(AgentRunRepositoryInterface::class);
        $runRepository->method('findByUuid')->willReturn($run);

        $reader = null;
        if ($unavailable !== null) {
            $reader = $this->createMock(UnavailableToolsReaderInterface::class);
            $reader->method('read')->willReturn($unavailable);
        }

        return new ChatService(
            $this->createMock(ConversationRepository::class),
            $config,
            $agentRuntime,
            $this->createMock(PendingApprovalReaderInterface::class),
            $runRepository,
            $taskRepository,
            $adapterRegistry,
            $this->createMock(ResourceFactory::class),
            $this->createMock(SiteFinder::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $reader,
        );
    }

    /**
     * @param list<RunStep> $steps
     */
    private function completed(string $text, array $steps = []): AgentRunResult
    {
        return new AgentRunResult(
            AgentRunOutcome::COMPLETED,
            'run-uuid',
            $steps,
            new ToolLoopResult($text, [], 1, false, new UsageStatistics(10, 20, 30)),
        );
    }

    private function conversation(string $userMessage): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(2);
        $conversation->appendMessage(MessageRole::User, $userMessage);

        return $conversation;
    }

    /**
     * @return array<string, mixed>
     */
    private function lastMessage(Conversation $conversation): array
    {
        $messages = $conversation->getDecodedMessages();
        $last = end($messages);
        self::assertIsArray($last);

        return $last;
    }

    private function systemPrompt(): string
    {
        self::assertNotNull($this->capturedRequest);
        $system = $this->capturedRequest->messages[0] ?? null;
        self::assertInstanceOf(ChatMessage::class, $system);

        return $system->content;
    }

    // ---- 1. a claimed change the run did not write ----------------------------

    #[Test]
    public function aClaimedChangeWithoutAWriteInTheRunCarriesTheNothingSavedNotice(): void
    {
        $conversation = $this->conversation('10041');

        $this->createChatService($this->completed(self::FALSE_SUCCESS))->processConversation($conversation);

        $answer = $this->lastMessage($conversation);
        self::assertSame(self::FALSE_SUCCESS, $answer['content']);
        self::assertSame(ChatService::NOTICE_NOTHING_SAVED, $answer['notice'] ?? null);
    }

    #[Test]
    public function aClaimedChangeTheRunDidWriteCarriesNoNotice(): void
    {
        $conversation = $this->conversation('10041');
        $write = new RunStep(
            kind: RunStep::KIND_WRITE,
            round: 1,
            durationMs: 3.2,
            toolName: 'create_content_element_draft',
            writeTarget: new RecordReference('tt_content', 10162),
        );

        $this->createChatService($this->completed(self::FALSE_SUCCESS, [$write]))->processConversation($conversation);

        self::assertArrayNotHasKey('notice', $this->lastMessage($conversation));
    }

    #[Test]
    public function anAnswerThatClaimsNoChangeCarriesNoNotice(): void
    {
        $conversation = $this->conversation('Für die Seite „Prof. Dr. Erika Mustermann" unter 10011 den Titel setzen.');

        // Demo conversation 92, U35: a question back, in a run without a write.
        $this->createChatService($this->completed(
            "Ich habe unter `10011` zwei Seiten mit diesem Titel gefunden:\n\n- `pages:10057`\n- `pages:10058`\n\nWelche davon soll ich bearbeiten?",
        ))->processConversation($conversation);

        self::assertArrayNotHasKey('notice', $this->lastMessage($conversation));
    }

    #[Test]
    public function theIdentityPromptForbidsClaimingAChangeWithoutAWriteResult(): void
    {
        $this->createChatService()->processConversation($this->conversation('Hallo'));

        $system = $this->systemPrompt();
        self::assertStringContainsString('ONLY when a writing tool returned a successful result', $system);
        self::assertStringContainsString('say plainly that nothing has been saved yet', $system);
        self::assertStringContainsString('"decided_by: run_owner"', $system);
    }

    // ---- 3. configuration failures carry a code ------------------------------

    /**
     * Demo conversation 49: the provider had no API key, and the chat showed the
     * provider's own sentence to whoever was reading.
     */
    #[Test]
    public function aProviderWithoutAnApiKeyIsStoredWithItsCode(): void
    {
        $conversation = $this->conversation('what is the last LLM eror about?');
        $failure = new AgentRunResult(
            AgentRunOutcome::FAILED,
            'run-uuid',
            [],
            error: new ProviderConfigurationException('API key identifier is required for provider OpenAI', 1307337100),
        );

        $this->createChatService($failure)->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertSame('API key identifier is required for provider OpenAI', $conversation->getErrorMessage());
        self::assertSame('providerNotConfigured', $conversation->getErrorCode());
    }

    #[Test]
    public function aProviderFailureWrappedByTheRuntimeIsStillRecognised(): void
    {
        $conversation = $this->conversation('Hallo');
        $failure = new AgentRunResult(
            AgentRunOutcome::FAILED,
            'run-uuid',
            [],
            error: new RuntimeException(
                'The run failed',
                1,
                new ProviderConfigurationException('API key identifier is required for provider OpenAI', 1307337100),
            ),
        );

        $this->createChatService($failure)->processConversation($conversation);

        self::assertSame('providerNotConfigured', $conversation->getErrorCode());
    }

    #[Test]
    public function aMissingTaskIsStoredAsAChatThatIsNotSetUp(): void
    {
        $conversation = $this->conversation('Hallo');
        $taskRepository = $this->createMock(TaskRepository::class);
        $taskRepository->method('findByUid')->willReturn(null);

        $this->createChatService(taskRepository: $taskRepository)->processConversation($conversation);

        self::assertSame('chatNotConfigured', $conversation->getErrorCode());
    }

    #[Test]
    public function anyOtherFailureCarriesNoCode(): void
    {
        $conversation = $this->conversation('Hallo');
        $failure = new AgentRunResult(AgentRunOutcome::FAILED, 'run-uuid', [], error: new RuntimeException('provider exploded'));

        $this->createChatService($failure)->processConversation($conversation);

        self::assertSame('provider exploded', $conversation->getErrorMessage());
        self::assertSame('', $conversation->getErrorCode());
    }

    #[Test]
    public function aSuccessfulTurnClearsTheCodeOfAnEarlierFailure(): void
    {
        $conversation = $this->conversation('Hallo');
        $conversation->setErrorMessage('API key identifier is required for provider OpenAI', 'providerNotConfigured');

        $this->createChatService()->processConversation($conversation);

        self::assertSame('', $conversation->getErrorCode());
    }

    // ---- 4. the tools the run is not offered ---------------------------------

    /**
     * Demo conversation 104, U8: "welche tools dürfen wir NICHT nutzen?" — the
     * model saw no list of the sixteen tools the configuration held back.
     */
    #[Test]
    public function theSystemPromptNamesTheToolsTheRunIsNotOfferedWithTheirReason(): void
    {
        $this->createChatService(unavailable: [
            ['name' => 'read_source', 'reason' => 'configurationGroup'],
            ['name' => 'get_env_raw', 'reason' => 'requiresAdmin'],
            ['name' => 'create_record_draft', 'reason' => 'toolDisabled'],
            ['name' => 'fetch_logs', 'reason' => 'trustZone'],
            ['name' => 'future_tool', 'reason' => 'somethingNew'],
        ])->processConversation($this->conversation('welche tools dürfen wir NICHT nutzen?'));

        $system = $this->systemPrompt();
        self::assertStringContainsString(
            "Tools that exist in this installation but are NOT available to this user in this chat, with the reason:\n"
            . "- read_source: not part of the tool groups this chat is configured with\n"
            . "- get_env_raw: for administrators only\n"
            . "- create_record_draft: switched off by an administrator\n"
            . "- fetch_logs: withheld because this AI provider may not receive the data it returns\n"
            . '- future_tool: somethingNew',
            $system,
        );
        self::assertStringContainsString('suggest asking an administrator', $system);
    }

    #[Test]
    public function withoutUnavailableToolsThePromptHasNoSuchList(): void
    {
        $this->createChatService(unavailable: [])->processConversation($this->conversation('Hallo'));

        self::assertStringNotContainsString('NOT available to this user', $this->systemPrompt());
    }

    // ---- 7. a "go on" message on a parked conversation ---------------------------

    private function parked(): Conversation
    {
        $conversation = $this->conversation('Lege unter der Seite 10011 eine Unterseite an.');
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid('ccc12670-2f8d-4595-a89c-d81291ff5820');

        return $conversation;
    }

    private function agentRun(AgentRunStatus $status, int $beUser = 2): AgentRun
    {
        $run = (new ReflectionClass(AgentRun::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionClass($run);
        foreach (['uid' => 45, 'uuid' => 'ccc12670-2f8d-4595-a89c-d81291ff5820', 'beUser' => $beUser, 'status' => $status->value] as $name => $value) {
            $reflection->getProperty($name)->setValue($run, $value);
        }

        return $run;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(int $sequence, string $kind, array $payload = []): AgentRunEvent
    {
        return new AgentRunEvent(0, 45, $sequence, $kind, 1, 0.0, $payload, 0);
    }

    #[Test]
    public function aRunStillWaitingIsReportedAsWaiting(): void
    {
        $pending = $this->createChatService(run: $this->agentRun(AgentRunStatus::WAITING_FOR_APPROVAL))
            ->inspectPendingRun($this->parked());

        self::assertSame(['state' => ChatApprovalInterface::PENDING_RUN_WAITING, 'writes' => []], $pending);
    }

    #[Test]
    public function aRunCarriedOnElsewhereIsReportedAsBusy(): void
    {
        $pending = $this->createChatService(run: $this->agentRun(AgentRunStatus::RUNNING))
            ->inspectPendingRun($this->parked());

        self::assertSame(ChatApprovalInterface::PENDING_RUN_BUSY, $pending['state']);
    }

    /**
     * Demo conversation 79: approved in the AI Tasks module, the run finished
     * there, and the chat still showed the card. Run 45 wrote a page and an
     * element; those are what the next turn has to know about.
     */
    #[Test]
    public function aRunThatFinishedElsewhereReportsTheRecordsItWrote(): void
    {
        $pending = $this->createChatService(
            run: $this->agentRun(AgentRunStatus::COMPLETED),
            events: [
                $this->event(0, 'request'),
                $this->event(1, 'approval', ['approved' => true, 'decidedBy' => 2]),
                $this->event(2, 'tool', ['toolName' => 'create_page_draft']),
                $this->event(3, 'tool_write', ['writeTargetTable' => 'pages', 'writeTargetUid' => 10073]),
                $this->event(4, 'tool_write', ['writeTargetTable' => 'tt_content', 'writeTargetUid' => 10077]),
                $this->event(5, 'llm'),
            ],
        )->inspectPendingRun($this->parked());

        self::assertSame(
            ['state' => ChatApprovalInterface::PENDING_RUN_SETTLED, 'writes' => ['pages:10073', 'tt_content:10077']],
            $pending,
        );
    }

    #[Test]
    public function aRunOfAnotherUserIsNotReported(): void
    {
        $pending = $this->createChatService(run: $this->agentRun(AgentRunStatus::COMPLETED, beUser: 9))
            ->inspectPendingRun($this->parked());

        self::assertSame(ChatApprovalInterface::PENDING_RUN_UNKNOWN, $pending['state']);
    }

    #[Test]
    public function aConversationThatIsNotParkedIsNotInspected(): void
    {
        $conversation = $this->parked();
        $conversation->setStatus(ConversationStatus::Idle);

        $pending = $this->createChatService(run: $this->agentRun(AgentRunStatus::WAITING_FOR_APPROVAL))
            ->inspectPendingRun($conversation);

        self::assertSame(ChatApprovalInterface::PENDING_RUN_UNKNOWN, $pending['state']);
    }
}
