<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Rows the writes on one image reference are decided on (ADR-026): the
 * reference, its record and that record's page, as stored in the live
 * workspace, and the decorative mark as it was written.
 */
readonly class ImageReferenceRepository
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * A live, undeleted row; null when the uid names none. With `$versioned`
     * a workspace version counts as not there: it is another workspace's
     * draft.
     *
     * @return array<string, mixed>|null
     */
    public function findLive(string $table, int $uid, bool $versioned): ?array
    {
        if ($uid < 1) {
            return null;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $qb->select('*')->from($table)->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)));
        if ($versioned) {
            foreach (['t3ver_wsid', 't3ver_oid', 't3ver_state'] as $column) {
                $qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter(0, Connection::PARAM_INT)));
            }
        }

        $row = $qb->executeQuery()->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /** The reference's decorative mark as stored; null when there is no such row. */
    public function decorativeMark(int $referenceUid): ?bool
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        $value = $qb->select('tx_nrmcpagent_decorative')
            ->from('sys_file_reference')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($referenceUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value !== 0 : null;
    }
}
