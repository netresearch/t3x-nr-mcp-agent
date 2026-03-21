<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Command;

use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrMcpAgent\Command\ProcessChatCommand;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Domain\Repository\LlmTaskRepository;
use Netresearch\NrMcpAgent\Mcp\McpToolProviderInterface;
use Netresearch\NrMcpAgent\Service\ChatService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;

class ProcessChatCommandTest extends TestCase
{
    private function createChatService(): ChatService
    {
        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(0);
        $config->method('isMcpEnabled')->willReturn(false);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);

        $llmTaskRepository = $this->createMock(LlmTaskRepository::class);
        $llmTaskRepository->method('resolveModelByTaskUid')->willReturn([
            'model' => $this->createMock(LlmModel::class),
            'systemPrompt' => '',
            'promptTemplate' => '',
        ]);

        $adapterRegistry = $this->createMock(ProviderAdapterRegistry::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn($this->createMock(ProviderInterface::class));

        return new ChatService($repository, $config, $mcpProvider, $llmTaskRepository, $adapterRegistry, $this->createMock(ResourceFactory::class));
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

    #[Test]
    public function classHasAsCommandAttribute(): void
    {
        $reflection = new ReflectionClass(ProcessChatCommand::class);
        $attributes = $reflection->getAttributes(AsCommand::class);

        self::assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        self::assertSame('ai-chat:process', $instance->name);
        self::assertSame('Process a single chat conversation', $instance->description);
    }

    #[Test]
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(ProcessChatCommand::class);
        self::assertTrue($reflection->isFinal());
    }

    #[Test]
    public function configureAddsConversationUidArgument(): void
    {
        $chatService = $this->createChatService();
        $repository = $this->createMock(ConversationRepository::class);
        $connectionPool = $this->createMock(ConnectionPool::class);

        $command = new ProcessChatCommand($chatService, $repository, $connectionPool);
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasArgument('conversationUid'));
        self::assertTrue($definition->getArgument('conversationUid')->isRequired());
    }

    #[Test]
    public function constructorAcceptsCorrectDependencies(): void
    {
        $reflection = new ReflectionClass(ProcessChatCommand::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor);
        $parameters = $constructor->getParameters();
        self::assertCount(3, $parameters);
        self::assertSame('chatService', $parameters[0]->getName());
        self::assertSame('repository', $parameters[1]->getName());
        self::assertSame('connectionPool', $parameters[2]->getName());
    }

    #[Test]
    public function executeFailsWhenConversationIsToolLoop(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 4,
            'be_user' => 1,
            'status' => 'tool_loop',
            'messages' => '[]',
            'message_count' => 0,
        ]);

        $chatService = $this->createChatService();
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('findByUid')->willReturn($conversation);
        $connectionPool = $this->createMock(ConnectionPool::class);

        $command = new ProcessChatCommand($chatService, $repository, $connectionPool);

        $input = new ArrayInput(['conversationUid' => '4']);
        $input->bind($command->getDefinition());

        $output = new BufferedOutput();
        $result = $command->run($input, $output);

        self::assertSame(1, $result);
        self::assertStringContainsString('not in processing state', $output->fetch());
    }

    #[Test]
    public function conversationWithPendingToolCallsIsDetected(): void
    {
        // Verify the hasPendingToolCalls logic that execute() uses for branching
        $toolCallMessages = json_encode([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'tc_1', 'function' => ['name' => 'test']]]],
        ]);

        $conversation = Conversation::fromRow([
            'uid' => 30,
            'be_user' => 1,
            'status' => 'processing',
            'messages' => $toolCallMessages,
            'message_count' => 2,
        ]);

        self::assertTrue($conversation->hasPendingToolCalls());
    }

    #[Test]
    public function conversationWithoutToolCallsIsNotDetectedAsPending(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 31,
            'be_user' => 1,
            'status' => 'processing',
            'messages' => '[{"role":"user","content":"Hello"}]',
            'message_count' => 1,
        ]);

        self::assertFalse($conversation->hasPendingToolCalls());
    }
}
