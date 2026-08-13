<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Provider\Contract\DocumentCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\VisionCapableInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\Locale;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

class ChatServiceTest extends TestCase
{
    /** The AgentRunRequest captured by the mocked AgentRuntime, for inspection. */
    private ?AgentRunRequest $capturedRequest = null;

    /** The LlmConfiguration the mocked TaskRepository resolves; asserted identity-equal. */
    private ?LlmConfiguration $configuration = null;

    private function completedResult(string $text = 'Hi there!'): AgentRunResult
    {
        return new AgentRunResult(
            AgentRunOutcome::COMPLETED,
            'run-uuid',
            [],
            new ToolLoopResult($text, [], 1, false, new UsageStatistics(10, 20, 30)),
        );
    }

    /**
     * @param array{system_prompt?: string, prompt_template?: string} $prompts
     */
    private function createChatService(
        ?AgentRunResult $result = null,
        ?ConversationRepository $repository = null,
        ?ExtensionConfiguration $config = null,
        array $prompts = [],
        ?ProviderInterface $provider = null,
        ?ResourceFactory $resourceFactory = null,
        ?SiteFinder $siteFinder = null,
        ?DocumentExtractorRegistry $registry = null,
        ?TaskRepository $taskRepository = null,
    ): ChatService {
        $repository ??= $this->createMock(ConversationRepository::class);
        if ($config === null) {
            $config = $this->createStub(ExtensionConfiguration::class);
            $config->method('getLlmTaskUid')->willReturn(1);
        }
        $resourceFactory ??= $this->createMock(ResourceFactory::class);
        $siteFinder ??= $this->createMock(SiteFinder::class);
        $registry ??= new DocumentExtractorRegistry([]);
        $provider ??= $this->createMock(ProviderInterface::class);

        $agentRuntime = $this->createMock(AgentRuntimeInterface::class);
        $agentRuntime->method('run')->willReturnCallback(
            function (AgentRunRequest $request) use ($result): AgentRunResult {
                $this->capturedRequest = $request;
                return $result ?? $this->completedResult();
            },
        );

        if ($taskRepository === null) {
            $model = $this->createMock(LlmModel::class);
            $this->configuration = $this->createMock(LlmConfiguration::class);
            $this->configuration->method('getSystemPrompt')->willReturn($prompts['system_prompt'] ?? '');
            $this->configuration->method('getLlmModel')->willReturn($model);

            $task = $this->createMock(Task::class);
            $task->method('getConfiguration')->willReturn($this->configuration);
            $task->method('getPromptTemplate')->willReturn($prompts['prompt_template'] ?? '');

            $taskRepository = $this->createMock(TaskRepository::class);
            $taskRepository->method('findByUid')->willReturn($task);
        }

        $adapterRegistry = $this->createMock(ProviderAdapterRegistryInterface::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn($provider);

        return new ChatService($repository, $config, $agentRuntime, $this->createMock(PendingApprovalReaderInterface::class), $this->createMock(AgentRunRepositoryInterface::class), $taskRepository, $adapterRegistry, $resourceFactory, $siteFinder, $registry);
    }

    /**
     * The system prompt the AgentRuntime was invoked with (messages[0]).
     */
    private function capturedSystemPrompt(): string
    {
        self::assertNotNull($this->capturedRequest);
        $system = $this->capturedRequest->messages[0] ?? null;
        self::assertInstanceOf(ChatMessage::class, $system);
        self::assertTrue($system->isSystem());
        return $system->content;
    }

    /**
     * The last message the AgentRuntime was invoked with — the user turn (array shape).
     *
     * @return array<string, mixed>
     */
    private function capturedUserMessage(): array
    {
        self::assertNotNull($this->capturedRequest);
        // Copy out of the readonly property before end() moves the array pointer.
        $messages = $this->capturedRequest->messages;
        $last = end($messages);
        self::assertIsArray($last);
        return $last;
    }

    /**
     * @param list<array{uid: int, title: string, isoCode: string}> $languageData
     */
    private function createSiteFinderWithLanguages(array $languageData): SiteFinder
    {
        $siteLanguages = [];
        foreach ($languageData as $data) {
            $locale = $this->createMock(Locale::class);
            $locale->method('getLanguageCode')->willReturn($data['isoCode']);

            $siteLanguage = $this->createMock(SiteLanguage::class);
            $siteLanguage->method('getLanguageId')->willReturn($data['uid']);
            $siteLanguage->method('getTitle')->willReturn($data['title']);
            $siteLanguage->method('getLocale')->willReturn($locale);
            $siteLanguage->method('getHreflang')->willReturn($data['isoCode']);
            $siteLanguages[] = $siteLanguage;
        }

        $site = $this->createMock(Site::class);
        $site->method('getAllLanguages')->willReturn($siteLanguages);

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([$site]);

        return $siteFinder;
    }

    // -------------------------------------------------------------------------
    // Outcome mapping
    // -------------------------------------------------------------------------

    #[Test]
    public function processConversationAppendsFinalAnswerAndSetsIdleOnCompletion(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $service = $this->createChatService($this->completedResult('Final answer.'));
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame(2, $conversation->getMessageCount());
        $messages = $conversation->getDecodedMessages();
        $assistant = end($messages);
        self::assertSame('assistant', $assistant['role']);
        self::assertSame('Final answer.', $assistant['content']);
    }

    #[Test]
    public function processConversationInvokesAgentRuntimeWithResolvedConfigurationAndBeUser(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(42);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $service = $this->createChatService();
        $service->processConversation($conversation);

        self::assertNotNull($this->capturedRequest);
        self::assertSame($this->configuration, $this->capturedRequest->configuration);
        // null allowed-tool list => offer the whole registered/enabled set.
        self::assertNull($this->capturedRequest->allowedToolNames);
        // The run carries the conversation's backend user as its actor (not a
        // service account). Without a live $GLOBALS['BE_USER'], resolveActor()
        // takes the uid-only fallback but still preserves ownership.
        self::assertSame(42, $this->capturedRequest->actor->backendUserUid);
        self::assertFalse($this->capturedRequest->actor->isServiceAccount());
    }

    #[Test]
    public function agentRunActorCarriesLiveBackendUserAdminFlagAndGroups(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(42);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 42];
        $backendUser->userGroupsUID = [1, 2];
        $backendUser->method('isAdmin')->willReturn(true);
        $GLOBALS['BE_USER'] = $backendUser;

        try {
            $service = $this->createChatService();
            $service->processConversation($conversation);
        } finally {
            unset($GLOBALS['BE_USER']);
        }

        self::assertNotNull($this->capturedRequest);
        self::assertSame(42, $this->capturedRequest->actor->backendUserUid);
        self::assertTrue($this->capturedRequest->actor->isAdmin);
        self::assertSame([1, 2], $this->capturedRequest->actor->backendGroupIds);
        self::assertFalse($this->capturedRequest->actor->isServiceAccount());
    }

