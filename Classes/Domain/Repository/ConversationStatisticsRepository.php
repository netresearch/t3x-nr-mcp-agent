<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Domain\Repository;

use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Counts for the overview module. Read-only, and never a message: the counts
 * carry no content, and the only titles it returns are those of the user who
 * asked ({@see findWaitingForUser()}). Only conversations with a message
 * count, as only those are listed ({@see conversations()}).
 *
 * Every method takes the backend user to narrow to; null counts every user,
 * which only an administrator is shown.
 */
readonly class ConversationStatisticsRepository
{
    private const TABLE = 'tx_nrmcpagent_conversation';

    private const OPEN_POINT_TABLE = 'tx_nrmcpagent_open_point';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Conversations that are neither archived nor deleted, per status.
     *
     * @return array<string, int> status value → count, sorted by status
     */
    public function countByStatus(?int $beUserUid): array
    {
        $qb = $this->conversations($beUserUid);
        $qb->select('status')
            ->addSelectLiteral('COUNT(*) AS cnt')
            ->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(0, Connection::PARAM_INT)))
            ->groupBy('status')
            ->orderBy('status');

        $counts = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            if (is_string($row['status'] ?? null) && is_numeric($row['cnt'] ?? null)) {
                $counts[$row['status']] = (int) $row['cnt'];
            }
        }

        return $counts;
    }

    public function countArchived(?int $beUserUid): int
    {
        $qb = $this->conversations($beUserUid);
        $qb->count('uid')
            ->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(1, Connection::PARAM_INT)));

        return $this->int($qb->executeQuery()->fetchOne());
    }

    /** Conversations created between the two Unix times, both included. */
    public function countStarted(int $from, int $to, ?int $beUserUid): int
    {
        $qb = $this->startedBetween($from, $to, $beUserUid);
        $qb->count('uid');

        return $this->int($qb->executeQuery()->fetchOne());
    }

    /**
     * Of those, the ones that have a skill now: guided tours and skill tasks.
     * The column holds the current skill, so an ended tour no longer counts.
     */
    public function countStartedWithSkill(int $from, int $to, ?int $beUserUid): int
    {
        $qb = $this->startedBetween($from, $to, $beUserUid);
        $qb->count('uid')
            ->andWhere($qb->expr()->neq('skill_identifier', $qb->createNamedParameter('')));

        return $this->int($qb->executeQuery()->fetchOne());
    }

    /**
     * Failed conversations per error code (never the message, which can quote
     * content).
     *
     * @return array<string, int>
     */
    public function countFailedByErrorCode(?int $beUserUid): array
    {
        $qb = $this->conversations($beUserUid);
        $qb->select('error_code')
            ->addSelectLiteral('COUNT(*) AS cnt')
            ->andWhere(
                $qb->expr()->eq('status', $qb->createNamedParameter(ConversationStatus::Failed->value)),
                $qb->expr()->eq('archived', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->groupBy('error_code')
            ->orderBy('error_code');

        $counts = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            if (is_string($row['error_code'] ?? null) && is_numeric($row['cnt'] ?? null)) {
                $counts[$row['error_code']] = (int) $row['cnt'];
            }
        }

        return $counts;
    }

    /** Open points a guided process recorded and nothing has closed yet. */
    public function countOpenPoints(?int $beUserUid): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::OPEN_POINT_TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->count('uid')->from(self::OPEN_POINT_TABLE);
        if ($beUserUid !== null) {
            $qb->where($qb->expr()->eq('be_user', $qb->createNamedParameter($beUserUid, Connection::PARAM_INT)));
        }

        return $this->int($qb->executeQuery()->fetchOne());
    }

    /**
     * The user's own conversations that wait for them (approval or input),
     * newest first.
     *
     * @return list<array{uid: int, title: string, status: string}>
     */
    public function findWaitingForUser(int $beUserUid, int $limit): array
    {
        $qb = $this->conversations($beUserUid);
        $qb->select('uid', 'title', 'status')
            ->andWhere(
                $qb->expr()->in('status', $qb->createNamedParameter(
                    [ConversationStatus::AwaitingApproval->value, ConversationStatus::AwaitingInput->value],
                    Connection::PARAM_STR_ARRAY,
                )),
                $qb->expr()->eq('archived', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            // Most recent last message first, as the other lists (ADR-027):
            // the kept last_message_at, or tstamp for an older row.
            ->addSelectLiteral(sprintf(
                'CASE WHEN %1$s > 0 THEN %1$s ELSE %2$s END AS %3$s',
                $qb->quoteIdentifier('last_message_at'),
                $qb->quoteIdentifier('tstamp'),
                $qb->quoteIdentifier('last_activity'),
            ))
            ->orderBy('last_activity', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults($limit);

        $rows = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $rows[] = [
                'uid'    => $this->int($row['uid'] ?? 0),
                'title'  => is_string($row['title'] ?? null) ? $row['title'] : '',
                'status' => is_string($row['status'] ?? null) ? $row['status'] : '',
            ];
        }

        return $rows;
    }

    private function startedBetween(int $from, int $to, ?int $beUserUid): QueryBuilder
    {
        $qb = $this->conversations($beUserUid);
        $qb->andWhere(
            $qb->expr()->gte('crdate', $qb->createNamedParameter($from, Connection::PARAM_INT)),
            $qb->expr()->lte('crdate', $qb->createNamedParameter($to, Connection::PARAM_INT)),
        );

        return $qb;
    }

    /**
     * Not deleted, with at least one message, and of one user when one is
     * given. A conversation without a message is listed nowhere (ADR-027),
     * so the user can neither open nor archive it; counted, it would be a
     * number the lists do not show.
     */
    private function conversations(?int $beUserUid): QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->from(self::TABLE)
            ->where(
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->gt('message_count', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            );
        if ($beUserUid !== null) {
            $qb->andWhere($qb->expr()->eq('be_user', $qb->createNamedParameter($beUserUid, Connection::PARAM_INT)));
        }

        return $qb;
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
