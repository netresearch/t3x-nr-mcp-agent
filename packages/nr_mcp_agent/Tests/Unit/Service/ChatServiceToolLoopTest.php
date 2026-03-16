<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
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

/**
 * Combined interface for testing — provider that supports both chat and tool calling.
 */
interface ToolCapableProviderStub extends ProviderInterface, ToolCapableInterface {}

class ChatServiceToolLoopTest extends TestCase
{
    /** @var list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}> */
    private array $dummyTools = [
        [
            'type' => 'function',
            'function' => [
                'name' => 'dummy_tool',
                'description' => 'A dummy tool for testing',
                'parameters' => ['type' => 'object', 'properties' => []],
            ],
        ],
    ];

    private function createCompletionResponse(string $content = '', ?array $toolCalls = null): CompletionResponse
    {
        return new CompletionResponse(
            content: $content,
            model: 'test-model',
            usage: new UsageStatistics(10, 20, 30),
            toolCalls: $toolCalls,
        );
    }

    /**
     * @return array{ConnectionPool, ProviderAdapterRegistry, DataMapper}
     */
    private function createProviderResolutionMocks(ProviderInterface $provider): array
    {
        $exprBuilder = $this->createMock(ExpressionBuilder::class);
        $exprBuilder->method('eq')->willReturn('1 = 1');

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['uid' => 1, 'name' => 'test-model', '_config_system_prompt' => '', '_task_prompt_template' => '']);

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

