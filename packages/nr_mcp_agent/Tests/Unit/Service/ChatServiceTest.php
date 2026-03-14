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
}
