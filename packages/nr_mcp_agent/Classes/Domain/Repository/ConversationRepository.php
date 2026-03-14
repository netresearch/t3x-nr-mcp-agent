<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Domain\Repository;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * DBAL-based repository — no Extbase, direct QueryBuilder access.
 */
final class ConversationRepository
{
    private const TABLE = 'tx_nrmcpagent_conversation';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function findByUid(int $uid): ?Conversation
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? Conversation::fromRow($row) : null;
    }

    public function findByBeUser(int $beUserUid, bool $includeArchived = false): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('be_user', $qb->createNamedParameter($beUserUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', 0),
            )
            ->orderBy('tstamp', 'DESC');

        if (!$includeArchived) {
            $qb->andWhere($qb->expr()->eq('archived', 0));
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map(Conversation::fromRow(...), $rows);
    }

    public function findOneByUidAndBeUser(int $uid, int $beUserUid): ?Conversation
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)),
                $qb->expr()->eq('be_user', $qb->createNamedParameter($beUserUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? Conversation::fromRow($row) : null;
    }

    public function countActiveByBeUser(int $beUserUid): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return (int)$qb->count('uid')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('be_user', $qb->createNamedParameter($beUserUid, Connection::PARAM_INT)),
                $qb->expr()->in('status', $qb->createNamedParameter(
                    ['processing', 'locked', 'tool_loop'],
                    Connection::PARAM_STR_ARRAY,
                )),
                $qb->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchOne();
    }

    public function add(Conversation $conversation): int
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $data = $conversation->toRow();
        $data['crdate'] = $data['tstamp'] = time();
        $data['pid'] = 0;
        $conn->insert(self::TABLE, $data);
        return (int)$conn->lastInsertId();
    }

    public function update(Conversation $conversation): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $data = $conversation->toRow();
        $data['tstamp'] = time();
        $conn->update(self::TABLE, $data, ['uid' => $conversation->getUid()]);
    }

    /**
     * Lightweight status-only update — avoids writing the full messages blob.
     */
    public function updateStatus(int $uid, ConversationStatus $status): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $conn->update(self::TABLE, [
            'status' => $status->value,
            'tstamp' => time(),
        ], ['uid' => $uid]);
    }

    /**
     * Atomically claim one 'processing' conversation for a worker.
     * Uses UPDATE...LIMIT 1 with row-level locking to prevent race conditions.
     */
    public function dequeueForWorker(string $workerId): ?Conversation
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);

        $affected = $conn->executeStatement(
            'UPDATE ' . self::TABLE . '
             SET status = \'locked\', current_request_id = ?
             WHERE status = \'processing\' AND deleted = 0
             ORDER BY tstamp ASC LIMIT 1',
            [$workerId]
        );

        if ($affected === 0) {
            return null;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('current_request_id', $qb->createNamedParameter($workerId)),
                $qb->expr()->eq('status', $qb->createNamedParameter('locked')),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return Conversation::fromRow($row);
    }
}