    #[Test]
    public function systemMessageCarriesNetresearchIdentityAndToolSteering(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $service = $this->createChatService();
        $service->processConversation($conversation);

        $system = $this->capturedSystemPrompt();
        self::assertStringContainsString('TYPO3 Backend AI Chat by Netresearch', $system);
        self::assertStringContainsString('USE the appropriate tool', $system);
        self::assertStringContainsString('Never claim to be ChatGPT', $system);
    }

    #[Test]
    public function userMessageIsForwardedToAgentRuntime(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'What is the weather?');

        $service = $this->createChatService();
        $service->processConversation($conversation);

        $userMsg = $this->capturedUserMessage();
        self::assertSame('user', $userMsg['role']);
        self::assertSame('What is the weather?', $userMsg['content']);
    }

    #[Test]
    public function processConversationSetsProcessingStatusAtStart(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 10,
            'be_user' => 7,
            'status' => 'processing',
            'messages' => json_encode([['role' => 'user', 'content' => 'Hi']]),
            'message_count' => 1,
        ]);

        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::once())
            ->method('updateStatus')
            ->with(10, ConversationStatus::Processing, 7);

        $service = $this->createChatService(repository: $repository);
        $service->processConversation($conversation);
    }

    #[Test]
    public function processConversationSetsFailedWhenNoLlmTaskConfigured(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(0);

        $service = $this->createChatService(config: $config);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertStringContainsString('nr-llm Task', $conversation->getErrorMessage());
    }

    #[Test]
    public function processConversationSetsFailedWhenTaskNotFound(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $taskRepository = $this->createMock(TaskRepository::class);
        $taskRepository->method('findByUid')->willReturn(null);

        $service = $this->createChatService(taskRepository: $taskRepository);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertStringContainsString('not found', $conversation->getErrorMessage());
    }

    #[Test]
    public function processConversationSetsFailedWhenConfigurationMissing(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $task = $this->createMock(Task::class);
        $task->method('getConfiguration')->willReturn(null);
        $taskRepository = $this->createMock(TaskRepository::class);
        $taskRepository->method('findByUid')->willReturn($task);

        $service = $this->createChatService(taskRepository: $taskRepository);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertStringContainsString('no LLM configuration', $conversation->getErrorMessage());
    }

    #[Test]
    public function processConversationSetsFailedOnErrorOutcome(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $result = new AgentRunResult(
            AgentRunOutcome::FAILED,
            'run-uuid',
            [],
            null,
            null,
            null,
            new RuntimeException('provider exploded'),
        );

        $service = $this->createChatService($result);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertStringContainsString('provider exploded', $conversation->getErrorMessage());
    }

    #[Test]
    public function processConversationSetsFailedWithDescriptiveMessageOnGuardrailBlock(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $result = new AgentRunResult(AgentRunOutcome::GUARDRAIL_BLOCKED, 'run-uuid', []);

        $service = $this->createChatService($result);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertStringContainsString('guardrail', $conversation->getErrorMessage());
    }

    /**
     * A pending approval is the safeguard working, not a crash. Reporting it as
     * Failed made a correct pause look like a broken feature to anyone using
     * the chat.
     */
    #[Test]
    public function processConversationDoesNotFailWhenAwaitingApproval(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $result = new AgentRunResult(AgentRunOutcome::AWAITING_APPROVAL, 'run-uuid', []);

        $service = $this->createChatService($result);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::AwaitingApproval, $conversation->getStatus());
        self::assertNotSame(ConversationStatus::Failed, $conversation->getStatus());
    }

    /**
     * The message has to say where the approval is granted, otherwise the pause
     * is merely less alarming without being more actionable.
     */
    #[Test]
    public function awaitingApprovalMessageNamesWhereToApproveAndWhichRun(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $service = $this->createChatService(
            new AgentRunResult(AgentRunOutcome::AWAITING_APPROVAL, 'run-uuid-1234', []),
        );
        $service->processConversation($conversation);

        $message = $conversation->getErrorMessage();
        self::assertStringContainsString('approval', $message);
        self::assertStringContainsString('AI Tasks', $message);
        self::assertStringContainsString('run-uuid-1234', $message);
    }

    /**
     * The run uuid is empty when the run could not be persisted (fail-soft
     * persister). An empty reference helps nobody, so it is left out entirely.
     */
    #[Test]
    public function awaitingApprovalMessageOmitsAnEmptyRunReference(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $service = $this->createChatService(
            new AgentRunResult(AgentRunOutcome::AWAITING_APPROVAL, '', []),
        );
        $service->processConversation($conversation);

        self::assertStringNotContainsString('Run: ', $conversation->getErrorMessage());
        self::assertStringContainsString('AI Tasks', $conversation->getErrorMessage());
    }

    /**
     * The uuid is carried in a field of its own so the chat can build a link to
     * the pending run. In the message it is prose, and prose is not a link.
     */
    #[Test]
    public function awaitingApprovalRecordsTheRunForLinking(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $service = $this->createChatService(
            new AgentRunResult(AgentRunOutcome::AWAITING_APPROVAL, 'run-uuid-1234', []),
        );
        $service->processConversation($conversation);

        self::assertSame('run-uuid-1234', $conversation->getApprovalRunUuid());
    }

    /**
     * A conversation that has settled has no approval to grant, and a link to a
     * decision already made invites a click that lands nowhere.
     *
     * The clearing lives in setStatus() rather than at each caller: the paths
     * that settle a conversation are spread across the service and both
     * commands, and one of them would have been forgotten.
     */
    #[Test]
    #[DataProvider('settlingStatusesProvider')]
    public function settlingDropsTheRunReference(ConversationStatus $status): void
    {
        $conversation = new Conversation();
        $conversation->setApprovalRunUuid('run-uuid-1234');
        $conversation->recordApprovalDecision(true, 'digest-abc');

        $conversation->setStatus($status);

        self::assertSame('', $conversation->getApprovalRunUuid());
        self::assertSame('', $conversation->getApprovalDecision());
        self::assertSame('', $conversation->getApprovalTurnDigest());
    }

    /**
     * @return iterable<string, array{ConversationStatus}>
     */
    public static function settlingStatusesProvider(): iterable
    {
        yield 'completed' => [ConversationStatus::Idle];
        yield 'failed' => [ConversationStatus::Failed];
    }

    /**
     * The busy states deliberately KEEP it. A decision recorded in the request
     * is carried by the row until the worker executes it, and the run uuid is
     * what lets a conversation whose worker never came be reconciled against
     * its run. Clearing here would drop the work on the way to the worker.
     *
     * Nothing renders from a surviving reference: the card's gate is the
     * waiting status, not the uuid.
     */
    #[Test]
    #[DataProvider('busyStatusesProvider')]
    public function theBusyStatesCarryTheDecisionToTheWorker(ConversationStatus $status): void
    {
        $conversation = new Conversation();
        $conversation->setApprovalRunUuid('run-uuid-1234');
        $conversation->recordApprovalDecision(true, 'digest-abc');

        $conversation->setStatus($status);

        self::assertSame('run-uuid-1234', $conversation->getApprovalRunUuid());
        self::assertSame('approve', $conversation->getApprovalDecision());
        self::assertSame('digest-abc', $conversation->getApprovalTurnDigest());
    }

    /**
     * @return iterable<string, array{ConversationStatus}>
     */
    public static function busyStatusesProvider(): iterable
    {
        yield 'claimed for the decision' => [ConversationStatus::Processing];
        yield 'claimed by a worker' => [ConversationStatus::Locked];
        yield 'back in the tool loop' => [ConversationStatus::ToolLoop];
    }

    /**
     * The whole run, not the setter on its own: a completed turn leaves no
     * reference behind. The previous version of this test named completion and
     * asserted a failure instead, so the completion arm was never covered.
     */
    #[Test]
    public function completingAfterAnApprovalClearsTheRunReference(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');
        $conversation->setApprovalRunUuid('stale-run-uuid');

        $service = $this->createChatService($this->completedResult());
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame('', $conversation->getApprovalRunUuid());
    }

    /**
     * A turn that fails before the runtime returns never reaches applyResult().
     */
    #[Test]
    public function aTurnThatFailsBeforeTheRuntimeClearsTheRunReference(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');
        $conversation->setApprovalRunUuid('stale-run-uuid');

        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(0);

        $service = $this->createChatService(null, null, $config);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertSame('', $conversation->getApprovalRunUuid());
    }

    #[Test]
    public function processConversationPersistsWhenNoLlmTaskConfigured(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(0);

        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::once())->method('update');

        $service = $this->createChatService(repository: $repository, config: $config);
        $service->processConversation($conversation);
    }

    #[Test]
    public function processConversationPersistsOnCompletion(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::once())->method('update');

        $service = $this->createChatService(repository: $repository);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
    }

    // -------------------------------------------------------------------------
    // resumeConversation
    // -------------------------------------------------------------------------

    #[Test]
    public function resumeConversationDoesNothingForNonResumableStatus(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setStatus(ConversationStatus::Idle);

        $service = $this->createChatService();
        $service->resumeConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertNull($this->capturedRequest);
    }

    #[Test]
    public function resumeConversationReRunsResumableConversation(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 3,
            'be_user' => 1,
            'status' => 'failed',
            'messages' => json_encode([['role' => 'user', 'content' => 'Hi again']]),
            'message_count' => 1,
        ]);

        $service = $this->createChatService($this->completedResult('Resumed answer.'));
        $service->resumeConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        $messages = $conversation->getDecodedMessages();
        self::assertSame('Resumed answer.', end($messages)['content']);
    }

    #[Test]
    public function resumeConversationSetsFailedWhenNoLlmTaskConfigured(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 1,
            'be_user' => 1,
            'status' => 'failed',
            'messages' => json_encode([['role' => 'user', 'content' => 'Hi']]),
            'message_count' => 1,
        ]);

        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(0);

        $service = $this->createChatService(config: $config);
        $service->resumeConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertStringContainsString('nr-llm Task', $conversation->getErrorMessage());
    }

    // -------------------------------------------------------------------------
    // System prompt composition
    // -------------------------------------------------------------------------

    #[Test]
    public function systemPromptAppendsConfigurationPromptAfterIdentity(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $service = $this->createChatService(prompts: ['system_prompt' => 'You are a content editor.']);
        $service->processConversation($conversation);

        $system = $this->capturedSystemPrompt();
        self::assertStringContainsString('TYPO3 Backend AI Chat by Netresearch', $system);
        self::assertStringContainsString('You are a content editor.', $system);
    }

    #[Test]
    public function systemPromptCombinesConfigAndTaskPrompts(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $service = $this->createChatService(prompts: [
            'system_prompt' => 'You are a TYPO3 assistant.',
            'prompt_template' => 'Always wrap record fields in the data parameter.',
        ]);
        $service->processConversation($conversation);

        $system = $this->capturedSystemPrompt();
        self::assertStringContainsString('You are a TYPO3 assistant.', $system);
        self::assertStringContainsString('Always wrap record fields', $system);
    }

    #[Test]
    public function systemPromptUsesConversationCustomPromptInsteadOfConfigPrompts(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setSystemPrompt('Only custom instructions');
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $service = $this->createChatService(prompts: [
            'system_prompt' => 'This should be ignored.',
            'prompt_template' => 'This too.',
        ]);
        $service->processConversation($conversation);

        $system = $this->capturedSystemPrompt();
        // Identity is always present; the conversation prompt replaces config/task prompts.
        self::assertStringContainsString('TYPO3 Backend AI Chat by Netresearch', $system);
        self::assertStringContainsString('Only custom instructions', $system);
        self::assertStringNotContainsString('This should be ignored.', $system);
        self::assertStringNotContainsString('This too.', $system);
    }

    // -------------------------------------------------------------------------
    // Site language context
    // -------------------------------------------------------------------------

    #[Test]
    public function siteLanguageContextIsAppendedToSystemPrompt(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $siteFinder = $this->createSiteFinderWithLanguages([
            ['uid' => 0, 'title' => 'English', 'isoCode' => 'en'],
            ['uid' => 1, 'title' => 'German', 'isoCode' => 'de'],
        ]);

        $service = $this->createChatService(siteFinder: $siteFinder);
        $service->processConversation($conversation);

        $system = $this->capturedSystemPrompt();
        self::assertStringContainsString('Available site languages', $system);
        self::assertStringContainsString('sys_language_uid=0', $system);
        self::assertStringContainsString('sys_language_uid=1', $system);
        self::assertStringContainsString('English', $system);
        self::assertStringContainsString('German', $system);
    }

    #[Test]
    public function siteLanguageContextMarksDefaultLanguage(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $siteFinder = $this->createSiteFinderWithLanguages([
            ['uid' => 0, 'title' => 'Deutsch', 'isoCode' => 'de'],
            ['uid' => 1, 'title' => 'English', 'isoCode' => 'en'],
        ]);

        $service = $this->createChatService(siteFinder: $siteFinder);
        $service->processConversation($conversation);

        $lines = explode("\n", $this->capturedSystemPrompt());
        $uid0Line = array_values(array_filter($lines, static fn(string $l) => str_contains($l, 'sys_language_uid=0')))[0] ?? '';
        $uid1Line = array_values(array_filter($lines, static fn(string $l) => str_contains($l, 'sys_language_uid=1')))[0] ?? '';
        self::assertStringContainsString('(default)', $uid0Line);
        self::assertStringNotContainsString('(default)', $uid1Line);
    }

    #[Test]
    public function siteLanguageContextIsAppendedToCustomPrompt(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setSystemPrompt('Only custom instructions');
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $siteFinder = $this->createSiteFinderWithLanguages([
            ['uid' => 0, 'title' => 'English', 'isoCode' => 'en'],
        ]);

        $service = $this->createChatService(siteFinder: $siteFinder);
        $service->processConversation($conversation);

        $system = $this->capturedSystemPrompt();
        self::assertStringContainsString('Only custom instructions', $system);
        self::assertStringContainsString('Available site languages', $system);
    }

    #[Test]
    public function siteLanguageContextIsOmittedWhenNoSitesConfigured(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        // Default SiteFinder mock returns [] for getAllSites() → no language context
        $service = $this->createChatService();
        $service->processConversation($conversation);

        self::assertStringNotContainsString('Available site languages', $this->capturedSystemPrompt());
    }

    #[Test]
    public function siteLanguagesAreSortedByUidInOutput(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $siteFinder = $this->createSiteFinderWithLanguages([
            ['uid' => 2, 'title' => 'French', 'isoCode' => 'fr'],
            ['uid' => 1, 'title' => 'German', 'isoCode' => 'de'],
            ['uid' => 0, 'title' => 'English', 'isoCode' => 'en'],
        ]);

        $service = $this->createChatService(siteFinder: $siteFinder);
        $service->processConversation($conversation);

        $content = $this->capturedSystemPrompt();
        $pos0 = strpos($content, 'sys_language_uid=0');
        $pos1 = strpos($content, 'sys_language_uid=1');
        $pos2 = strpos($content, 'sys_language_uid=2');
        self::assertNotFalse($pos0);
        self::assertNotFalse($pos1);
        self::assertNotFalse($pos2);
        self::assertLessThan($pos1, $pos0, 'uid=0 must appear before uid=1');
        self::assertLessThan($pos2, $pos1, 'uid=1 must appear before uid=2');
    }

    #[Test]
    public function siteLanguageIsoCodeIsLowercasedFromLocale(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $locale = $this->createMock(Locale::class);
        $locale->method('getLanguageCode')->willReturn('FR');

        $siteLanguage = $this->createMock(SiteLanguage::class);
        $siteLanguage->method('getLanguageId')->willReturn(0);
        $siteLanguage->method('getTitle')->willReturn('French');
        $siteLanguage->method('getLocale')->willReturn($locale);
        $siteLanguage->method('getHreflang')->willReturn('de-CH');

        $site = $this->createMock(Site::class);
        $site->method('getAllLanguages')->willReturn([$siteLanguage]);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([$site]);

        $service = $this->createChatService(siteFinder: $siteFinder);
        $service->processConversation($conversation);

        $content = $this->capturedSystemPrompt();
        self::assertStringContainsString('ISO "fr"', $content);
        self::assertStringNotContainsString('ISO "de"', $content);
    }

    #[Test]
    public function siteLanguageHeaderPrecedesLanguageLines(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $siteFinder = $this->createSiteFinderWithLanguages([
            ['uid' => 0, 'title' => 'English', 'isoCode' => 'en'],
        ]);

        $service = $this->createChatService(siteFinder: $siteFinder);
        $service->processConversation($conversation);

        $content = $this->capturedSystemPrompt();
        $headerPos = strpos($content, 'Available site languages');
        $langLinePos = strpos($content, 'sys_language_uid=0');
        self::assertNotFalse($headerPos);
        self::assertNotFalse($langLinePos);
        self::assertLessThan($langLinePos, $headerPos, 'Header must appear before language lines');
    }

    // -------------------------------------------------------------------------
    // File / multimodal message expansion
    // -------------------------------------------------------------------------

    #[Test]
    public function buildLlmMessagesPassesThroughRegularMessages(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello without file');

        $resourceFactory = $this->createMock(ResourceFactory::class);
        $resourceFactory->expects(self::never())->method('getFileObject');

        $service = $this->createChatService(resourceFactory: $resourceFactory);
        $service->processConversation($conversation);

        $userMsg = $this->capturedUserMessage();
        self::assertSame('user', $userMsg['role']);
        self::assertSame('Hello without file', $userMsg['content']);
    }

    #[Test]
    public function buildLlmMessagesConvertsImageFileToMultimodal(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'chat_test_');
        file_put_contents($tempFile, 'fake-image-data');

        $mockFile = $this->createMock(File::class);
        $mockFile->method('getForLocalProcessing')->willReturn($tempFile);
        $mockFile->method('getMimeType')->willReturn('image/jpeg');

        $resourceFactory = $this->createMock(ResourceFactory::class);
        $resourceFactory->method('getFileObject')->with(42)->willReturn($mockFile);

        $conversation = Conversation::fromRow([
            'uid' => 1,
            'be_user' => 1,
            'status' => 'idle',
            'messages' => json_encode([[
                'role' => 'user',
                'content' => 'What is in this image?',
                'fileUid' => 42,
                'fileName' => 'photo.jpg',
                'fileMimeType' => 'image/jpeg',
            ]]),
            'message_count' => 1,
        ]);

        $service = $this->createChatService(resourceFactory: $resourceFactory);
        $service->processConversation($conversation);

        $userMsg = $this->capturedUserMessage();
        self::assertSame('user', $userMsg['role']);
        self::assertIsArray($userMsg['content']);
        self::assertSame('text', $userMsg['content'][0]['type']);
        self::assertSame('What is in this image?', $userMsg['content'][0]['text']);
        self::assertSame('image_url', $userMsg['content'][1]['type']);
        self::assertStringStartsWith('data:image/jpeg;base64,', $userMsg['content'][1]['image_url']['url']);

        unlink($tempFile);
    }

    #[Test]
    public function buildLlmMessagesConvertsPdfToDocumentBlock(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'chat_test_');
        file_put_contents($tempFile, '%PDF-fake-data');

        $mockFile = $this->createMock(File::class);
        $mockFile->method('getForLocalProcessing')->willReturn($tempFile);
        $mockFile->method('getMimeType')->willReturn('application/pdf');

        $resourceFactory = $this->createMock(ResourceFactory::class);
        $resourceFactory->method('getFileObject')->with(99)->willReturn($mockFile);

        $provider = $this->createMockForIntersectionOfInterfaces([ProviderInterface::class, DocumentCapableInterface::class]);
        $provider->method('supportsDocuments')->willReturn(true);

        $conversation = Conversation::fromRow([
            'uid' => 1,
            'be_user' => 1,
            'status' => 'idle',
            'messages' => json_encode([[
                'role' => 'user',
                'content' => 'Summarize this PDF',
                'fileUid' => 99,
                'fileName' => 'report.pdf',
                'fileMimeType' => 'application/pdf',
            ]]),
            'message_count' => 1,
        ]);

        $service = $this->createChatService(provider: $provider, resourceFactory: $resourceFactory);
        $service->processConversation($conversation);

        $userMsg = $this->capturedUserMessage();
        self::assertIsArray($userMsg['content']);
        self::assertSame('document', $userMsg['content'][1]['type']);
        self::assertSame('base64', $userMsg['content'][1]['source']['type']);
        self::assertSame('application/pdf', $userMsg['content'][1]['source']['media_type']);

        unlink($tempFile);
    }

    #[Test]
    public function buildLlmMessagesHandlesMissingFile(): void
    {
        $resourceFactory = $this->createMock(ResourceFactory::class);
        $resourceFactory->method('getFileObject')->willThrowException(new RuntimeException('File not found'));

        $conversation = Conversation::fromRow([
            'uid' => 1,
            'be_user' => 1,
            'status' => 'idle',
            'messages' => json_encode([[
                'role' => 'user',
                'content' => 'Look at this',
                'fileUid' => 77,
                'fileName' => 'deleted.png',
                'fileMimeType' => 'image/png',
            ]]),
            'message_count' => 1,
        ]);

        $service = $this->createChatService(resourceFactory: $resourceFactory);
        $service->processConversation($conversation);

        $userMsg = $this->capturedUserMessage();
        self::assertSame('user', $userMsg['role']);
        self::assertIsString($userMsg['content']);
        self::assertStringContainsString('Look at this', $userMsg['content']);
        self::assertStringContainsString('deleted.png', $userMsg['content']);
    }

    #[Test]
    public function buildFileContentBlockReturnsTextBlockWhenProviderCannotHandleDocument(): void
    {
        $provider = $this->createMock(ProviderInterface::class);

        $extractor = $this->createMock(\Netresearch\NrMcpAgent\Document\DocumentExtractorInterface::class);
        $extractor->method('isAvailable')->willReturn(true);
        $extractor->method('getSupportedMimeTypes')->willReturn(['text/plain']);
        $extractor->method('extract')->willReturn('Hello TXT');
        $registry = new DocumentExtractorRegistry([$extractor]);

        $service = $this->createChatService(registry: $registry);

        $method = new ReflectionMethod($service, 'buildFileContentBlock');

        $tmpPath = tempnam(sys_get_temp_dir(), 'nr_test_');
        file_put_contents($tmpPath, 'Hello TXT');
        try {
            $block = $method->invoke($service, 'text/plain', base64_encode('Hello TXT'), $tmpPath, $provider);
        } finally {
            unlink($tmpPath);
        }

        self::assertSame('text', $block['type']);
        self::assertStringStartsWith('[Extracted from ', $block['text']);
        self::assertStringContainsString(basename($tmpPath), $block['text']);
        self::assertStringContainsString('Hello TXT', $block['text']);
    }

    // -------------------------------------------------------------------------
    // getProviderCapabilities
    // -------------------------------------------------------------------------

    #[Test]
    public function getProviderCapabilitiesReturnsPdfForVisionAndDocumentCapableProvider(): void
    {
        $provider = $this->createMockForIntersectionOfInterfaces([ProviderInterface::class, VisionCapableInterface::class, DocumentCapableInterface::class]);
        $provider->method('supportsVision')->willReturn(true);
        $provider->method('getSupportedImageFormats')->willReturn(['png', 'jpeg', 'webp']);
        $provider->method('getMaxImageSize')->willReturn(20 * 1024 * 1024);
        $provider->method('supportsDocuments')->willReturn(true);
        $provider->method('getSupportedDocumentFormats')->willReturn(['pdf']);

        $service = $this->createChatService(provider: $provider);
        $caps = $service->getProviderCapabilities();

        self::assertTrue($caps['visionSupported']);
        self::assertSame(20 * 1024 * 1024, $caps['maxFileSize']);
        self::assertContains('png', $caps['supportedFormats']);
        self::assertContains('pdf', $caps['supportedFormats']);
    }

    #[Test]
    public function getProviderCapabilitiesExcludesPdfForVisionOnlyProvider(): void
    {
        $provider = $this->createMockForIntersectionOfInterfaces([ProviderInterface::class, VisionCapableInterface::class]);
        $provider->method('supportsVision')->willReturn(true);
        $provider->method('getSupportedImageFormats')->willReturn(['png', 'jpeg']);
        $provider->method('getMaxImageSize')->willReturn(10 * 1024 * 1024);

        $service = $this->createChatService(provider: $provider);
        $caps = $service->getProviderCapabilities();

        self::assertTrue($caps['visionSupported']);
        self::assertContains('png', $caps['supportedFormats']);
        self::assertNotContains('pdf', $caps['supportedFormats']);
    }

    #[Test]
    public function getProviderCapabilitiesReturnsEmptyForNonVisionProvider(): void
    {
        $provider = $this->createMock(ProviderInterface::class);

        $service = $this->createChatService(provider: $provider);
        $caps = $service->getProviderCapabilities();

        self::assertFalse($caps['visionSupported']);
        self::assertSame(0, $caps['maxFileSize']);
        self::assertSame([], $caps['supportedFormats']);
    }

    #[Test]
    public function getProviderCapabilitiesFallsBackWhenResolutionFails(): void
    {
        $taskRepository = $this->createMock(TaskRepository::class);
        $taskRepository->method('findByUid')->willReturn(null);

        $service = $this->createChatService(taskRepository: $taskRepository);
        $caps = $service->getProviderCapabilities();

        self::assertFalse($caps['visionSupported']);
        self::assertSame(0, $caps['maxFileSize']);
    }
}
