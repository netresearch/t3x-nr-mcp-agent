<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Provider\Contract\DocumentCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Mcp\McpToolProviderInterface;
use Netresearch\NrMcpAgent\Service\ChatService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper;

class ChatServiceTest extends TestCase
{
    private function createCompletionResponse(string $content = 'Hi!', ?array $toolCalls = null): CompletionResponse
    {
        return new CompletionResponse(
            content: $content,
            model: 'test-model',
            usage: new UsageStatistics(10, 20, 30),
            toolCalls: $toolCalls,
        );
    }

    /**
     * Create mock chain for resolveProvider() — returns [ConnectionPool, ProviderAdapterRegistry, DataMapper].
     *
     * @param array{system_prompt?: string, prompt_template?: string} $prompts
     * @return array{ConnectionPool, ProviderAdapterRegistry, DataMapper}
     */
    private function createProviderResolutionMocks(ProviderInterface $provider, array $prompts = []): array
    {
        $exprBuilder = $this->createMock(ExpressionBuilder::class);
        $exprBuilder->method('eq')->willReturn('1 = 1');

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn([
            'uid' => 1,
            'name' => 'test-model',
            '_config_system_prompt' => array_key_exists('system_prompt', $prompts) ? $prompts['system_prompt'] : '',
            '_task_prompt_template' => array_key_exists('prompt_template', $prompts) ? $prompts['prompt_template'] : '',
        ]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('join')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('expr')->willReturn($exprBuilder);
        $qb->method('quoteIdentifier')->willReturnArgument(0);
        $qb->method('createNamedParameter')->willReturn('1');
        $qb->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($qb);

        $model = $this->createMock(LlmModel::class);
        $dataMapper = $this->createMock(DataMapper::class);
        $dataMapper->method('map')->willReturn([$model]);

        $adapterRegistry = $this->createMock(ProviderAdapterRegistry::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn($provider);

        return [$connectionPool, $adapterRegistry, $dataMapper];
    }

    /**
     * @param array{system_prompt?: string, prompt_template?: string} $prompts
     */
    private function createChatService(
        ProviderInterface $provider,
        ?ConversationRepository $repository = null,
        ?ExtensionConfiguration $config = null,
        ?McpToolProviderInterface $mcpProvider = null,
        array $prompts = [],
        ?ResourceFactory $resourceFactory = null,
    ): ChatService {
        $repository ??= $this->createMock(ConversationRepository::class);
        if ($config === null) {
            $config = $this->createStub(ExtensionConfiguration::class);
            $config->method('getLlmTaskUid')->willReturn(1);
            $config->method('isMcpEnabled')->willReturn(false);
        }
        $mcpProvider ??= $this->createMock(McpToolProviderInterface::class);
        $resourceFactory ??= $this->createMock(ResourceFactory::class);

        [$connectionPool, $adapterRegistry, $dataMapper] = $this->createProviderResolutionMocks($provider, $prompts);

        return new ChatService($repository, $config, $mcpProvider, $connectionPool, $adapterRegistry, $dataMapper, $resourceFactory);
    }

    private function createMcpEnabledConfig(): ExtensionConfiguration
    {
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $config->method('isMcpEnabled')->willReturn(true);
        $config->method('isMcpServerInstalled')->willReturn(true);
        return $config;
    }

    #[Test]
    public function processConversationSetsIdleOnSimpleResponse(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $provider = $this->createMock(ProviderInterface::class);
        $provider->expects(self::once())->method('chatCompletion')
            ->willReturn($this->createCompletionResponse('Hi there!'));

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame(2, $conversation->getMessageCount());

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function processConversationSetsFailedWhenNoLlmTaskConfigured(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(0);

        $provider = $this->createMock(ProviderInterface::class);
        $service = $this->createChatService($provider, config: $config);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertStringContainsString('nr-llm Task', $conversation->getErrorMessage());
    }

    #[Test]
    public function resumeConversationDoesNothingForNonResumableStatus(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setStatus(ConversationStatus::Idle);

        $provider = $this->createMock(ProviderInterface::class);
        $provider->expects(self::never())->method('chatCompletion');

        $service = $this->createChatService($provider);
        $service->resumeConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
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

        $provider = $this->createMock(ProviderInterface::class);
        $service = $this->createChatService($provider, config: $config);
        $service->resumeConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertStringContainsString('nr-llm Task', $conversation->getErrorMessage());
    }

    #[Test]
    public function processConversationCallsUpdateStatusAtStart(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 42,
            'be_user' => 1,
            'status' => 'processing',
            'messages' => json_encode([['role' => 'user', 'content' => 'Hi']]),
            'message_count' => 1,
        ]);

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')
            ->willReturn($this->createCompletionResponse('Hello!'));

        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::once())
            ->method('updateStatus')
            ->with(42, ConversationStatus::Processing, 1);

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider, repository: $repository);
        $service->processConversation($conversation);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function buildSystemPromptReturnsGermanForDeLocale(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hallo');

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')
            ->willReturn($this->createCompletionResponse('Hallo!'));

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'de'];

