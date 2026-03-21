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
readonly class ConversationRepository
{
    private const TABLE = 'tx_nrmcpagent_conversation';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function findByUid(int $uid): ?Conversation
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? Conversation::fromRow($row) : null;
    }

    private const LIST_COLUMNS = [
        'uid', 'be_user', 'title', 'status', 'message_count',
        'pinned', 'archived', 'error_message', 'tstamp', 'crdate',
    ];

    /** @return list<Conversation> */
    public function findByBeUser(int $beUserUid, bool $includeArchived = false): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->select(...self::LIST_COLUMNS)
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('be_user', $qb->createNamedParameter($beUserUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('tstamp', 'DESC');

        if (!$includeArchived) {
            $qb->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(0, Connection::PARAM_INT)));
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
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? Conversation::fromRow($row) : null;
    }

    public function countActiveByBeUser(int $beUserUid): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $fetchResult = $qb->count('uid')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('be_user', $qb->createNamedParameter($beUserUid, Connection::PARAM_INT)),
                $qb->expr()->in('status', $qb->createNamedParameter(
                    [ConversationStatus::Processing->value, ConversationStatus::Locked->value, ConversationStatus::ToolLoop->value],
                    Connection::PARAM_STR_ARRAY,
                )),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();
        return is_int($fetchResult) ? $fetchResult : (is_string($fetchResult) ? (int) $fetchResult : 0);
    }

    public function add(Conversation $conversation): int
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $data = $conversation->toRow();
        $data['crdate'] = $data['tstamp'] = time();
        $data['pid'] = 0;
        $conn->insert(self::TABLE, $data);
        return (int) $conn->lastInsertId();
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
    public function updateStatus(int $uid, ConversationStatus $status, int $beUserUid): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $conn->update(self::TABLE, [
            'status' => $status->value,
            'tstamp' => time(),
        ], ['uid' => $uid, 'be_user' => $beUserUid]);
    }

    /**
     * Lightweight flag update — avoids reading/writing the full messages blob.
     */
    public function updateArchived(int $uid, bool $archived, int $beUserUid): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $conn->update(self::TABLE, [
            'archived' => (int) $archived,
            'tstamp' => time(),
        ], ['uid' => $uid, 'be_user' => $beUserUid]);
    }

    /**
     * Lightweight flag update — avoids reading/writing the full messages blob.
     */
    public function updatePinned(int $uid, bool $pinned, int $beUserUid): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $conn->update(self::TABLE, [
            'pinned' => (int) $pinned,
            'tstamp' => time(),
        ], ['uid' => $uid, 'be_user' => $beUserUid]);
    }

    /**
     * Lightweight poll check — returns status metadata without loading messages.
     *
     * @return array{status: string, message_count: int, error_message: string}|null
     */
    public function findPollStatus(int $uid, int $beUserUid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('status', 'message_count', 'error_message')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)),
                $qb->expr()->eq('be_user', $qb->createNamedParameter($beUserUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $status = $row['status'] ?? '';
        $messageCount = $row['message_count'] ?? 0;
        $errorMessage = $row['error_message'] ?? '';

        return [
            'status' => is_string($status) ? $status : '',
            'message_count' => is_int($messageCount) ? $messageCount : (int) (is_string($messageCount) ? $messageCount : 0),
            'error_message' => is_string($errorMessage) ? $errorMessage : '',
        ];
    }

    /**
     * Atomic Compare-And-Swap: writes the full conversation row only if the
     * current DB status matches $expectedStatus. Prevents race conditions
     * where a worker could claim the row between a status change and the data write.
     *
     * Returns true if the row was updated (status matched), false otherwise.
     */
    public function updateIf(Conversation $conversation, ConversationStatus $expectedStatus): bool
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $data = $conversation->toRow();
        $data['tstamp'] = time();

        $columns = [];
        $params = [];
        foreach ($data as $col => $val) {
            $columns[] = $col . ' = ?';
            $params[] = $val;
        }
        // WHERE uid = ? AND status = ? AND deleted = 0
        $params[] = $conversation->getUid();
        $params[] = $expectedStatus->value;

        $params[] = 0; // deleted

        $affected = $conn->executeStatement(
            'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $columns)
            . ' WHERE uid = ? AND status = ? AND deleted = ?',
            $params,
        );
        return $affected > 0;
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
             SET status = ?, current_request_id = ?
             WHERE status = ? AND deleted = ?
             ORDER BY tstamp ASC LIMIT 1',
            [ConversationStatus::Locked->value, $workerId, ConversationStatus::Processing->value, 0],
        );

        if ($affected === 0) {
            return null;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('current_request_id', $qb->createNamedParameter($workerId)),
                $qb->expr()->eq('status', $qb->createNamedParameter(ConversationStatus::Locked->value)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return Conversation::fromRow($row);
    }
}
