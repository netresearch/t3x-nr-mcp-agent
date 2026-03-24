<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Command;

use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrMcpAgent\Command\ChatWorkerCommand;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Domain\Repository\LlmTaskRepository;
use Netresearch\NrMcpAgent\Mcp\McpToolProviderInterface;
use Netresearch\NrMcpAgent\Service\ChatService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

class ChatWorkerCommandTest extends TestCase
{
    #[Test]
    public function classHasAsCommandAttribute(): void
    {
        $reflection = new ReflectionClass(ChatWorkerCommand::class);
        $attributes = $reflection->getAttributes(AsCommand::class);

        self::assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        self::assertSame('ai-chat:worker', $instance->name);
        self::assertSame('Long-running worker that processes chat conversations from queue', $instance->description);
    }

    #[Test]
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(ChatWorkerCommand::class);
        self::assertTrue($reflection->isFinal());
    }

    #[Test]
    public function constructorAcceptsCorrectDependencies(): void
    {
        $reflection = new ReflectionClass(ChatWorkerCommand::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor);
        $parameters = $constructor->getParameters();
        self::assertCount(3, $parameters);
        self::assertSame('chatService', $parameters[0]->getName());
        self::assertSame('repository', $parameters[1]->getName());
        self::assertSame('connectionPool', $parameters[2]->getName());

        self::assertSame(ChatService::class, $parameters[0]->getType()?->getName());
        self::assertSame(ConversationRepository::class, $parameters[1]->getType()?->getName());
        self::assertSame(ConnectionPool::class, $parameters[2]->getType()?->getName());
    }

    #[Test]
    public function configureAddsPollIntervalOption(): void
    {
        $command = $this->createWorkerCommand();
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasOption('poll-interval'));

        $option = $definition->getOption('poll-interval');
        self::assertTrue($option->isValueOptional());
        self::assertSame(200, $option->getDefault());
    }

    #[Test]
    public function commandExtendsSymfonyCommand(): void
    {
        $reflection = new ReflectionClass(ChatWorkerCommand::class);
        self::assertTrue($reflection->isSubclassOf(Command::class));
    }

    #[Test]
    public function executeMethodExists(): void
    {
        $reflection = new ReflectionClass(ChatWorkerCommand::class);
        self::assertTrue($reflection->hasMethod('execute'));

        $method = $reflection->getMethod('execute');
        self::assertTrue($method->isProtected());
    }

    #[Test]
    public function pollIntervalOptionDescription(): void
    {
        $command = $this->createWorkerCommand();
        $option = $command->getDefinition()->getOption('poll-interval');

        self::assertSame('Poll interval in milliseconds', $option->getDescription());
    }

    private function createWorkerCommand(): ChatWorkerCommand
    {
        $chatRepository = $this->createMock(ConversationRepository::class);
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

        $chatService = new ChatService($chatRepository, $config, $mcpProvider, $llmTaskRepository, $adapterRegistry, $this->createMock(ResourceFactory::class), $this->createMock(SiteFinder::class));
        $repository = $this->createMock(ConversationRepository::class);
        $connectionPool = $this->createMock(ConnectionPool::class);

        return new ChatWorkerCommand($chatService, $repository, $connectionPool);
    }
}
