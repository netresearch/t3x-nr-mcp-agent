<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Domain\Repository;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * DBAL-based repository — no Extbase, direct QueryBuilder access.
 *
 * Messages live in their own table, one row per message (ADR-016). The
 * conversation model still carries the transcript as one list, so nothing
 * above this class knows: loading a conversation fills the list from the
 * message rows, saving one writes them back — together with the
 * conversation row, in one transaction, so a claim that fails leaves no
 * message behind and a worker never dequeues a conversation whose new
 * message is not there yet.
 *
 * Rows written before the table existed keep their transcript in the
 * `messages` column until the upgrade wizard moves it, and are read from
 * there as long as they have no message rows. The first save moves such a
 * transcript as a side effect.
 */
readonly class ConversationRepository
{
    private const TABLE = 'tx_nrmcpagent_conversation';

    private const MESSAGE_TABLE = 'tx_nrmcpagent_message';

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

        return $row !== false ? $this->hydrate($row) : null;
    }

    private const LIST_COLUMNS = [
        'uid', 'be_user', 'title', 'status', 'message_count',
        'pinned', 'archived', 'error_message', 'approval_run_uuid',
        'approval_decision', 'approval_turn_digest', 'tstamp', 'crdate',
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

        return $row !== false ? $this->hydrate($row) : null;
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
        if (is_int($fetchResult)) {
            return $fetchResult;
        }

        return is_string($fetchResult) ? (int) $fetchResult : 0;
    }

    public function add(Conversation $conversation): int
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $data = $this->rowData($conversation);
        $data['crdate'] = $data['tstamp'];
        $data['pid'] = 0;
        // A new row races nobody, so the instructions are written with it;
        // later only updateSystemPrompt() writes them (see toRow()).
        $data['system_prompt'] = $conversation->getSystemPrompt();

        $conn->beginTransaction();
        try {
            $conn->insert(self::TABLE, $data);
            $uid = (int) $conn->lastInsertId();
            $this->writeMessages($conn, $uid, $conversation->getDecodedMessages());
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        return $uid;
    }

    public function update(Conversation $conversation): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);

        $conn->beginTransaction();
        try {
            $conn->update(self::TABLE, $this->rowData($conversation), ['uid' => $conversation->getUid()]);
            $this->writeMessages($conn, $conversation->getUid(), $conversation->getDecodedMessages());
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
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
     * Lightweight title update — avoids reading/writing the full messages blob.
     */
    public function updateTitle(int $uid, string $title, int $beUserUid): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $conn->update(self::TABLE, [
            'title'  => $title,
            'tstamp' => time(),
        ], ['uid' => $uid, 'be_user' => $beUserUid]);
    }

    /**
     * Lightweight instructions update — avoids reading/writing the full messages blob.
     */
    public function updateSystemPrompt(int $uid, string $systemPrompt, int $beUserUid): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $conn->update(self::TABLE, [
            'system_prompt' => $systemPrompt,
            'tstamp' => time(),
        ], ['uid' => $uid, 'be_user' => $beUserUid]);
    }

    /**
     * Single-column write of the running turn's activity (NEXT-172). Called
     * from the worker while the turn runs, so it must not touch any other
     * column the turn's final write owns.
     */
    public function updateActivity(int $uid, string $activityJson): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $conn->update(self::TABLE, ['activity' => $activityJson], ['uid' => $uid]);
    }

    /**
     * Lightweight poll check — returns status metadata without loading messages.
     *
     * @return array{status: string, message_count: int, error_message: string, approval_run_uuid: string, tstamp: int, activity: list<array<string, bool|int|string>>}|null
     */
    public function findPollStatus(int $uid, int $beUserUid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('status', 'message_count', 'error_message', 'approval_run_uuid', 'tstamp', 'activity')
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
        $approvalRunUuid = $row['approval_run_uuid'] ?? '';
        $tstamp = $row['tstamp'] ?? 0;
        $activity = $row['activity'] ?? '';

        if (is_int($messageCount)) {
            $messageCountInt = $messageCount;
        } else {
            $messageCountInt = is_string($messageCount) ? (int) $messageCount : 0;
        }

        return [
            'status' => is_string($status) ? $status : '',
            'message_count' => $messageCountInt,
            'error_message' => is_string($errorMessage) ? $errorMessage : '',
            'approval_run_uuid' => is_string($approvalRunUuid) ? $approvalRunUuid : '',
            'tstamp' => is_numeric($tstamp) ? (int) $tstamp : 0,
            'activity' => Conversation::decodeActivity(is_string($activity) ? $activity : ''),
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
        $data = $this->rowData($conversation);

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

        // The claim and the transcript commit together: a claim that loses
        // leaves no message rows behind, and a worker cannot dequeue the
        // conversation before its new message is there.
        $conn->beginTransaction();
        try {
            $affected = $conn->executeStatement(
                'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $columns)
                . ' WHERE uid = ? AND status = ? AND deleted = ?',
                $params,
            );
            if ($affected === 0) {
                $conn->rollBack();

                return false;
            }

            $this->writeMessages($conn, $conversation->getUid(), $conversation->getDecodedMessages());
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Atomically claim one 'processing' conversation for a worker.
     * Uses UPDATE...LIMIT 1 with row-level locking to prevent race conditions.
     */
    public function dequeueForWorker(string $workerId): ?Conversation
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);

        // One statement, because two workers must not be able to claim the same
        // row: the WHERE clause re-checks the status the subquery selected on,
        // so a loser updates zero rows instead of stealing a claimed one.
        //
        // The inner query is wrapped in a derived table on purpose. Measured
        // against every database this extension can run on:
        //
        //   form                     SQLite 3.45  SQLite 3.51  MariaDB 11.8  MySQL 8.4
        //   UPDATE … ORDER BY LIMIT  ok           SYNTAX ERROR ok            ok
        //   … uid = (SELECT … )      ok           ok           ok            ERROR 1093
        //   … uid = (SELECT (SELECT  ok           ok           ok            ok
        //
        // UPDATE … ORDER BY … LIMIT needs SQLITE_ENABLE_UPDATE_DELETE_LIMIT,
        // which most builds do not set; the single-level subquery hits MySQL's
        // "can't specify target table for update in FROM clause". PostgreSQL 16
        // accepts the third form too.
        $affected = $conn->executeStatement(
            'UPDATE ' . self::TABLE . '
             SET status = ?, current_request_id = ?
             WHERE status = ? AND deleted = ? AND uid = (
                 SELECT uid FROM (
                     SELECT uid FROM ' . self::TABLE . '
                     WHERE status = ? AND deleted = ?
                     ORDER BY tstamp ASC
                     LIMIT 1
                 ) AS oldest
             )',
            [
                ConversationStatus::Locked->value,
                $workerId,
                ConversationStatus::Processing->value,
                0,
                ConversationStatus::Processing->value,
                0,
            ],
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

        return $this->hydrate($row);
    }

    /**
     * A conversation with its transcript: from the message rows, or — for a
     * row the upgrade wizard has not reached yet — from the legacy column.
     *
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Conversation
    {
        $conversation = Conversation::fromRow($row);
        $messages = $this->loadMessages($conversation->getUid());
        if ($messages !== []) {
            $conversation->setMessages($messages);
        }

        return $conversation;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadMessages(int $conversationUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::MESSAGE_TABLE);
        $payloads = $qb->select('payload')
            ->from(self::MESSAGE_TABLE)
            ->where($qb->expr()->eq('conversation', $qb->createNamedParameter($conversationUid, Connection::PARAM_INT)))
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        $messages = [];
        foreach ($payloads as $payload) {
            $message = is_string($payload) ? json_decode($payload, true) : null;
            if (is_array($message)) {
                /** @var array<string, mixed> $message */
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * Replace the conversation's message rows with the given transcript.
     *
     * Replace, not append: an edit truncates the transcript (NEXT-172), and
     * a transcript is a few dozen rows. Callers run inside the transaction
     * that also writes the conversation row.
     *
     * @param list<array<string, mixed>> $messages
     */
    private function writeMessages(Connection $conn, int $conversationUid, array $messages): void
    {
        $conn->delete(self::MESSAGE_TABLE, ['conversation' => $conversationUid]);
        $now = time();
        foreach ($messages as $position => $message) {
            $role = $message['role'] ?? '';
            $conn->insert(self::MESSAGE_TABLE, [
                'pid' => 0,
                'conversation' => $conversationUid,
                'sorting' => $position,
                'role' => is_string($role) ? mb_substr($role, 0, 20) : '',
                'payload' => json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'crdate' => $now,
            ]);
        }
    }

    /**
     * The conversation row as written: everything the model serialises, the
     * transcript excepted — that goes to the message table, and the legacy
     * column is emptied so it can never be read in place of the rows.
     *
     * @return array<string, int|string>
     */
    private function rowData(Conversation $conversation): array
    {
        $data = $conversation->toRow();
        $data['messages'] = '';
        $data['tstamp'] = time();

        return $data;
    }

    /**
     * How many conversations still keep their transcript in the legacy
     * column — what the upgrade wizard has left to do.
     */
    public function countLegacyTranscripts(): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $count = $qb->count('uid')
            ->from(self::TABLE)
            ->where($qb->expr()->neq('messages', $qb->createNamedParameter('')))
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Move up to $limit legacy transcripts into the message table, one
     * conversation per transaction. A conversation that already has message
     * rows keeps them — they were written later than the column — and only
     * loses the stale copy. Returns the number of conversations handled.
     */
    public function migrateLegacyTranscripts(int $limit): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid', 'messages')
            ->from(self::TABLE)
            ->where($qb->expr()->neq('messages', $qb->createNamedParameter('')))
            ->orderBy('uid')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        foreach ($rows as $row) {
            $uid = is_numeric($row['uid'] ?? null) ? (int) $row['uid'] : 0;
            $blob = is_string($row['messages'] ?? null) ? $row['messages'] : '';

            $conn->beginTransaction();
            try {
                if ($this->loadMessages($uid) === []) {
                    $decoded = json_decode($blob, true);
                    $messages = [];
                    foreach (is_array($decoded) ? $decoded : [] as $message) {
                        if (is_array($message)) {
                            /** @var array<string, mixed> $message */
                            $messages[] = $message;
                        }
                    }

                    $this->writeMessages($conn, $uid, $messages);
                }

                $conn->update(self::TABLE, ['messages' => ''], ['uid' => $uid]);
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollBack();
                throw $e;
            }
        }

        return count($rows);
    }
}