    private function createService(
        ToolCapableProviderStub $provider,
        ?ConversationRepository $repository = null,
        ?ExtensionConfiguration $config = null,
        ?McpToolProviderInterface $mcpProvider = null,
    ): ChatService {
        $repository ??= $this->createMock(ConversationRepository::class);
        $config ??= $this->createMcpEnabledConfig();
        $mcpProvider ??= $this->createMcpProviderStub();

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

    private function createMcpProviderStub(): McpToolProviderInterface
    {
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn($this->dummyTools);
        return $mcpProvider;
    }

    private function setUpBeUser(): void
    {
        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function maxToolIterationsReachedSetsFailed(): void
    {
        $this->setUpBeUser();

        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Do something');

        $toolCall = [
            'id' => 'call_1',
            'type' => 'function',
            'function' => ['name' => 'test_tool', 'arguments' => '{}'],
        ];

        $provider = $this->createMock(ToolCapableProviderStub::class);
        // Always return tool calls — should hit MAX_TOOL_ITERATIONS (20)
        $provider->method('chatCompletionWithTools')
            ->willReturn($this->createCompletionResponse('', [$toolCall]));

        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn($this->dummyTools);
        $mcpProvider->method('executeTool')->willReturn('tool result');

        $service = $this->createService($provider, mcpProvider: $mcpProvider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertSame('Max tool iterations reached', $conversation->getErrorMessage());
    }

    #[Test]
    public function toolLoopExecutesToolAndContinues(): void
    {
        $this->setUpBeUser();

        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Use a tool');

        $toolCall = [
            'id' => 'call_abc',
            'type' => 'function',
            'function' => ['name' => 'my_tool', 'arguments' => '{"key":"val"}'],
        ];

        $callCount = 0;
        $provider = $this->createMock(ToolCapableProviderStub::class);
        $provider->method('chatCompletionWithTools')
            ->willReturnCallback(function () use ($toolCall, &$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return $this->createCompletionResponse('', [$toolCall]);
                }
                return $this->createCompletionResponse('Done!');
            });

        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn($this->dummyTools);
        $mcpProvider->expects(self::once())
            ->method('executeTool')
            ->with('my_tool', ['key' => 'val'])
            ->willReturn('tool output');

        $service = $this->createService($provider, mcpProvider: $mcpProvider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        $messages = $conversation->getDecodedMessages();
        // user, assistant+tool_calls, tool, assistant
        self::assertGreaterThanOrEqual(4, count($messages));
    }

    #[Test]
    public function executeToolCallsHandlesJsonStringArguments(): void
    {
        $this->setUpBeUser();

        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'call tool');

        $toolCall = [
            'id' => 'call_json',
            'type' => 'function',
            'function' => [
                'name' => 'json_tool',
                'arguments' => '{"query":"SELECT 1"}',
            ],
        ];

        $callCount = 0;
        $provider = $this->createMock(ToolCapableProviderStub::class);
        $provider->method('chatCompletionWithTools')
            ->willReturnCallback(function () use ($toolCall, &$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return $this->createCompletionResponse('', [$toolCall]);
                }
                return $this->createCompletionResponse('Result');
            });

        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn($this->dummyTools);
        $mcpProvider->expects(self::once())
            ->method('executeTool')
            ->with('json_tool', ['query' => 'SELECT 1'])
            ->willReturn('1');

        $service = $this->createService($provider, mcpProvider: $mcpProvider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
    }

    #[Test]
    public function executeToolCallsSkipsNonArrayItems(): void
    {
        $this->setUpBeUser();

        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'call');

        // Mix of valid and invalid tool calls
        $toolCalls = [
            'not-an-array',
            [
                'id' => 'call_valid',
                'type' => 'function',
                'function' => ['name' => 'valid_tool', 'arguments' => '{}'],
            ],
            42,
        ];

        $callCount = 0;
        $provider = $this->createMock(ToolCapableProviderStub::class);
        $provider->method('chatCompletionWithTools')
            ->willReturnCallback(function () use ($toolCalls, &$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return $this->createCompletionResponse('', $toolCalls);
                }
                return $this->createCompletionResponse('Done');
            });

        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn($this->dummyTools);
        // Only the valid tool call should be executed
        $mcpProvider->expects(self::once())
            ->method('executeTool')
            ->with('valid_tool', [])
            ->willReturn('ok');

        $service = $this->createService($provider, mcpProvider: $mcpProvider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
    }

    #[Test]
    public function resumeConversationExecutesPendingToolCalls(): void
    {
        $this->setUpBeUser();

        $pendingToolCalls = [
            [
                'id' => 'call_pending',
                'type' => 'function',
                'function' => ['name' => 'pending_tool', 'arguments' => '{}'],
            ],
        ];

        $conversation = Conversation::fromRow([
            'uid' => 10,
            'be_user' => 1,
            'status' => 'failed',
            'messages' => json_encode([
                ['role' => 'user', 'content' => 'Do thing'],
                ['role' => 'assistant', 'content' => '', 'tool_calls' => $pendingToolCalls],
            ]),
            'message_count' => 2,
        ]);

        $provider = $this->createMock(ToolCapableProviderStub::class);
        $provider->method('chatCompletionWithTools')
            ->willReturn($this->createCompletionResponse('Resumed!'));

        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn($this->dummyTools);
        $mcpProvider->expects(self::once())
            ->method('executeTool')
            ->with('pending_tool', [])
            ->willReturn('pending result');

        $service = $this->createService($provider, mcpProvider: $mcpProvider);
        $service->resumeConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        $messages = $conversation->getDecodedMessages();
        $lastMessage = end($messages);
        self::assertSame('assistant', $lastMessage['role']);
        self::assertSame('Resumed!', $lastMessage['content']);
    }

    #[Test]
    public function resumeConversationWithNoPendingToolCallsRunsNormalLoop(): void
    {
        $this->setUpBeUser();

        $conversation = Conversation::fromRow([
            'uid' => 11,
            'be_user' => 1,
            'status' => 'failed',
            'messages' => json_encode([
                ['role' => 'user', 'content' => 'Hello'],
            ]),
            'message_count' => 1,
        ]);

        $provider = $this->createMock(ToolCapableProviderStub::class);
        $provider->expects(self::once())
            ->method('chatCompletionWithTools')
            ->willReturn($this->createCompletionResponse('Hi!'));

        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn($this->dummyTools);
        $mcpProvider->expects(self::never())->method('executeTool');

        $service = $this->createService($provider, mcpProvider: $mcpProvider);
        $service->resumeConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
    }

    #[Test]
    public function processConversationSetsFailedOnException(): void
    {
        $this->setUpBeUser();

        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('connect')->willThrowException(new RuntimeException('Connection failed'));

        $provider = $this->createMock(ToolCapableProviderStub::class);

        $service = $this->createService($provider, mcpProvider: $mcpProvider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertStringContainsString('Connection failed', $conversation->getErrorMessage());
    }

    #[Test]
    public function processConversationDisconnectsMcpEvenOnFailure(): void
    {
        $this->setUpBeUser();

        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('connect')->willThrowException(new RuntimeException('fail'));
        $mcpProvider->expects(self::once())->method('disconnect');

        $provider = $this->createMock(ToolCapableProviderStub::class);

        $service = $this->createService($provider, mcpProvider: $mcpProvider);
        $service->processConversation($conversation);
    }

    #[Test]
    public function sanitizeErrorMessageRedactsApiKeys(): void
    {
        $this->setUpBeUser();

        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('connect')->willThrowException(
            new RuntimeException('Auth failed with Bearer sk-abc123def456 at https://api.example.com/v1/chat'),
        );

        $provider = $this->createMock(ToolCapableProviderStub::class);

        $service = $this->createService($provider, mcpProvider: $mcpProvider);
        $service->processConversation($conversation);

        self::assertStringNotContainsString('sk-abc123def456', $conversation->getErrorMessage());
        self::assertStringNotContainsString('https://api.example.com', $conversation->getErrorMessage());
        self::assertStringContainsString('[REDACTED]', $conversation->getErrorMessage());
        self::assertStringContainsString('[URL]', $conversation->getErrorMessage());
    }

    #[Test]
    public function resumeConversationSetsFailedWhenToolExecutionThrows(): void
    {
        $this->setUpBeUser();

        $pendingToolCalls = [
            [
                'id' => 'call_fail',
                'type' => 'function',
                'function' => ['name' => 'failing_tool', 'arguments' => '{}'],
            ],
        ];

        $conversation = Conversation::fromRow([
            'uid' => 20,
            'be_user' => 1,
            'status' => 'failed',
            'messages' => json_encode([
                ['role' => 'user', 'content' => 'Do thing'],
                ['role' => 'assistant', 'content' => '', 'tool_calls' => $pendingToolCalls],
            ]),
            'message_count' => 2,
        ]);

        $provider = $this->createMock(ToolCapableProviderStub::class);

        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn($this->dummyTools);
        $mcpProvider->method('executeTool')
            ->willThrowException(new RuntimeException('Tool execution failed with key sk-secret123'));

        $service = $this->createService($provider, mcpProvider: $mcpProvider);
        $service->resumeConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertStringNotContainsString('sk-secret123', $conversation->getErrorMessage());
        self::assertStringContainsString('[REDACTED]', $conversation->getErrorMessage());
    }

    #[Test]
    public function agentLoopFailsWhenProviderNotToolCapable(): void
    {
        $this->setUpBeUser();

        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        // Plain ProviderInterface — does NOT implement ToolCapableInterface
        $provider = $this->createMock(ProviderInterface::class);

        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn($this->dummyTools);

        $config = $this->createMcpEnabledConfig();
        $repository = $this->createMock(ConversationRepository::class);

        // Build mocks manually since createService expects ToolCapableProviderStub
        $exprBuilder = $this->createMock(ExpressionBuilder::class);
        $exprBuilder->method('eq')->willReturn('1 = 1');
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
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
        $model = $this->createMock(LlmModel::class);
        $dataMapper = $this->createMock(DataMapper::class);
        $dataMapper->method('map')->willReturn([$model]);
        $adapterRegistry = $this->createMock(ProviderAdapterRegistry::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn($provider);

        $service = new ChatService($repository, $config, $mcpProvider, $connectionPool, $adapterRegistry, $dataMapper);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertStringContainsString('does not support tool calling', $conversation->getErrorMessage());
    }
}
