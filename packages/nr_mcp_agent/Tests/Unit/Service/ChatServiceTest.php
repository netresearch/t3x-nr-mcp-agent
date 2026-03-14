<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Mcp\McpToolProvider;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Dto\ChatResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ChatServiceTest extends TestCase
{
    #[Test]
    public function processConversationSetsIdleOnSimpleResponse(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage('user', 'Hello');

        $chatResponse = $this->createMock(ChatResponse::class);
        $chatResponse->method('hasToolCalls')->willReturn(false);
        $chatResponse->method('getContent')->willReturn('Hi there!');
        $chatResponse->toolCalls = [];

        $llmManager = $this->createMock(LlmServiceManager::class);
        $llmManager->expects(self::once())->method('chatWithTools')->willReturn($chatResponse);

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProvider::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);

        // Mock BE_USER for system prompt
        $GLOBALS['BE_USER'] = new \stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = new ChatService(
            $llmManager, $repository, $config, $mcpProvider
        );
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
        $conversation->appendMessage('user', 'Hello');

        $llmManager = $this->createMock(LlmServiceManager::class);
        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(0);
        $mcpProvider = $this->createMock(McpToolProvider::class);

        $service = new ChatService(
            $llmManager, $repository, $config, $mcpProvider
        );
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

        $llmManager = $this->createMock(LlmServiceManager::class);
        $llmManager->expects(self::never())->method('chatWithTools');

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createMock(ExtensionConfiguration::class);
        $mcpProvider = $this->createMock(McpToolProvider::class);

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

        $llmManager = $this->createMock(LlmServiceManager::class);
        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(0);
        $mcpProvider = $this->createMock(McpToolProvider::class);

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

        $chatResponse = $this->createMock(ChatResponse::class);
        $chatResponse->method('hasToolCalls')->willReturn(false);
        $chatResponse->method('getContent')->willReturn('Hello!');
        $chatResponse->toolCalls = [];

        $llmManager = $this->createMock(LlmServiceManager::class);
        $llmManager->method('chatWithTools')->willReturn($chatResponse);

        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::once())
            ->method('updateStatus')
            ->with(42, ConversationStatus::Processing);

        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProvider::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);

        $GLOBALS['BE_USER'] = new \stdClass();
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
        $conversation->appendMessage('user', 'Hallo');

        $chatResponse = $this->createMock(ChatResponse::class);
        $chatResponse->method('hasToolCalls')->willReturn(false);
        $chatResponse->method('getContent')->willReturn('Hallo!');
        $chatResponse->toolCalls = [];

        $llmManager = $this->createMock(LlmServiceManager::class);
        $llmManager->method('chatWithTools')->willReturnCallback(
            function ($messages, $tools, $options, int $taskUid, string $systemPrompt) use ($chatResponse) {
                // Assert the German system prompt was passed
                self::assertStringContainsString('TYPO3-Assistent', $systemPrompt);
                return $chatResponse;
            }
        );

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProvider::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);

        $GLOBALS['BE_USER'] = new \stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'de'];

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
        $service->processConversation($conversation);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function buildSystemPromptUsesCustomPromptWhenSet(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->setSystemPrompt('Custom system prompt');
        $conversation->appendMessage('user', 'Hello');

        $chatResponse = $this->createMock(ChatResponse::class);
        $chatResponse->method('hasToolCalls')->willReturn(false);
        $chatResponse->method('getContent')->willReturn('Hi!');
        $chatResponse->toolCalls = [];

        $llmManager = $this->createMock(LlmServiceManager::class);
        $llmManager->method('chatWithTools')->willReturnCallback(
            function ($messages, $tools, $options, int $taskUid, string $systemPrompt) use ($chatResponse) {
                self::assertSame('Custom system prompt', $systemPrompt);
                return $chatResponse;
            }
        );

        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $mcpProvider = $this->createMock(McpToolProvider::class);
        $mcpProvider->method('getToolDefinitions')->willReturn([]);

        $GLOBALS['BE_USER'] = new \stdClass();
        $GLOBALS['BE_USER']->uc = ['lang' => 'default'];

        $service = new ChatService($llmManager, $repository, $config, $mcpProvider);
        $service->processConversation($conversation);

        unset($GLOBALS['BE_USER']);
    }
}
