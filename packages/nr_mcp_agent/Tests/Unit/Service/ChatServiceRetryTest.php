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
use Netresearch\NrMcpAgent\Mcp\McpToolProviderInterface;
use Netresearch\NrMcpAgent\Service\ChatService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

class ChatServiceRetryTest extends TestCase
{
    #[Test]
    public function retriesOnTransient429Error(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage('user', 'Hello');

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $callCount = 0;
        $llmManager->method('chatWithTools')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new RuntimeException('429 Too Many Requests');
            }
            return new CompletionResponse(
                content: 'Hi!',
                model: 'test',
                usage: new UsageStatistics(10, 20, 30),
            );
        });

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame(2, $callCount);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function doesNotRetryOnNonTransientError(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage('user', 'Hello');

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatWithTools')->willThrowException(
            new RuntimeException('Invalid API key'),
        );

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function errorMessageIsSanitized(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage('user', 'Hello');

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatWithTools')->willThrowException(
            new RuntimeException('Error calling https://api.anthropic.com/v1/messages with Bearer sk-ant-api03-secretkey123: 500'),
        );

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
        $service->processConversation($conversation);

        self::assertStringNotContainsString('sk-ant', $conversation->getErrorMessage());
        self::assertStringNotContainsString('anthropic.com', $conversation->getErrorMessage());
        self::assertStringContainsString('[REDACTED]', $conversation->getErrorMessage());

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function retriesOnOverloadedError(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage('user', 'Hello');

        $callCount = 0;
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatWithTools')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new RuntimeException('The server is overloaded');
            }
            return new CompletionResponse(
                content: 'Hi!',
                model: 'test',
                usage: new UsageStatistics(10, 20, 30),
            );
        });

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame(2, $callCount);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function retriesOn503Error(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage('user', 'Hello');

        $callCount = 0;
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatWithTools')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new RuntimeException('503 Service Unavailable');
            }
            return new CompletionResponse(
                content: 'Hi!',
                model: 'test',
                usage: new UsageStatistics(10, 20, 30),
            );
        });

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame(2, $callCount);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function exhaustsAllRetriesOnPersistentTransientError(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage('user', 'Hello');

        $callCount = 0;
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatWithTools')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            throw new RuntimeException('429 Too Many Requests');
        });

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        // MAX_LLM_RETRIES = 2, so 3 attempts total (0, 1, 2)
        self::assertSame(3, $callCount);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function sanitizesKeyPrefixPattern(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage('user', 'Hello');

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatWithTools')->willThrowException(
            new RuntimeException('Error with key-abc123def456 token'),
        );

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
        $service->processConversation($conversation);

        self::assertStringNotContainsString('key-abc123def456', $conversation->getErrorMessage());
        self::assertStringContainsString('[REDACTED]', $conversation->getErrorMessage());

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function errorMessageTruncatedAt500Chars(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage('user', 'Hello');

        $longError = str_repeat('a', 600);
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatWithTools')->willThrowException(new RuntimeException($longError));

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
        $service->processConversation($conversation);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertLessThanOrEqual(500, mb_strlen($conversation->getErrorMessage()));

        unset($GLOBALS['BE_USER']);
    }
}
