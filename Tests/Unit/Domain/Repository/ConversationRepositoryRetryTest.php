<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Domain\Repository;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Replacing message rows deadlocks under REPEATABLE READ when two new
 * conversations are written at once (reproduced on MariaDB 11.4). The
 * database rolls the victim back and asks for a restart; the repository
 * restarts it (ADR-016).
 *
 * The connection double tracks DBAL's nesting level the way DBAL 4 does:
 * commit() lowers it even when it throws, and rollBack() without an active
 * transaction fails.
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
    public function aStatementDeadlockIsRunAgain(): void
    {
        $level = 0;
        $connection = $this->trackingConnection($level);
        $calls = 0;
        $connection->method('executeStatement')->willReturnCallback(function (string $sql) use (&$calls): int {
            if (!str_contains($sql, 'AND status = ?')) {
                return 0; // clearing the legacy column
            }
            if (++$calls === 1) {
                throw $this->deadlock();
            }

            return 1;
        });
        $connection->method('commit')->willReturnCallback(static function () use (&$level): void {
            --$level;
        });

        self::assertTrue($this->claim($connection));
        self::assertSame(2, $calls);
        self::assertSame(0, $level);
    }

    /**
     * Galera reports a certification conflict at COMMIT. DBAL has already
     * lowered the nesting level then; rolling back would throw instead of
     * retrying.
     */
    #[Test]
    public function aDeadlockAtCommitIsRunAgain(): void
    {
        $level = 0;
        $connection = $this->trackingConnection($level);
        $connection->method('executeStatement')->willReturn(1);
        $commits = 0;
        $connection->method('commit')->willReturnCallback(function () use (&$level, &$commits): void {
            --$level;
            if (++$commits === 1) {
                throw $this->deadlock();
            }
        });

        self::assertTrue($this->claim($connection));
        self::assertSame(2, $commits);
    }

    #[Test]
    public function aDeadlockThatKeepsHappeningIsReported(): void
    {
        $level = 0;
        $begins = 0;
        $connection = $this->trackingConnection($level, $begins);
        $connection->method('executeStatement')->willThrowException($this->deadlock());

        try {
            $this->claim($connection);
            self::fail('The deadlock must reach the caller after the last attempt.');
        } catch (DeadlockException) {
            self::assertSame(3, $begins);
            self::assertSame(0, $level);
        }
    }

    /** It has already waited innodb_lock_wait_timeout; three more would hold the request for minutes. */
    #[Test]
    public function aLockWaitTimeoutIsNotRetried(): void
    {
        $level = 0;
        $begins = 0;
        $connection = $this->trackingConnection($level, $begins);
        $connection->method('executeStatement')->willThrowException(
            new LockWaitTimeoutException($this->createMock(DriverException::class), null),
        );

        try {
            $this->claim($connection);
            self::fail('A lock-wait timeout must reach the caller.');
        } catch (LockWaitTimeoutException) {
            self::assertSame(1, $begins);
        }
    }

    /** Inside a caller's transaction a deadlock has rolled back more than this part. */
    #[Test]
    public function insideAnOuterTransactionADeadlockIsNotRetried(): void
    {
        $level = 1;
        $begins = 0;
        $connection = $this->trackingConnection($level, $begins);
        $connection->method('executeStatement')->willThrowException($this->deadlock());

        try {
            $this->claim($connection);
            self::fail('The deadlock must reach the caller.');
        } catch (DeadlockException) {
            self::assertSame(1, $begins);
            self::assertSame(1, $level, 'the outer transaction is the caller\'s to end');
        }
    }

    #[Test]
    public function anyOtherErrorIsNotRetried(): void
    {
        $level = 0;
        $begins = 0;
        $connection = $this->trackingConnection($level, $begins);
        $connection->method('executeStatement')->willThrowException(new RuntimeException('no such column'));

        try {
            $this->claim($connection);
            self::fail('The error must reach the caller.');
        } catch (RuntimeException $e) {
            self::assertSame('no such column', $e->getMessage());
            self::assertSame(1, $begins);
            self::assertSame(0, $level);
        }
    }

    /**
     * A connection double whose nesting level lives in $level, shared with
     * the test so commit() can lower it the way DBAL does.
     */
    private function trackingConnection(int &$level, int &$begins = 0): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level): int {
            return $level;
        });
        $connection->method('isTransactionActive')->willReturnCallback(static function () use (&$level): bool {
            return $level > 0;
        });
        $connection->method('beginTransaction')->willReturnCallback(static function () use (&$level, &$begins): void {
            ++$level;
            ++$begins;
        });
        $connection->method('rollBack')->willReturnCallback(static function () use (&$level): void {
            if ($level === 0) {
                throw new RuntimeException('There is no active transaction.');
            }
            --$level;
        });

        return $connection;
    }
}
