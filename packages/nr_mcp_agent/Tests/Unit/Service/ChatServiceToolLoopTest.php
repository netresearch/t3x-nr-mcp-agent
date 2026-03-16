<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
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

class ChatServiceToolLoopTest extends TestCase
{
    private function createCompletionResponse(string $content = '', ?array $toolCalls = null): CompletionResponse
    {
        return new CompletionResponse(
            content: $content,
            model: 'test-model',
            usage: new UsageStatistics(10, 20, 30),
            toolCalls: $toolCalls,
        );
    }

    private function createService(
        LlmServiceManagerInterface $llmManager,
        ?ConversationRepository $repository = null,
        ?ExtensionConfiguration $config = null,
        ?McpToolProviderInterface $mcpProvider = null,
    ): ChatService {
        $repository ??= $this->createMock(ConversationRepository::class);
        $config ??= $this->createConfigStub();
        $mcpProvider ??= $this->createMcpProviderStub();

        return new ChatService($llmManager, $repository, $config, $mcpProvider);
    }

    private function createConfigStub(): ExtensionConfiguration
    {
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        return $config;
    }

    private function createMcpProviderStub(): McpToolProviderInterface
    {
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);
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

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        // Always return tool calls — should hit MAX_TOOL_ITERATIONS (20)
        $llmManager->method('chatWithTools')
            ->willReturn($this->createCompletionResponse('', [$toolCall]));

        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);
        $mcpProvider->method('executeTool')->willReturn('tool result');

        $service = $this->createService($llmManager, mcpProvider: $mcpProvider);
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
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatWithTools')
            ->willReturnCallback(function () use ($toolCall, &$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return $this->createCompletionResponse('', [$toolCall]);
                }
                return $this->createCompletionResponse('Done!');
            });

        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);
        $mcpProvider->expects(self::once())
            ->method('executeTool')
            ->with('my_tool', ['key' => 'val'])
            ->willReturn('tool output');

        $service = $this->createService($llmManager, mcpProvider: $mcpProvider);
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
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatWithTools')
            ->willReturnCallback(function () use ($toolCall, &$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return $this->createCompletionResponse('', [$toolCall]);
                }
                return $this->createCompletionResponse('Result');
            });

        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);
        $mcpProvider->expects(self::once())
            ->method('executeTool')
            ->with('json_tool', ['query' => 'SELECT 1'])
            ->willReturn('1');

        $service = $this->createService($llmManager, mcpProvider: $mcpProvider);
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
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatWithTools')
            ->willReturnCallback(function () use ($toolCalls, &$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return $this->createCompletionResponse('', $toolCalls);
                }
                return $this->createCompletionResponse('Done');
            });

        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);
        // Only the valid tool call should be executed
        $mcpProvider->expects(self::once())
            ->method('executeTool')
            ->with('valid_tool', [])
            ->willReturn('ok');

        $service = $this->createService($llmManager, mcpProvider: $mcpProvider);
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

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatWithTools')
            ->willReturn($this->createCompletionResponse('Resumed!'));

        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);
        $mcpProvider->expects(self::once())
            ->method('executeTool')
            ->with('pending_tool', [])
            ->willReturn('pending result');

        $service = $this->createService($llmManager, mcpProvider: $mcpProvider);
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

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->expects(self::once())
            ->method('chatWithTools')
            ->willReturn($this->createCompletionResponse('Hi!'));

        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);
        $mcpProvider->expects(self::never())->method('executeTool');

        $service = $this->createService($llmManager, mcpProvider: $mcpProvider);
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

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);

        $service = $this->createService($llmManager, mcpProvider: $mcpProvider);
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

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);

        $service = $this->createService($llmManager, mcpProvider: $mcpProvider);
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

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);

        $service = $this->createService($llmManager, mcpProvider: $mcpProvider);
        $service->processConversation($conversation);

        self::assertStringNotContainsString('sk-abc123def456', $conversation->getErrorMessage());
        self::assertStringNotContainsString('https://api.example.com', $conversation->getErrorMessage());
        self::assertStringContainsString('[REDACTED]', $conversation->getErrorMessage());
        self::assertStringContainsString('[URL]', $conversation->getErrorMessage());
    }
}
