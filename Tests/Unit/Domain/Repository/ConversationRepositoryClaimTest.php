<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Domain\Repository;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * claimForProcess() and a deadlock. MySQL answers SQLSTATE 40001 when the
 * claim meets a worker's dequeue on the same row; the claim is then lost,
 * not an error.
 */
final class ConversationRepositoryClaimTest extends TestCase
{
    private function repository(Connection $connection): ConversationRepository
    {
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        return new ConversationRepository($pool);
    }

    #[Test]
    public function aDeadlockIsALostClaim(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->willThrowException(new DeadlockException($this->createMock(DriverException::class), null));

        self::assertNull($this->repository($connection)->claimForProcess(2, 'process_1'));
    }

    #[Test]
    public function aLockWaitTimeoutIsStillAnError(): void
    {
        // Only the deadlock is a race with a known outcome; a lock-wait timeout
        // says the database is stuck, and the caller should hear about it.
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')
            ->willThrowException(new LockWaitTimeoutException($this->createMock(DriverException::class), null));

        $this->expectException(LockWaitTimeoutException::class);
        $this->repository($connection)->claimForProcess(2, 'process_1');
    }
}
