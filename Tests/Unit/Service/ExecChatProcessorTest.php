<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Exception\Typo3CliBinaryNotFoundException;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use Netresearch\NrMcpAgent\Service\ExecChatProcessor;
use Netresearch\NrMcpAgent\Service\Typo3CliBinaryResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

class ExecChatProcessorTest extends TestCase
{
    /**
     * Environment's static state, restored after each test: the suite has no
     * TYPO3 unit-test bootstrap, so UnitTestCase::$backupEnvironment cannot be used.
     *
     * @var array<string, mixed>
     */
    private array $environmentBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->environmentBackup = (new ReflectionClass(Environment::class))->getStaticProperties();
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();
        parent::tearDown();
    }

    #[Test]
    public function implementsChatProcessorInterface(): void
    {
        $reflection = new ReflectionClass(ExecChatProcessor::class);
        self::assertTrue($reflection->implementsInterface(ChatProcessorInterface::class));
    }

    #[Test]
    public function classIsFinalAndReadonly(): void
    {
        $reflection = new ReflectionClass(ExecChatProcessor::class);
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadonly());
    }

    #[Test]
    public function dispatchMethodExistsWithCorrectSignature(): void
    {
        $reflection = new ReflectionClass(ExecChatProcessor::class);
        self::assertTrue($reflection->hasMethod('dispatch'));

        $method = $reflection->getMethod('dispatch');
        self::assertTrue($method->isPublic());

        $parameters = $method->getParameters();
        self::assertCount(1, $parameters);
        self::assertSame('conversationUid', $parameters[0]->getName());
        self::assertSame('int', $parameters[0]->getType()?->getName());

        $returnType = $method->getReturnType();
        self::assertNotNull($returnType);
        self::assertSame('void', $returnType->getName());
    }

    #[Test]
    public function constructorTakesTheBinaryResolverTheRepositoryAndALogger(): void
    {
        $constructor = (new ReflectionClass(ExecChatProcessor::class))->getConstructor();
        self::assertNotNull($constructor);

        $types = array_map(
            static fn(ReflectionParameter $p): string => (string) $p->getType(),
            $constructor->getParameters(),
        );
        self::assertSame(
            [Typo3CliBinaryResolver::class, ConversationRepository::class, LoggerInterface::class],
            $types,
        );
    }

    #[Test]
    public function commandRunsTheBinaryTheResolverFound(): void
    {
        $this->initializeEnvironment('/srv/site');
        $processor = $this->processor($this->resolverReturning('/srv/site/.Build/bin/typo3'));

        self::assertSame(
            "'" . PHP_BINARY . "' '/srv/site/.Build/bin/typo3' ai-chat:process 42 >> '/srv/site/var/log/ai-chat-process.log' 2>&1 &",
            $processor->buildCommand(42),
        );
    }

    #[Test]
    public function commandQuotesPathsThatCarryShellSyntax(): void
    {
        $this->initializeEnvironment("/srv/it's; touch pwned");
        $processor = $this->processor($this->resolverReturning('/opt/a b/$(id)/typo3'));

        $command = $processor->buildCommand(7);

        self::assertStringContainsString(" '/opt/a b/\$(id)/typo3' ai-chat:process 7 ", $command);
        self::assertStringEndsWith(
            " >> '/srv/it'\\''s; touch pwned/var/log/ai-chat-process.log' 2>&1 &",
            $command,
        );
    }

    #[Test]
    public function missingBinaryIsLoggedAndFailsTheClaimedConversation(): void
    {
        $exception = new Typo3CliBinaryNotFoundException('The TYPO3 console binary was not found at "/x/vendor/bin/typo3"');
        $resolver = $this->createMock(Typo3CliBinaryResolver::class);
        $resolver->method('resolve')->willThrowException($exception);

        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Processing);

        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('findByUid')->with(42)->willReturn($conversation);
        $repository->expects(self::once())
            ->method('updateIf')
            ->with($conversation, ConversationStatus::Processing)
            ->willReturn(true);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('not started'),
                self::callback(static fn(array $context): bool => $context['uid'] === 42
                    && $context['exception'] === $exception
                    && str_contains((string) $context['reason'], '/x/vendor/bin/typo3')),
            );

        (new ExecChatProcessor($resolver, $repository, $logger))->dispatch(42);

        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertSame(ExecChatProcessor::WORKER_NOT_STARTED_MESSAGE, $conversation->getErrorMessage());
    }

    #[Test]
    public function missingBinaryLeavesAConversationAloneThatIsNoLongerProcessing(): void
    {
        $resolver = $this->createMock(Typo3CliBinaryResolver::class);
        $resolver->method('resolve')->willThrowException(new Typo3CliBinaryNotFoundException('missing'));

        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Idle);

        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('findByUid')->willReturn($conversation);
        $repository->expects(self::never())->method('updateIf');
        $repository->expects(self::never())->method('update');

        (new ExecChatProcessor($resolver, $repository, $this->createMock(LoggerInterface::class)))->dispatch(42);

        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
    }

    private function resolverReturning(string $path): Typo3CliBinaryResolver&MockObject
    {
        $resolver = $this->createMock(Typo3CliBinaryResolver::class);
        $resolver->method('resolve')->willReturn($path);

        return $resolver;
    }

    private function processor(Typo3CliBinaryResolver $resolver): ExecChatProcessor
    {
        return new ExecChatProcessor(
            $resolver,
            $this->createMock(ConversationRepository::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function initializeEnvironment(string $projectPath): void
    {
        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            $projectPath,
            $projectPath . '/public',
            $projectPath . '/var',
            $projectPath . '/config',
            $projectPath . '/vendor/bin/typo3',
            'UNIX',
        );
    }

    private function restoreEnvironment(): void
    {
        foreach ($this->environmentBackup as $name => $value) {
            (new ReflectionProperty(Environment::class, $name))->setValue(null, $value);
        }
    }
}
