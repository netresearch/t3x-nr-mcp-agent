<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Domain\Repository;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Replacing message rows deadlocks under REPEATABLE READ when two new
 * conversations are written at once (reproduced on MariaDB 11.4). The
 * database rolls the victim back and asks for a restart; the repository
 * restarts it (ADR-016).
 */
final class ConversationRepositoryRetryTest extends TestCase
{
    private function deadlock(): DeadlockException
    {
        return new DeadlockException($this->createMock(DriverException::class), null);
    }

    private function claim(Connection $connection): bool
    {
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);
        $conversation = Conversation::fromRow(['uid' => 7, 'be_user' => 1, 'status' => 'processing']);

        return (new ConversationRepository($pool))->updateIf($conversation, ConversationStatus::Idle);
    }

    #[Test]
    public function aDeadlockedClaimIsRunAgain(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(1);
        $commits = 0;
        $connection->method('commit')->willReturnCallback(function () use (&$commits): void {
            if (++$commits === 1) {
                throw $this->deadlock();
            }
        });
        $connection->expects(self::exactly(2))->method('beginTransaction');
        $connection->expects(self::once())->method('rollBack');

        self::assertTrue($this->claim($connection));
    }

    #[Test]
    public function aDeadlockThatKeepsHappeningIsReported(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(1);
        $connection->method('commit')->willThrowException($this->deadlock());
        $connection->expects(self::exactly(3))->method('beginTransaction');
        $connection->expects(self::exactly(3))->method('rollBack');

        $this->expectException(DeadlockException::class);
        $this->claim($connection);
    }

    #[Test]
    public function anyOtherErrorIsNotRetried(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willThrowException(new RuntimeException('no such column'));
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('rollBack');

        $this->expectException(RuntimeException::class);
        $this->claim($connection);
    }
}
