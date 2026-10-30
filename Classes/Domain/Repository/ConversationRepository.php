<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Domain\Repository;

use Doctrine\DBAL\Exception\DeadlockException;
use Netresearch\NrMcpAgent\Configuration\ImageAnalysisTurnSettings;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

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

    private const TRANSACTION_ATTEMPTS = 3;

    /** Prefix of a legacy transcript the upgrade wizard could not decode and left in place. */
    public const UNDECODABLE_MARKER = Conversation::UNDECODABLE_MARKER;

    public function __construct(
        private ConnectionPool $connectionPool,
        private ?ImageAnalysisTurnSettings $imageAnalysis = null,
    ) {}

    public function findByUid(int $uid): ?Conversation
    {
        $qb  = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
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
        'pinned', 'archived', 'error_message', 'error_code', 'approval_run_uuid',
        'approval_decision', 'approval_turn_digest', 'skill_identifier', 'tstamp', 'crdate',
        'title_source', 'last_message_at',
    ];

    /**
     * What a list of conversations needs to show where each one stands: the
     * page and skill it is about, the guided state and the image of an alt
     * text conversation (ConversationListPresenter). Small columns; the
     * transcript is not among them.
     */
    private const CONTEXT_COLUMNS = ['view_context', 'skill_uid', 'guided_state', 'alt_text_hit'];

    /**
     * The user's conversations with what a list shows about them
     * (CONTEXT_COLUMNS), most recently changed first. No transcript.
     *
     * @return list<Conversation>
     */
    public function findByBeUser(int $beUserUid, bool $includeArchived = false): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->select(...self::LIST_COLUMNS)
            ->addSelect(...self::CONTEXT_COLUMNS)
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('be_user', $qb->createNamedParameter($beUserUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            );
        $this->orderByLastActivity($qb);

        if (!$includeArchived) {
            $qb->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(0, Connection::PARAM_INT)));
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_map(Conversation::fromRow(...), $rows);
    }

    /**
     * The user's conversations that hold at least one message and are not
     * archived, most recently changed first, at most $limit — with what a list
     * shows about them (CONTEXT_COLUMNS). No transcript.
     *
     * @return list<Conversation>
     */
    public function findRecentWithMessagesByBeUser(int $beUserUid, int $limit): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->select(...self::LIST_COLUMNS)
            ->addSelect(...self::CONTEXT_COLUMNS)
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('be_user', $qb->createNamedParameter($beUserUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('archived', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->gt('message_count', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(max(1, $limit));
        $this->orderByLastActivity($qb);

        return array_map(Conversation::fromRow(...), $qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * Most recent last message first, as the lists show it (ADR-027): the
     * kept `last_message_at`, or the row's changed-at time for a row saved
     * before that column existed. Ordered by an alias of a CASE expression,
     * which SQLite, MySQL/MariaDB and PostgreSQL all accept.
     */
    private function orderByLastActivity(QueryBuilder $qb): void
    {
        $qb->addSelectLiteral(sprintf(
            'CASE WHEN %1$s > 0 THEN %1$s ELSE %2$s END AS %3$s',
            $qb->quoteIdentifier('last_message_at'),
            $qb->quoteIdentifier('tstamp'),
            $qb->quoteIdentifier('last_activity'),
        ))
            ->orderBy('last_activity', 'DESC')
            ->addOrderBy('uid', 'DESC');
    }

    /**
     * When the last message of each conversation was written, as a Unix
     * timestamp, keyed by conversation uid.
     *
     * Read from the message itself (`createdAt`): the message rows' crdate is
     * the time of the last save, which rewrites every row, and the
     * conversation's tstamp also moves when it is pinned, renamed or given a
     * skill. A conversation whose last message carries no time is missing
     * from the result.
     *
     * @param list<int> $conversationUids
     *
     * @return array<int, int>
     */
    public function findLastMessageTimes(array $conversationUids): array
    {
        if ($conversationUids === []) {
            return [];
        }

        $qb   = $this->connectionPool->getQueryBuilderForTable(self::MESSAGE_TABLE);
        $last = sprintf(
            '%s = (SELECT MAX(%s) FROM %s %s WHERE %s = %s)',
            $qb->quoteIdentifier('m.sorting'),
            $qb->quoteIdentifier('s.sorting'),
            $qb->quoteIdentifier(self::MESSAGE_TABLE),
            $qb->quoteIdentifier('s'),
            $qb->quoteIdentifier('s.conversation'),
            $qb->quoteIdentifier('m.conversation'),
        );
        $rows = $qb->select('m.conversation', 'm.payload')
            ->from(self::MESSAGE_TABLE, 'm')
            ->where(
                $qb->expr()->in('m.conversation', $qb->createNamedParameter($conversationUids, Connection::PARAM_INT_ARRAY)),
                $last,
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $times = [];
        foreach ($rows as $row) {
            $payload   = is_string($row['payload'] ?? null) ? json_decode($row['payload'], true) : null;
            $createdAt = is_array($payload) ? ($payload['createdAt'] ?? null) : null;
            $time      = is_string($createdAt) ? strtotime($createdAt) : false;
            $uid       = $row['conversation'] ?? null;
            if ($time !== false && is_numeric($uid)) {
                $times[(int) $uid] = $time;
            }
        }

        return $times;
    }

    public function findOneByUidAndBeUser(int $uid, int $beUserUid): ?Conversation
    {
        $qb  = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
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
        $qb          = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
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
        $conn             = $this->connectionPool->getConnectionForTable(self::TABLE);
        $data             = $this->rowData($conversation);
        $data['messages'] = '';
        $data['crdate']   = $data['tstamp'];
        $data['pid']      = 0;
        // A new row races nobody, so the instructions are written with it;
        // later only updateSystemPrompt() writes them (see toRow()).
        $data['system_prompt']    = $conversation->getSystemPrompt();
        $data['skill_identifier'] = $conversation->getSkillIdentifier();
        $data['skill_uid']        = $conversation->getSkillUid();
        $data['title']            = $conversation->getTitle();
        $data['title_source']     = $conversation->getTitleSource();

        return $this->transactional($conn, function () use ($conn, $data, $conversation): int {
            $conn->insert(self::TABLE, $data);
            $uid = (int) $conn->lastInsertId();
            $this->writeMessages($conn, $uid, $conversation->getDecodedMessages());

            return $uid;
        });
    }

    public function update(Conversation $conversation): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);

        $this->transactional($conn, function () use ($conn, $conversation): void {
            $conn->update(self::TABLE, $this->rowData($conversation), ['uid' => $conversation->getUid()]);
            $this->writeMessages($conn, $conversation->getUid(), $conversation->getDecodedMessages());
            $this->writeAutomaticTitle($conn, $conversation);
            $this->clearLegacyColumn($conn, $conversation->getUid());
        });
    }

    /**
     * The title a save carries — from the first message, an edit of it, an
     * image of the list of missing alt texts — unless the editor has renamed
     * the conversation since it was loaded: a rename during a running turn
     * survives the worker's final save (ADR-027).
     */
    private function writeAutomaticTitle(Connection $conn, Conversation $conversation): void
    {
        if ($conversation->isTitleManual()) {
            return;
        }

        $conn->executeStatement(
            'UPDATE ' . self::TABLE . ' SET title = ?, title_source = ? WHERE uid = ? AND title_source <> ?',
            [$conversation->getTitle(), $conversation->getTitleSource(), $conversation->getUid(), Conversation::TITLE_MANUAL],
        );
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
            'tstamp'   => time(),
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
     * Single-column write of where the conversation is (page, module and
     * language, as Conversation::setViewContext() keeps them), for a change
     * made outside a turn.
     */
    public function updateViewContext(Conversation $conversation, int $beUserUid): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $conn->update(self::TABLE, [
            'view_context' => $conversation->toRow()['view_context'],
            'tstamp'       => time(),
        ], ['uid' => $conversation->getUid(), 'be_user' => $beUserUid]);
    }

    /**
     * Lightweight title update — avoids reading/writing the full messages blob.
     */
    /**
     * The title the editor gave. It is marked as theirs, so no list replaces
     * it with a derived one (ADR-027).
     */
    public function updateTitle(int $uid, string $title, int $beUserUid): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $conn->update(self::TABLE, [
            'title'        => $title,
            'title_source' => Conversation::TITLE_MANUAL,
            'tstamp'       => time(),
        ], ['uid' => $uid, 'be_user' => $beUserUid]);
    }

    /**
     * Lightweight instructions update — avoids reading/writing the full messages blob.
     */
    /**
     * Single-column write of the conversation's skill (ADR-019), for the reason
     * updateSystemPrompt() gives. `$startPending` marks the next turn as the
     * start of the skill's process (nr-llm ADR-214 item 10).
     */
    public function updateSkillIdentifier(int $uid, string $skillIdentifier, int $beUserUid, int $skillUid = 0, bool $startPending = false): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $conn->update(self::TABLE, [
            'skill_identifier'    => $skillIdentifier,
            'skill_uid'           => $skillIdentifier !== '' ? $skillUid : 0,
            'skill_start_pending' => (int) ($skillIdentifier !== '' && $startPending),
            'tstamp'              => time(),
        ], ['uid' => $uid, 'be_user' => $beUserUid]);
    }

    /**
     * Single-column clear of the start marker, once a run carried the
     * invocation. Not part of a full-row write, so a save from a snapshot
     * taken before the skill was picked cannot clear a newer marker.
     */
    public function clearSkillStartPending(int $uid): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $conn->update(self::TABLE, ['skill_start_pending' => 0], ['uid' => $uid]);
    }

    public function updateSystemPrompt(int $uid, string $systemPrompt, int $beUserUid): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $conn->update(self::TABLE, [
            'system_prompt' => $systemPrompt,
            'tstamp'        => time(),
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
     * `about_image`: the conversation is about a missing alt text (ADR-026).
     *
     * @return array{status: string, message_count: int, error_message: string, error_code: string, approval_run_uuid: string, tstamp: int, activity: list<array<string, bool|int|string>>, about_image: bool}|null
     */
    public function findPollStatus(int $uid, int $beUserUid): ?array
    {
        $qb  = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('status', 'message_count', 'error_message', 'error_code', 'approval_run_uuid', 'tstamp', 'activity', 'alt_text_hit')
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

        $status          = $row['status'] ?? '';
        $messageCount    = $row['message_count'] ?? 0;
        $errorMessage    = $row['error_message'] ?? '';
        $errorCode       = $row['error_code'] ?? '';
        $approvalRunUuid = $row['approval_run_uuid'] ?? '';
        $tstamp          = $row['tstamp'] ?? 0;
        $activity        = $row['activity'] ?? '';

        if (is_int($messageCount)) {
            $messageCountInt = $messageCount;
        } else {
            $messageCountInt = is_string($messageCount) ? (int) $messageCount : 0;
        }

        return [
            'status'            => is_string($status) ? $status : '',
            'message_count'     => $messageCountInt,
            'error_message'     => is_string($errorMessage) ? $errorMessage : '',
            'error_code'        => is_string($errorCode) ? $errorCode : '',
            'approval_run_uuid' => is_string($approvalRunUuid) ? $approvalRunUuid : '',
            'tstamp'            => is_numeric($tstamp) ? (int) $tstamp : 0,
            'activity'          => Conversation::decodeActivity(is_string($activity) ? $activity : ''),
            'about_image'       => is_string($row['alt_text_hit'] ?? null) && $row['alt_text_hit'] !== '',
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
        $params  = [];
        foreach ($data as $col => $val) {
            $columns[] = $col . ' = ?';
            $params[]  = $val;
        }

        // WHERE uid = ? AND status = ? AND deleted = 0
        $params[] = $conversation->getUid();
        $params[] = $expectedStatus->value;

        $params[] = 0; // deleted

        // The claim and the transcript commit together: a claim that loses
        // leaves no message rows behind, and a worker cannot dequeue the
        // conversation before its new message is there.
        return $this->transactional($conn, function () use ($conn, $columns, $params, $conversation): bool {
            $affected = $conn->executeStatement(
                'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $columns)
                . ' WHERE uid = ? AND status = ? AND deleted = ?',
                $params,
            );
            if ($affected === 0) {
                // Lost: nothing was written, so there is nothing to undo.
                return false;
            }

            $this->writeMessages($conn, $conversation->getUid(), $conversation->getDecodedMessages());
            $this->writeAutomaticTitle($conn, $conversation);
            $this->clearLegacyColumn($conn, $conversation->getUid());

            return true;
        });
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

        return $this->findLockedBy($workerId);
    }

    /**
     * Atomically claim one given 'processing' conversation for `ai-chat:process`.
     *
     * The same claim dequeueForWorker() makes, for a known uid: one UPDATE that
     * moves the row from processing to locked only if it is still processing.
     * Whoever loses — a second process for the same turn, or a worker that
     * dequeued it first — updates zero rows and gets null.
     *
     * A deadlock counts as a lost claim too. MySQL reports one (SQLSTATE
     * 40001) when this UPDATE meets dequeueForWorker()'s on the same row; the
     * database has rolled this side back, and the row is either taken or
     * still 'processing' for the next worker poll.
     */
    public function claimForProcess(int $uid, string $claimId): ?Conversation
    {
        try {
            $affected = $this->connectionPool->getConnectionForTable(self::TABLE)->executeStatement(
                'UPDATE ' . self::TABLE . '
                 SET status = ?, current_request_id = ?
                 WHERE uid = ? AND status = ? AND deleted = ?',
                [
                    ConversationStatus::Locked->value,
                    $claimId,
                    $uid,
                    ConversationStatus::Processing->value,
                    0,
                ],
            );
        } catch (DeadlockException) {
            return null;
        }

        if ($affected === 0) {
            return null;
        }

        return $this->findLockedBy($claimId);
    }

    private function findLockedBy(string $claimId): ?Conversation
    {
        $qb  = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('current_request_id', $qb->createNamedParameter($claimId)),
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
        $messages     = $this->loadMessages($conversation->getUid());
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
        $qb       = $this->connectionPool->getQueryBuilderForTable(self::MESSAGE_TABLE);
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
                'pid'          => 0,
                'conversation' => $conversationUid,
                'sorting'      => $position,
                'role'         => is_string($role) ? mb_substr($role, 0, 20) : '',
                'payload'      => json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'crdate'       => $now,
            ]);
        }

        // What the lists order by, kept beside the rows so that a list never
        // reads a transcript (ADR-027). Same transaction as the rows.
        $conn->update(self::TABLE, ['last_message_at' => $this->lastMessageTime($messages, $now)], ['uid' => $conversationUid]);
    }

    /**
     * When the last of $messages was written: its `createdAt`, or $fallback
     * for a message without one; 0 without messages.
     *
     * @param list<array<string, mixed>> $messages
     */
    private function lastMessageTime(array $messages, int $fallback): int
    {
        if ($messages === []) {
            return 0;
        }

        $createdAt = $messages[array_key_last($messages)]['createdAt'] ?? null;
        $time      = is_string($createdAt) ? strtotime($createdAt) : false;

        return $time !== false && $time > 0 ? $time : $fallback;
    }

    /**
     * How many conversations with messages have no last message time yet:
     * rows saved before the column existed (LastMessageTimeUpdateWizard).
     */
    public function countWithoutLastMessageTime(): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $count = $qb->count('uid')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('last_message_at', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->gt('message_count', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Fill the last message time of up to $limit such rows with a uid above
     * $afterUid from their last message row (findLastMessageTimes()); a row
     * whose last message carries no time, or that has no message rows yet,
     * gets its changed-at time. Returns the highest uid of the batch, the
     * cursor for the next one, or 0 when there was none: a run walks the
     * table once instead of scanning it from the start for every batch.
     */
    public function backfillLastMessageTimes(int $limit, int $afterUid = 0): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid', 'tstamp')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('last_message_at', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->gt('message_count', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->gt('uid', $qb->createNamedParameter($afterUid, Connection::PARAM_INT)),
            )
            ->orderBy('uid')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();

        $uids  = array_map(static fn (array $row): int => is_numeric($row['uid'] ?? null) ? (int) $row['uid'] : 0, $rows);
        $times = $this->findLastMessageTimes($uids);
        $conn  = $this->connectionPool->getConnectionForTable(self::TABLE);
        foreach ($rows as $index => $row) {
            $uid    = $uids[$index];
            $tstamp = is_numeric($row['tstamp'] ?? null) ? (int) $row['tstamp'] : 0;
            $conn->update(self::TABLE, ['last_message_at' => max(1, $times[$uid] ?? $tstamp)], ['uid' => $uid, 'last_message_at' => 0]);
        }

        return $uids === [] ? 0 : max($uids);
    }

    /**
     * Run $work in a transaction, and run it again when the database picked it
     * as a deadlock victim. A lock-wait timeout is not retried: it has already
     * waited for innodb_lock_wait_timeout, and three of those would hold an
     * AJAX request for minutes.
     *
     * Replacing a conversation's message rows deletes a range of the unique
     * (conversation, sorting) index and inserts into it. Under REPEATABLE READ
     * (MySQL/MariaDB's default) the delete takes a gap lock even when the range
     * is empty, so two users writing the first messages of two new
     * conversations at the same moment deadlock — reproduced on MariaDB 11.4.
     * The database rolls the victim back and asks for a restart, which is what
     * this does; the work is a pure function of the conversation, so running it
     * again is safe.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function transactional(Connection $conn, callable $work): mixed
    {
        // Inside a caller's transaction this would only be a savepoint, and a
        // deadlock rolls back the whole outer transaction: restarting just the
        // inner part would run outside the transaction that was lost.
        $attempts = $conn->getTransactionNestingLevel() === 0 ? self::TRANSACTION_ATTEMPTS : 1;

        for ($attempt = 1;; ++$attempt) {
            $conn->beginTransaction();
            try {
                $result = $work();
                $conn->commit();

                return $result;
            } catch (DeadlockException $e) {
                $this->rollBackIfActive($conn);
                if ($attempt >= $attempts) {
                    throw $e;
                }

                // A short, growing, jittered pause: restarting at once tends
                // to meet the other transaction again.
                usleep(random_int(5, 25) * 1000 * $attempt);
            } catch (Throwable $e) {
                $this->rollBackIfActive($conn);
                throw $e;
            }
        }
    }

    /**
     * DBAL lowers the nesting level even when commit() itself throws, so after
     * a deadlock reported at COMMIT (Galera, for one) there is nothing left to
     * roll back — and rollBack() would throw instead of letting the retry run.
     */
    private function rollBackIfActive(Connection $conn): void
    {
        if ($conn->isTransactionActive()) {
            $conn->rollBack();
        }
    }

    /**
     * A transcript as the legacy column held it, or null when the value is
     * not a JSON list.
     *
     * @return list<array<string, mixed>>|null
     */
    private function decodeLegacyTranscript(string $blob): ?array
    {
        $decoded = json_decode($blob, true);
        if (!is_array($decoded)) {
            return null;
        }

        $messages = [];
        foreach ($decoded as $message) {
            if (is_array($message)) {
                /** @var array<string, mixed> $message */
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * Empty the legacy column once the transcript is in the message table —
     * unless it holds a value the wizard could not decode, which is kept for
     * inspection behind its marker.
     */
    private function clearLegacyColumn(Connection $conn, int $uid): void
    {
        $conn->executeStatement(
            'UPDATE ' . self::TABLE . " SET messages = '' WHERE uid = ? AND messages <> '' AND messages NOT LIKE ?",
            [$uid, self::UNDECODABLE_MARKER . '%'],
        );
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
        // A turn is handed to the worker by writing Processing, and only a
        // backend request writes it. The privacy setting "Bildanalyse" goes
        // with that write, read now, so the turn runs with the setting of
        // the request that started it, not with the one a long-running
        // worker loaded when it started (ImageAnalysisTurnSettings).
        if ($this->imageAnalysis instanceof ImageAnalysisTurnSettings && $conversation->getStatus() === ConversationStatus::Processing) {
            $conversation->setImageAnalysisSnapshot($this->imageAnalysis->snapshot());
        }

        $data = $conversation->toRow();
        // The transcript goes to the message table; the legacy column is
        // cleared by clearLegacyColumn(), which leaves a marked value alone.
        unset($data['messages']);
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
            ->where(
                $qb->expr()->neq('messages', $qb->createNamedParameter('')),
                $qb->expr()->notLike('messages', $qb->createNamedParameter($qb->escapeLikeWildcards(self::UNDECODABLE_MARKER) . '%')),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Move up to $limit legacy transcripts into the message table. Only the
     * uids are selected here; each transcript is read and moved in its own
     * transaction (moveLegacyTranscript()), so a batch never holds more than
     * one mediumtext value in memory. Returns the number of transcripts
     * moved: a row whose move did not happen is not counted, so a caller that
     * loops until nothing moves cannot spin on a row that keeps qualifying.
     */
    public function migrateLegacyTranscripts(int $limit): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $uids = $qb->select('uid')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->neq('messages', $qb->createNamedParameter('')),
                $qb->expr()->notLike('messages', $qb->createNamedParameter($qb->escapeLikeWildcards(self::UNDECODABLE_MARKER) . '%')),
            )
            ->orderBy('uid')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchFirstColumn();

        $moved = 0;
        foreach ($uids as $uid) {
            $uid = is_numeric($uid) ? (int) $uid : 0;
            $qb  = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $qb->getRestrictions()->removeAll();
            $blob = $qb->select('messages')
                ->from(self::TABLE)
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchOne();
            // False when the chat saved it in the meantime, which moved it
            // already, or when the conditional write matched nothing.
            if ($this->moveLegacyTranscript($uid, is_string($blob) ? $blob : '')) {
                ++$moved;
            }
        }

        return $moved;
    }

    /**
     * Move one legacy transcript, $blob being the value read for it.
     *
     * The claim is a conditional write: the column is cleared (or marked)
     * only if it still holds exactly $blob, and that write takes the row
     * lock before anything else happens. A chat save that got there first
     * has emptied the column, so the claim matches nothing and the save's
     * rows are left alone; a save that comes later waits for this
     * transaction. Without it, rows written by a save between reading $blob
     * and writing the table would be replaced by the older transcript.
     *
     * Returns false when the value had changed and nothing was done.
     *
     * @internal public for the tests that interleave it with a save
     */
    public function moveLegacyTranscript(int $uid, string $blob): bool
    {
        if ($blob === '' || str_starts_with($blob, self::UNDECODABLE_MARKER)) {
            return false;
        }

        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);

        return $this->transactional($conn, function () use ($conn, $uid, $blob): bool {
            $messages = $this->decodeLegacyTranscript($blob);
            $claim    = $messages === null
                // Not a JSON list: the conversation could not be opened
                // before either. The value is kept behind the marker, the
                // conversation shows as failed and archived instead of
                // erroring, and the wizard does not pick it up again.
                ? [
                    'messages'      => self::UNDECODABLE_MARKER . $blob,
                    'status'        => ConversationStatus::Failed->value,
                    'error_message' => 'The stored transcript of this conversation could not be read. It is kept unchanged for inspection.',
                    'archived'      => 1,
                    // The retention period of archived conversations counts
                    // from here, so an old row is not deleted with its kept
                    // value by the next cleanup run.
                    'tstamp' => time(),
                ]
                : ['messages' => ''];

            $affected = $conn->executeStatement(
                'UPDATE ' . self::TABLE . ' SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($claim)))
                . ' WHERE uid = ? AND messages = ?',
                [...array_values($claim), $uid, $blob],
            );
            if ($affected === 0) {
                return false;
            }

            // Rows the chat wrote later than the column are the newer
            // transcript; they win over the legacy value.
            if ($messages !== null && $this->loadMessages($uid) === []) {
                $this->writeMessages($conn, $uid, $messages);
            }

            return true;
        });
    }
}
