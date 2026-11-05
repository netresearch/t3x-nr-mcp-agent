<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Domain\Repository;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Rows the writes on one image reference are decided on (ADR-026): the
 * reference, its record and that record's page, as stored in the live
 * workspace or as the editor's draft workspace shows them (ADR-032), and the
 * decorative mark as it was written.
 */
readonly class ImageReferenceRepository
{
    /** t3ver_state of a record deleted in a workspace. */
    private const DELETE_PLACEHOLDER = 2;

    /** How many elements of a page a workspace's neighbours are picked from. */
    private const NEIGHBOUR_CANDIDATES = 500;

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
     * after it in its column and language on its page, undeleted, hidden ones
     * included as the list includes them. In live the live rows; in a draft
     * workspace the rows as that workspace shows them (ADR-032).
     *
     * @return array{before: list<array<string, mixed>>, after: list<array<string, mixed>>}
     */
    public function neighbourContent(int $pageUid, int $colPos, int $language, int $sorting, int $elementUid, int $around, int $workspace = 0): array
    {
        if ($workspace > 0) {
            return $this->neighbourContentInWorkspace($pageUid, $colPos, $language, $sorting, $elementUid, $around, $workspace);
        }

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

    /**
     * {@see self::neighbourContent()} in a draft workspace: the page's live
     * elements and those new in the workspace, each overlaid with its version
     * there, those deleted there left out, then picked by the column,
     * language and sorting the workspace gives them. An element moved there
     * from another page is not among them (it is read from its live page).
     *
     * @return array{before: list<array<string, mixed>>, after: list<array<string, mixed>>}
     */
    private function neighbourContentInWorkspace(int $pageUid, int $colPos, int $language, int $sorting, int $elementUid, int $around, int $workspace): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $rows = $qb->select('*')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->neq('uid', $qb->createNamedParameter($elementUid, Connection::PARAM_INT)),
                $qb->expr()->eq('t3ver_oid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->in('t3ver_wsid', $qb->createNamedParameter([0, $workspace], Connection::PARAM_INT_ARRAY)),
            )
            ->setMaxResults(self::NEIGHBOUR_CANDIDATES)
            ->executeQuery()
            ->fetchAllAssociative();

        $before = [];
        $after  = [];
        foreach ($rows as $row) {
            BackendUtility::workspaceOL('tt_content', $row, $workspace);
            if (!is_array($row) || $this->int($row['t3ver_state'] ?? 0) === self::DELETE_PLACEHOLDER
                || $this->int($row['pid'] ?? 0) !== $pageUid || $this->int($row['colPos'] ?? 0) !== $colPos
                || $this->int($row['sys_language_uid'] ?? 0) !== $language) {
                continue;
            }

            $position = $this->int($row['sorting'] ?? 0);
            if ($position < $sorting) {
                $before[] = $this->columns($row);
            } elseif ($position > $sorting) {
                $after[] = $this->columns($row);
            }
        }

        $bySorting = fn (array $a, array $b): int => $this->int($a['sorting'] ?? 0) <=> $this->int($b['sorting'] ?? 0);
        usort($before, $bySorting);
        usort($after, $bySorting);

        return ['before' => array_slice($before, -$around), 'after' => array_slice($after, 0, $around)];
    }

    /**
     * The row's columns, by name.
     *
     * @param array<mixed> $row
     *
     * @return array<string, mixed>
     */
    private function columns(array $row): array
    {
        return array_filter($row, is_string(...), ARRAY_FILTER_USE_KEY);
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * A row as a user in `$workspace` sees it (ADR-032): in live, or for a
     * table that is not `$versioned`, {@see self::findLive()}. In a draft
     * workspace the live row overlaid with its version there — the live uid
     * kept — or a row new in that workspace; null for a row deleted there, a
     * version row named by its own uid (it is reached through its live
     * record) and a row of another workspace.
     *
     * @return array<string, mixed>|null
     */
    public function findAsTheUserSees(string $table, int $uid, int $workspace, bool $versioned): ?array
    {
        if ($workspace <= 0 || !$versioned) {
            return $this->findLive($table, $uid, $versioned);
        }

        if ($uid < 1) {
            return null;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $row = $qb->select('*')
            ->from($table)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)),
                $qb->expr()->eq('t3ver_oid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->in('t3ver_wsid', $qb->createNamedParameter([0, $workspace], Connection::PARAM_INT_ARRAY)),
            )
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($row)) {
            return null;
        }

        // Core's overlay selects the version's columns by the row's keys,
        // so the row carries every column.
        BackendUtility::workspaceOL($table, $row, $workspace);
        if (!is_array($row) || $this->int($row['t3ver_state'] ?? 0) === self::DELETE_PLACEHOLDER) {
            return null;
        }

        return $this->columns($row);
    }

    /**
     * The reference's decorative mark as a user in `$workspace` sees it: in a
     * draft workspace the mark of its version there; null when there is no
     * such reference.
     */
    public function decorativeMark(int $referenceUid, int $workspace = 0): ?bool
    {
        $row   = $this->findAsTheUserSees('sys_file_reference', $referenceUid, $workspace, true);
        $value = $row['tx_nrmcpagent_decorative'] ?? null;

        return is_numeric($value) ? (int) $value !== 0 : null;
    }
}