        $service = $this->createChatService($provider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function buildSystemPromptUsesCustomPromptWhenSet(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setSystemPrompt('Custom system prompt');
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')
            ->willReturn($this->createCompletionResponse('Hi!'));

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function buildSystemPromptPassesGermanPromptToLlm(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hallo');

        $capturedMessages = null;
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willReturnCallback(
            function (array $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;
                return $this->createCompletionResponse('Hallo!');
            },
        );

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'de'];

        $service = $this->createChatService($provider);
        $service->processConversation($conversation);

        self::assertNotNull($capturedMessages);
        self::assertSame('system', $capturedMessages[0]['role']);
        self::assertStringContainsString('Deutsch', $capturedMessages[0]['content']);
        self::assertStringContainsString('TYPO3-Assistent', $capturedMessages[0]['content']);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function buildSystemPromptPassesCustomPromptToLlm(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setSystemPrompt('My custom system prompt');
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $capturedMessages = null;
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willReturnCallback(
            function (array $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;
                return $this->createCompletionResponse('Hi!');
            },
        );

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider);
        $service->processConversation($conversation);

        self::assertNotNull($capturedMessages);
        self::assertSame('system', $capturedMessages[0]['role']);
        self::assertSame('My custom system prompt', $capturedMessages[0]['content']);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function systemPromptUsesConfigurationPromptWhenSet(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $capturedMessages = null;
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willReturnCallback(
            function (array $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;
                return $this->createCompletionResponse('Hi!');
            },
        );

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider, prompts: [
            'system_prompt' => 'You are a content editor.',
        ]);
        $service->processConversation($conversation);

        self::assertNotNull($capturedMessages);
        self::assertSame('You are a content editor.', $capturedMessages[0]['content']);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function systemPromptCombinesConfigAndTaskPrompts(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $capturedMessages = null;
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willReturnCallback(
            function (array $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;
                return $this->createCompletionResponse('Hi!');
            },
        );

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider, prompts: [
            'system_prompt' => 'You are a TYPO3 assistant.',
            'prompt_template' => 'Always wrap record fields in the data parameter.',
        ]);
        $service->processConversation($conversation);

        self::assertNotNull($capturedMessages);
        $content = $capturedMessages[0]['content'];
        self::assertStringContainsString('You are a TYPO3 assistant.', $content);
        self::assertStringContainsString('Always wrap record fields', $content);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function conversationPromptOverridesConfigPrompts(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setSystemPrompt('Only custom instructions');
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $capturedMessages = null;
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willReturnCallback(
            function (array $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;
                return $this->createCompletionResponse('Hi!');
            },
        );

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider, prompts: [
            'system_prompt' => 'This should be ignored.',
        ]);
        $service->processConversation($conversation);

        self::assertNotNull($capturedMessages);
        self::assertSame('Only custom instructions', $capturedMessages[0]['content']);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function processConversationDisconnectsMcpOnSuccess(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')
            ->willReturn($this->createCompletionResponse('Hi!'));

        $config = $this->createMcpEnabledConfig();
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);
        $mcpProvider->expects(self::once())->method('disconnect');

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider, config: $config, mcpProvider: $mcpProvider);
        $service->processConversation($conversation);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function processConversationDisconnectsMcpOnError(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')
            ->willThrowException(new RuntimeException('LLM exploded'));

        $config = $this->createMcpEnabledConfig();
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);
        $mcpProvider->expects(self::once())->method('disconnect');

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider, config: $config, mcpProvider: $mcpProvider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertStringContainsString('LLM exploded', $conversation->getErrorMessage());

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function processConversationSetsStatusToProcessingAtStart(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 10,
            'be_user' => 1,
            'status' => 'processing',
            'messages' => json_encode([['role' => 'user', 'content' => 'Hi']]),
            'message_count' => 1,
        ]);

