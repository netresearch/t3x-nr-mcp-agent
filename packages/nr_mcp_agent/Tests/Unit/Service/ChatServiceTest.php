<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
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
     * @return array{ConnectionPool, ProviderAdapterRegistry, DataMapper}
     */
    private function createProviderResolutionMocks(ProviderInterface $provider): array
    {
        $exprBuilder = $this->createMock(ExpressionBuilder::class);
        $exprBuilder->method('eq')->willReturn('1 = 1');

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['uid' => 1, 'name' => 'test-model']);

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

    private function createChatService(
        ProviderInterface $provider,
        ?ConversationRepository $repository = null,
        ?ExtensionConfiguration $config = null,
        ?McpToolProviderInterface $mcpProvider = null,
    ): ChatService {
        $repository ??= $this->createMock(ConversationRepository::class);
        if ($config === null) {
            $config = $this->createStub(ExtensionConfiguration::class);
            $config->method('getLlmTaskUid')->willReturn(1);
            $config->method('isMcpEnabled')->willReturn(false);
        }
        $mcpProvider ??= $this->createMock(McpToolProviderInterface::class);

        [$connectionPool, $adapterRegistry, $dataMapper] = $this->createProviderResolutionMocks($provider);

        return new ChatService($repository, $config, $mcpProvider, $connectionPool, $adapterRegistry, $dataMapper);
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
    public function defaultSystemPromptIncludesToolUsageHints(): void
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

        $service = $this->createChatService($provider);
        $service->processConversation($conversation);

        self::assertNotNull($capturedMessages);
        $systemContent = $capturedMessages[0]['content'];
        self::assertStringContainsString('WriteTable', $systemContent);
        self::assertStringContainsString('"data"', $systemContent);
        self::assertStringContainsString('nested inside "data"', $systemContent);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function customSystemPromptDoesNotIncludeToolHints(): void
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

        $service = $this->createChatService($provider);
        $service->processConversation($conversation);

        self::assertNotNull($capturedMessages);
        self::assertSame('Only custom instructions', $capturedMessages[0]['content']);
        self::assertStringNotContainsString('WriteTable', $capturedMessages[0]['content']);

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
}
