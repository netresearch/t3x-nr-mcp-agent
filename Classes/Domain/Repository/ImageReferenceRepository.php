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

    /**
     * The content elements around one element: up to `$around` before and
     * after it in its column and language on its page, live and undeleted,
     * hidden ones included as the list includes them.
     *
     * @return array{before: list<array<string, mixed>>, after: list<array<string, mixed>>}
     */
    public function neighbourContent(int $pageUid, int $colPos, int $language, int $sorting, int $elementUid, int $around): array
    {
        $result = ['before' => [], 'after' => []];
        foreach (['before' => ['lt', 'DESC'], 'after' => ['gt', 'ASC']] as $side => [$comparison, $direction]) {
            $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
            $qb->getRestrictions()->removeAll()->add(new DeletedRestriction());
            $rows = $qb->select('uid', 'CType', 'header', 'bodytext')
                ->from('tt_content')
                ->where(
                    $qb->expr()->eq('pid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                    $qb->expr()->eq('colPos', $qb->createNamedParameter($colPos, Connection::PARAM_INT)),
                    $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($language, Connection::PARAM_INT)),
                    $qb->expr()->neq('uid', $qb->createNamedParameter($elementUid, Connection::PARAM_INT)),
                    $comparison === 'lt'
                        ? $qb->expr()->lt('sorting', $qb->createNamedParameter($sorting, Connection::PARAM_INT))
                        : $qb->expr()->gt('sorting', $qb->createNamedParameter($sorting, Connection::PARAM_INT)),
                    $qb->expr()->eq('t3ver_wsid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->orderBy('sorting', $direction)
                ->setMaxResults($around)
                ->executeQuery()
                ->fetchAllAssociative();
            $result[$side] = $side === 'before' ? array_reverse($rows) : $rows;
        }

        return $result;
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
