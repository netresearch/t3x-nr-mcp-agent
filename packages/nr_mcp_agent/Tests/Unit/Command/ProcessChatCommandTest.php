<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Command;

use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrMcpAgent\Command\ProcessChatCommand;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Mcp\McpToolProviderInterface;
use Netresearch\NrMcpAgent\Service\ChatService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use TYPO3\CMS\Core\Database\ConnectionPool;

class ProcessChatCommandTest extends TestCase
{
    private function createChatService(): ChatService
    {
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(0);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);

        return new ChatService($llmManager, $repository, $config, $mcpProvider);
    }

    #[Test]
    public function executeFailsWhenConversationNotFound(): void
    {
        $chatService = $this->createChatService();
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('findByUid')->willReturn(null);
        $connectionPool = $this->createMock(ConnectionPool::class);

        $command = new ProcessChatCommand($chatService, $repository, $connectionPool);

        $input = new ArrayInput(['conversationUid' => '999']);
        $input->bind($command->getDefinition());

        $output = new BufferedOutput();
        $result = $command->run($input, $output);

        self::assertSame(1, $result);
        self::assertStringContainsString('not found', $output->fetch());
    }

    #[Test]
    public function executeFailsWhenConversationNotInProcessingState(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 1,
            'be_user' => 1,
            'status' => 'idle',
            'messages' => '[]',
            'message_count' => 0,
        ]);

        $chatService = $this->createChatService();
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('findByUid')->willReturn($conversation);
        $connectionPool = $this->createMock(ConnectionPool::class);

        $command = new ProcessChatCommand($chatService, $repository, $connectionPool);

        $input = new ArrayInput(['conversationUid' => '1']);
        $input->bind($command->getDefinition());

        $output = new BufferedOutput();
        $result = $command->run($input, $output);

        self::assertSame(1, $result);
        self::assertStringContainsString('not in processing state', $output->fetch());
    }

    #[Test]
    public function executeFailsWhenConversationInFailedState(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 2,
            'be_user' => 1,
            'status' => 'failed',
            'messages' => '[]',
            'message_count' => 0,
        ]);

        $chatService = $this->createChatService();
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('findByUid')->willReturn($conversation);
        $connectionPool = $this->createMock(ConnectionPool::class);

        $command = new ProcessChatCommand($chatService, $repository, $connectionPool);

        $input = new ArrayInput(['conversationUid' => '2']);
        $input->bind($command->getDefinition());

        $output = new BufferedOutput();
        $result = $command->run($input, $output);

        self::assertSame(1, $result);
        self::assertStringContainsString('not in processing state', $output->fetch());
    }

    #[Test]
    public function executeFailsWhenConversationIsLocked(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 3,
            'be_user' => 1,
            'status' => 'locked',
            'messages' => '[]',
            'message_count' => 0,
        ]);

        $chatService = $this->createChatService();
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('findByUid')->willReturn($conversation);
        $connectionPool = $this->createMock(ConnectionPool::class);

        $command = new ProcessChatCommand($chatService, $repository, $connectionPool);

        $input = new ArrayInput(['conversationUid' => '3']);
        $input->bind($command->getDefinition());

        $output = new BufferedOutput();
        $result = $command->run($input, $output);

        self::assertSame(1, $result);
    }
}
