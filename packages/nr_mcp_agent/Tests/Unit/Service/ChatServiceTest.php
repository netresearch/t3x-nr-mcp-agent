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

    #[Test]
    public function processConversationSetsIdleOnSimpleResponse(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->expects(self::once())->method('chatWithTools')
            ->willReturn($this->createCompletionResponse('Hi there!'));

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
        self::assertSame(2, $conversation->getMessageCount());

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function processConversationSetsFailedWhenNoLlmTaskConfigured(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(0);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
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

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->expects(self::never())->method('chatWithTools');

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
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

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(0);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
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

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatWithTools')
            ->willReturn($this->createCompletionResponse('Hello!'));

        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::once())
            ->method('updateStatus')
            ->with(42, ConversationStatus::Processing);

        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
        $service->processConversation($conversation);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function buildSystemPromptReturnsGermanForDeLocale(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hallo');

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatWithTools')->willReturnCallback(
            function ($messages, $tools, $options) {
                return $this->createCompletionResponse('Hallo!');
            },
        );

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'de'];

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
        $service->processConversation($conversation);

        // Verify conversation completed (system prompt is private, but it was used)
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

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatWithTools')->willReturnCallback(
            function ($messages, $tools, $options) {
                return $this->createCompletionResponse('Hi!');
            },
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

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function buildSystemPromptPassesGermanPromptToLlm(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hallo');

        $capturedOptions = null;
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatWithTools')->willReturnCallback(
            function ($messages, $tools, $options) use (&$capturedOptions) {
                $capturedOptions = $options;
                return $this->createCompletionResponse('Hallo!');
            },
        );

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'de'];

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
        $service->processConversation($conversation);

        self::assertNotNull($capturedOptions);
        self::assertStringContainsString('Deutsch', $capturedOptions->getSystemPrompt());
        self::assertStringContainsString('TYPO3-Assistent', $capturedOptions->getSystemPrompt());

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function buildSystemPromptPassesCustomPromptToLlm(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setSystemPrompt('My custom system prompt');
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $capturedOptions = null;
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatWithTools')->willReturnCallback(
            function ($messages, $tools, $options) use (&$capturedOptions) {
                $capturedOptions = $options;
                return $this->createCompletionResponse('Hi!');
            },
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

        self::assertNotNull($capturedOptions);
        self::assertSame('My custom system prompt', $capturedOptions->getSystemPrompt());

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function processConversationDisconnectsMcpOnSuccess(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatWithTools')
            ->willReturn($this->createCompletionResponse('Hi!'));

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);
        $mcpProvider->expects(self::once())->method('disconnect');

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
        $service->processConversation($conversation);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function processConversationDisconnectsMcpOnError(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatWithTools')
            ->willThrowException(new RuntimeException('LLM exploded'));

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);
        $mcpProvider->expects(self::once())->method('disconnect');

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
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
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatWithTools')
            ->willReturn($this->createCompletionResponse('Hello!'));

        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('updateStatus')->willReturnCallback(
            function (int $uid, ConversationStatus $status) use (&$statusUpdates): void {
                $statusUpdates[] = ['uid' => $uid, 'status' => $status];
            },
        );

        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
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

        $capturedOptions = null;
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatWithTools')->willReturnCallback(
            function ($messages, $tools, $options) use (&$capturedOptions) {
                $capturedOptions = $options;
                return $this->createCompletionResponse('Hi!');
            },
        );

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);

        // No BE_USER set
        unset($GLOBALS['BE_USER']);

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
        $service->processConversation($conversation);

        self::assertNotNull($capturedOptions);
        self::assertStringContainsString('English', $capturedOptions->getSystemPrompt());
        self::assertStringContainsString('TYPO3 assistant', $capturedOptions->getSystemPrompt());
    }
}
