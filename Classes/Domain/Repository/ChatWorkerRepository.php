<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Which `ai-chat:worker` processes are waiting for a turn (ADR-029).
 *
 * A worker reports itself while it is idle and takes its row away when it takes a
 * turn, so the table answers the one question a dispatch has: will a worker pick
 * this turn up soon? A worker that dies stops counting when its report expires.
 */
class ChatWorkerRepository
{
    private const TABLE = 'tx_nrmcpagent_chat_worker';

    /** A report counts this long. Above the heartbeat, so one late beat is no gap. */
    public const IDLE_SECONDS = 5;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function reportIdle(string $workerId): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $until      = time() + self::IDLE_SECONDS;
        if ($connection->update(self::TABLE, ['idle_until' => $until], ['worker_id' => $workerId]) === 0) {
            $connection->insert(self::TABLE, ['worker_id' => $workerId, 'idle_until' => $until]);
            // A new worker clears what crashed ones left: nothing else ends them.
            $connection->executeStatement(
                'DELETE FROM ' . $connection->quoteIdentifier(self::TABLE) . ' WHERE ' . $connection->quoteIdentifier('idle_until') . ' < ?',
                [time()],
                [Connection::PARAM_INT],
            );
        }
    }

    public function withdraw(string $workerId): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->delete(self::TABLE, ['worker_id' => $workerId]);
    }

    public function countIdle(): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        $count = $qb->count('worker_id')
            ->from(self::TABLE)
            ->where($qb->expr()->gte('idle_until', $qb->createNamedParameter(time(), Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }
}