        $statusUpdates = [];
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')
            ->willReturn($this->createCompletionResponse('Hello!'));

        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('updateStatus')->willReturnCallback(
            function (int $uid, ConversationStatus $status) use (&$statusUpdates): void {
                $statusUpdates[] = ['uid' => $uid, 'status' => $status];
            },
        );

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider, repository: $repository);
        $service->processConversation($conversation);

        self::assertNotEmpty($statusUpdates);
        self::assertSame(10, $statusUpdates[0]['uid']);
        self::assertSame(ConversationStatus::Processing, $statusUpdates[0]['status']);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function buildSystemPromptDefaultsToEnglishWhenNoBeUser(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $capturedMessages = null;
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willReturnCallback(
            function (array $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;
                return $this->createCompletionResponse('Hi!');
            },
        );

        // No BE_USER set
        unset($GLOBALS['BE_USER']);

        $service = $this->createChatService($provider);
        $service->processConversation($conversation);

        self::assertNotNull($capturedMessages);
        self::assertSame('system', $capturedMessages[0]['role']);
        self::assertStringContainsString('English', $capturedMessages[0]['content']);
        self::assertStringContainsString('TYPO3 assistant', $capturedMessages[0]['content']);
    }

    #[Test]
    public function processConversationFailsWhenProviderModelNotFound(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        // Mock fetchAssociative to return false (no DB row)
        $exprBuilder = $this->createMock(ExpressionBuilder::class);
        $exprBuilder->method('eq')->willReturn('1 = 1');

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('join')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('expr')->willReturn($exprBuilder);
        $qb->method('quoteIdentifier')->willReturnArgument(0);
        $qb->method('createNamedParameter')->willReturn('1');
        $qb->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($qb);

        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(999);
        $config->method('isMcpEnabled')->willReturn(false);

        $repository = $this->createMock(ConversationRepository::class);
        $dataMapper = $this->createMock(DataMapper::class);
        $adapterRegistry = $this->createMock(ProviderAdapterRegistry::class);

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = new ChatService($repository, $config, $this->createMock(McpToolProviderInterface::class), $connectionPool, $adapterRegistry, $dataMapper, $this->createMock(ResourceFactory::class));
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertStringContainsString('Could not resolve LLM model', $conversation->getErrorMessage());

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function processConversationFailsWhenDataMapperReturnsEmpty(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $exprBuilder = $this->createMock(ExpressionBuilder::class);
        $exprBuilder->method('eq')->willReturn('1 = 1');

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn([
            'uid' => 1, 'name' => 'test',
            '_config_system_prompt' => '', '_task_prompt_template' => '',
        ]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('join')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('expr')->willReturn($exprBuilder);
        $qb->method('quoteIdentifier')->willReturnArgument(0);
        $qb->method('createNamedParameter')->willReturn('1');
        $qb->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($qb);

        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $config->method('isMcpEnabled')->willReturn(false);

        $dataMapper = $this->createMock(DataMapper::class);
        $dataMapper->method('map')->willReturn([]);  // Empty — model mapping failed

        $repository = $this->createMock(ConversationRepository::class);
        $adapterRegistry = $this->createMock(ProviderAdapterRegistry::class);

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = new ChatService($repository, $config, $this->createMock(McpToolProviderInterface::class), $connectionPool, $adapterRegistry, $dataMapper, $this->createMock(ResourceFactory::class));
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertStringContainsString('Could not map LLM model', $conversation->getErrorMessage());

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function systemPromptUsesTaskPromptOnlyWhenConfigEmpty(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $capturedMessages = null;
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willReturnCallback(
            function (array $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;
                return $this->createCompletionResponse('Hi!');
            },
        );

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider, prompts: [
            'system_prompt' => '',
            'prompt_template' => 'Use the data parameter for record fields.',
        ]);
        $service->processConversation($conversation);

        self::assertNotNull($capturedMessages);
        self::assertSame('Use the data parameter for record fields.', $capturedMessages[0]['content']);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function systemPromptFallsBackToLocaleWhenBothPromptsEmpty(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $capturedMessages = null;
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willReturnCallback(
            function (array $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;
                return $this->createCompletionResponse('Hi!');
            },
        );

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'de'];

        $service = $this->createChatService($provider, prompts: [
            'system_prompt' => '',
            'prompt_template' => '',
        ]);
        $service->processConversation($conversation);

        self::assertNotNull($capturedMessages);
        self::assertStringContainsString('TYPO3-Assistent', $capturedMessages[0]['content']);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function buildLlmMessagesPassesThroughRegularMessages(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello without file');

        $capturedMessages = null;
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willReturnCallback(
            function (array $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;
                return $this->createCompletionResponse('Hi!');
            },
        );

        $resourceFactory = $this->createMock(ResourceFactory::class);
        $resourceFactory->expects(self::never())->method('getFileObject');

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider, resourceFactory: $resourceFactory);
        $service->processConversation($conversation);

        self::assertNotNull($capturedMessages);
        // System prompt + user message
        $userMsg = end($capturedMessages);
        self::assertSame('user', $userMsg['role']);
        self::assertSame('Hello without file', $userMsg['content']);

        unset($GLOBALS['BE_USER']);
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

        $capturedMessages = null;
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willReturnCallback(
            function (array $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;
                return $this->createCompletionResponse('It is a dog.');
            },
        );

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider, resourceFactory: $resourceFactory);
        $service->processConversation($conversation);

        self::assertNotNull($capturedMessages);
        $userMsg = end($capturedMessages);
        self::assertSame('user', $userMsg['role']);
        self::assertIsArray($userMsg['content']);
        self::assertSame('text', $userMsg['content'][0]['type']);
        self::assertSame('What is in this image?', $userMsg['content'][0]['text']);
        self::assertSame('image_url', $userMsg['content'][1]['type']);
        self::assertStringStartsWith('data:image/jpeg;base64,', $userMsg['content'][1]['image_url']['url']);

        unlink($tempFile);
        unset($GLOBALS['BE_USER']);
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

        $capturedMessages = null;
        $provider = $this->createMockForIntersectionOfInterfaces([ProviderInterface::class, DocumentCapableInterface::class]);
        $provider->method('supportsDocuments')->willReturn(true);
        $provider->method('chatCompletion')->willReturnCallback(
            function (array $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;
                return $this->createCompletionResponse('Summary here.');
            },
        );

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider, resourceFactory: $resourceFactory);
        $service->processConversation($conversation);

        self::assertNotNull($capturedMessages);
        $userMsg = end($capturedMessages);
        self::assertSame('user', $userMsg['role']);
        self::assertIsArray($userMsg['content']);
        self::assertSame('text', $userMsg['content'][0]['type']);
        self::assertSame('Summarize this PDF', $userMsg['content'][0]['text']);
        self::assertSame('document', $userMsg['content'][1]['type']);
        self::assertSame('base64', $userMsg['content'][1]['source']['type']);
        self::assertSame('application/pdf', $userMsg['content'][1]['source']['media_type']);

        unlink($tempFile);
        unset($GLOBALS['BE_USER']);
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

        $capturedMessages = null;
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willReturnCallback(
            function (array $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;
                return $this->createCompletionResponse('OK');
            },
        );

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = $this->createChatService($provider, resourceFactory: $resourceFactory);
        $service->processConversation($conversation);

        self::assertNotNull($capturedMessages);
        $userMsg = end($capturedMessages);
        self::assertSame('user', $userMsg['role']);
        self::assertIsString($userMsg['content']);
        self::assertStringContainsString('Look at this', $userMsg['content']);
        self::assertStringContainsString('deleted.png', $userMsg['content']);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function resolveProviderHandlesNullPromptFieldsFromDatabase(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $capturedMessages = null;
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('chatCompletion')->willReturnCallback(
            function (array $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;
                return $this->createCompletionResponse('Hi!');
            },
        );

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        // Simulate null values from DB (no system_prompt configured)
        $service = $this->createChatService($provider, prompts: [
            'system_prompt' => null,
            'prompt_template' => null,
        ]);
        $service->processConversation($conversation);

        self::assertNotNull($capturedMessages);
        // Should fall back to locale default
        self::assertStringContainsString('TYPO3 assistant', $capturedMessages[0]['content']);

        unset($GLOBALS['BE_USER']);
    }
}
