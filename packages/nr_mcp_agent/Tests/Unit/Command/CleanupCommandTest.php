<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Command;

use Netresearch\NrMcpAgent\Command\CleanupCommand;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;

class CleanupCommandTest extends TestCase
{
    #[Test]
    public function classHasAsCommandAttribute(): void
    {
        $reflection = new ReflectionClass(CleanupCommand::class);
        $attributes = $reflection->getAttributes(AsCommand::class);

        self::assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        self::assertSame('ai-chat:cleanup', $instance->name);
        self::assertSame('Clean up stuck, inactive and archived conversations', $instance->description);
    }

    #[Test]
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(CleanupCommand::class);
        self::assertTrue($reflection->isFinal());
    }

    #[Test]
    public function constructorRequiresConnectionPoolAndExtensionConfiguration(): void
    {
        $reflection = new ReflectionClass(CleanupCommand::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor);
        $parameters = $constructor->getParameters();
        self::assertCount(2, $parameters);
        self::assertSame('connectionPool', $parameters[0]->getName());
        self::assertSame('extensionConfiguration', $parameters[1]->getName());
    }

    #[Test]
    public function configureAddsDeleteAfterDaysOption(): void
    {
        [$command] = $this->createCommand([0]);
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasOption('delete-after-days'));

        $option = $definition->getOption('delete-after-days');
        self::assertTrue($option->isValueOptional());
        self::assertSame('90', $option->getDefault());
    }

    #[Test]
    public function executeTimeoutsStuckConversations(): void
    {
        // autoArchiveDays=0 skips archive -> 2 DB calls: timeout(3) + delete(0)
        [$command, , $extensionConfig] = $this->createCommand([3, 0]);
        $extensionConfig->method('getAutoArchiveDays')->willReturn(0);

        $input = new ArrayInput([]);
        $input->bind($command->getDefinition());
        $output = new BufferedOutput();

        $result = $command->run($input, $output);

        self::assertSame(0, $result);
        $text = $output->fetch();
        self::assertStringContainsString('Timed out 3 stuck conversation(s)', $text);
        self::assertStringContainsString('Timed out stuck conversations: 3', $text);
    }

    #[Test]
    public function executeAutoArchivesInactiveConversations(): void
    {
        // autoArchiveDays=30 -> 3 DB calls: timeout(0) + archive(5) + delete(0)
        [$command, , $extensionConfig] = $this->createCommand([0, 5, 0]);
        $extensionConfig->method('getAutoArchiveDays')->willReturn(30);

        $input = new ArrayInput([]);
        $input->bind($command->getDefinition());
        $output = new BufferedOutput();

        $result = $command->run($input, $output);

        self::assertSame(0, $result);
        $text = $output->fetch();
        self::assertStringContainsString('Auto-archived 5 inactive conversation(s)', $text);
        self::assertStringContainsString('Auto-archived inactive conversations: 5', $text);
    }

    #[Test]
    public function executeSkipsArchiveWhenAutoArchiveDaysIsZero(): void
    {
        // autoArchiveDays=0 skips archive -> 2 DB calls: timeout(0) + delete(0)
        [$command, , $extensionConfig] = $this->createCommand([0, 0]);
        $extensionConfig->method('getAutoArchiveDays')->willReturn(0);

        $input = new ArrayInput([]);
        $input->bind($command->getDefinition());
        $output = new BufferedOutput();

        $result = $command->run($input, $output);

        self::assertSame(0, $result);
        $text = $output->fetch();
        self::assertStringContainsString('Auto-archived inactive conversations: 0', $text);
    }

    #[Test]
    public function executeDeletesOldArchivedConversations(): void
    {
        // autoArchiveDays=0 skips archive -> 2 DB calls: timeout(0) + delete(7)
        [$command, , $extensionConfig] = $this->createCommand([0, 7]);
        $extensionConfig->method('getAutoArchiveDays')->willReturn(0);

        $input = new ArrayInput([]);
        $input->bind($command->getDefinition());
        $output = new BufferedOutput();

        $result = $command->run($input, $output);

        self::assertSame(0, $result);
        $text = $output->fetch();
        self::assertStringContainsString('Deleted 7 old archived conversation(s)', $text);
        self::assertStringContainsString('Deleted old archived conversations: 7', $text);
    }

    #[Test]
    public function executeSkipsDeleteWhenDeleteAfterDaysIsZero(): void
    {
        // autoArchiveDays=0 skips archive, delete-after-days=0 skips delete -> 1 DB call: timeout(0)
        [$command, , $extensionConfig] = $this->createCommand([0]);
        $extensionConfig->method('getAutoArchiveDays')->willReturn(0);

        $input = new ArrayInput(['--delete-after-days' => '0']);
        $input->bind($command->getDefinition());
        $output = new BufferedOutput();

        $result = $command->run($input, $output);

        self::assertSame(0, $result);
        $text = $output->fetch();
        self::assertStringContainsString('Deleted old archived conversations: 0', $text);
    }

    #[Test]
    public function executeShowsCleanupSummary(): void
    {
        // autoArchiveDays=30 -> 3 DB calls: timeout(2) + archive(4) + delete(6)
        [$command, , $extensionConfig] = $this->createCommand([2, 4, 6]);
        $extensionConfig->method('getAutoArchiveDays')->willReturn(30);

        $input = new ArrayInput([]);
        $input->bind($command->getDefinition());
        $output = new BufferedOutput();

        $result = $command->run($input, $output);

        self::assertSame(0, $result);
        $text = $output->fetch();
        self::assertStringContainsString('Cleanup summary:', $text);
        self::assertStringContainsString('Timed out stuck conversations: 2', $text);
        self::assertStringContainsString('Auto-archived inactive conversations: 4', $text);
        self::assertStringContainsString('Deleted old archived conversations: 6', $text);
    }

    #[Test]
    public function executeReturnsSuccessCode(): void
    {
        // autoArchiveDays=0 skips archive -> 2 DB calls: timeout(0) + delete(0)
        [$command, , $extensionConfig] = $this->createCommand([0, 0]);
        $extensionConfig->method('getAutoArchiveDays')->willReturn(0);

        $input = new ArrayInput([]);
        $input->bind($command->getDefinition());
        $output = new BufferedOutput();

        $result = $command->run($input, $output);

        self::assertSame(0, $result);
    }

    /**
     * @param list<int> $executeStatementResults ordered return values for each getQueryBuilderForTable call
     * @return array{0: CleanupCommand, 1: ConnectionPool&MockObject, 2: ExtensionConfiguration&MockObject}
     */
    private function createCommand(array $executeStatementResults): array
    {
        $callIndex = 0;

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturnCallback(
            function () use (&$callIndex, $executeStatementResults): QueryBuilder {
                $currentIndex = $callIndex++;

                $expressionBuilder = $this->createMock(ExpressionBuilder::class);
                $expressionBuilder->method('in')->willReturn('');
                $expressionBuilder->method('lt')->willReturn('');
                $expressionBuilder->method('eq')->willReturn('');

                $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
                $restrictions->method('removeAll')->willReturnSelf();

                $queryBuilder = $this->createMock(QueryBuilder::class);
                $queryBuilder->method('getRestrictions')->willReturn($restrictions);
                $queryBuilder->method('expr')->willReturn($expressionBuilder);
                $queryBuilder->method('update')->willReturnSelf();
                $queryBuilder->method('delete')->willReturnSelf();
                $queryBuilder->method('set')->willReturnSelf();
                $queryBuilder->method('where')->willReturnSelf();
                $queryBuilder->method('createNamedParameter')->willReturn('?');
                $queryBuilder->method('executeStatement')->willReturn($executeStatementResults[$currentIndex] ?? 0);

                return $queryBuilder;
            },
        );

        $extensionConfig = $this->createMock(ExtensionConfiguration::class);

        $command = new CleanupCommand($connectionPool, $extensionConfig);

        return [$command, $connectionPool, $extensionConfig];
    }
}
