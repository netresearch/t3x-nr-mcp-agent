<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Domain\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Netresearch\NrMcpAgent\Service\OpenPoint\OpenPointTarget;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Open points of guided processes (nr-llm ADR-214, item 9; ADR-022): a
 * proposal the editor skipped, kept beyond the conversation.
 *
 * One row per field of the skipped write, keyed by the process skill, the
 * subject record and the target record and field. Only identities are
 * stored — table names, uids, field names — never a value or the proposal's
 * text. An applied write to the same record and field closes the row.
 */
readonly class OpenPointRepository
{
    private const TABLE = 'tx_nrmcpagent_open_point';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Record a skipped write. A field that is already open under the same
     * key stays one open point.
     */
    public function record(int $skillUid, string $subjectTable, int $subjectUid, OpenPointTarget $target, int $beUser, int $conversation): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        foreach ($target->fieldKeys() as $field) {
            $key = [
                'skill_uid'     => $skillUid,
                'subject_table' => $subjectTable,
                'subject_uid'   => $subjectUid,
                'target_table'  => $target->table,
                'target_uid'    => $target->uid,
                'target_field'  => $field,
            ];

            try {
                $conn->insert(self::TABLE, $key + [
                    'be_user'      => $beUser,
                    'conversation' => $conversation,
                    'crdate'       => time(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Already open under this key: nothing to add.
            }
        }
    }

    /**
     * Close every open point on the target's record and fields, whichever
     * process recorded it. Returns how many were closed.
     */
    public function close(OpenPointTarget $target): int
    {
        $closed = 0;
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        foreach ($target->fieldKeys() as $field) {
            $closed += $conn->delete(self::TABLE, [
                'target_table' => $target->table,
                'target_uid'   => $target->uid,
                'target_field' => $field,
            ]);
        }

        return $closed;
    }

    /**
     * The open points about one subject record, oldest first; of one process
     * skill when `$skillUid` is above 0.
     *
     * @return list<array{skillUid: int, targetTable: string, targetUid: int, field: string, crdate: int}>
     */
    public function findBySubject(string $subjectTable, int $subjectUid, int $skillUid = 0, int $limit = 50): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->select('skill_uid', 'target_table', 'target_uid', 'target_field', 'crdate')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('subject_table', $qb->createNamedParameter($subjectTable)),
                $qb->expr()->eq('subject_uid', $qb->createNamedParameter($subjectUid, Connection::PARAM_INT)),
            )
            ->orderBy('crdate')
            ->addOrderBy('uid')
            ->setMaxResults($limit);
        if ($skillUid > 0) {
            $qb->andWhere($qb->expr()->eq('skill_uid', $qb->createNamedParameter($skillUid, Connection::PARAM_INT)));
        }

        $points = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $points[] = [
                'skillUid'    => $this->int($row['skill_uid'] ?? 0),
                'targetTable' => is_string($row['target_table'] ?? null) ? $row['target_table'] : '',
                'targetUid'   => $this->int($row['target_uid'] ?? 0),
                'field'       => is_string($row['target_field'] ?? null) ? $row['target_field'] : '',
                'crdate'      => $this->int($row['crdate'] ?? 0),
            ];
        }

        return $points;
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
