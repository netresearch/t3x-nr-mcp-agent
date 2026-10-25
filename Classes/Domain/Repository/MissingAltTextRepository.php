<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\LimitToTablesRestrictionContainer;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Resource\FileType;

/**
 * The database side of the "missing alt texts" check (ADR-026): image
 * references without an alt text, and the records they hang on. A query
 * over the references, nothing is rendered or crawled.
 *
 * What may be edited, and what the editor's workspace changes, is decided by
 * the caller (MissingAltTextScanner); this class reads rows as stored.
 */
readonly class MissingAltTextRepository
{
    /** Uids per IN() list, below every database's parameter limit. */
    private const CHUNK = 500;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * The references in the given fields that are open:
     * - the file is an image (sys_file.type);
     * - the reference is not marked decorative;
     * - the reference is not deleted;
     * - the image has no effective alt text. A reference whose `alternative`
     *   is NULL shows the file's metadata text, the one in the reference's
     *   language where the file has a metadata translation, else the default
     *   language's; a reference with its own value, an empty one included,
     *   shows that value (FileReference::getProperties()). Whitespace counts
     *   as empty.
     *
     * The file's metadata is read in its live version: a draft in a
     * workspace never decides here. In a workspace the live references and
     * those new in that workspace are read; the workspace versions of the
     * reference and of the metadata are the caller's to overlay.
     *
     * Columns are the reference's own, plus `_file_name` and the uid of the
     * metadata row whose text the reference inherits, `_metadata_uid` (0
     * without one); the underscore keeps the aliases out of a workspace
     * overlay's field list.
     *
     * @param array<string, list<string>> $fieldsByTable image fields per table
     *
     * @return list<array<string, mixed>>
     */
    public function findOpenReferences(array $fieldsByTable, int $workspace): array
    {
        if ($fieldsByTable === []) {
            return [];
        }

        $qb           = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $restrictions = new LimitToTablesRestrictionContainer();
        $restrictions->addForTables(new DeletedRestriction(), ['r']);
        $restrictions->addForTables(new WorkspaceRestriction($workspace), ['r']);
        $qb->getRestrictions()->removeAll()->add($restrictions);

        $fieldConditions = [];
        foreach ($fieldsByTable as $table => $fields) {
            $fieldConditions[] = $qb->expr()->and(
                $qb->expr()->eq('r.tablenames', $qb->createNamedParameter($table)),
                $qb->expr()->in('r.fieldname', $qb->createNamedParameter($fields, Connection::PARAM_STR_ARRAY)),
            );
        }

        $qb->select(
            'r.uid',
            'r.pid',
            'r.uid_local',
            'r.uid_foreign',
            'r.tablenames',
            'r.fieldname',
            'r.sorting_foreign',
            'r.sys_language_uid',
            'r.l10n_parent',
            'r.hidden',
            'r.alternative',
            'r.crop',
            'r.tx_nrmcpagent_decorative',
            'r.t3ver_oid',
            'r.t3ver_wsid',
            'r.t3ver_state',
        )
            ->addSelectLiteral($qb->quoteIdentifier('f.name') . ' AS ' . $qb->quoteIdentifier('_file_name'))
            ->addSelectLiteral('COALESCE(' . $qb->quoteIdentifier('ml.uid') . ', ' . $qb->quoteIdentifier('m0.uid') . ', 0) AS ' . $qb->quoteIdentifier('_metadata_uid'))
            ->from('sys_file_reference', 'r')
            ->innerJoin('r', 'sys_file', 'f', $qb->expr()->eq('f.uid', $qb->quoteIdentifier('r.uid_local')))
            ->leftJoin('f', 'sys_file_metadata', 'm0', (string) $qb->expr()->and(
                $qb->expr()->eq('m0.file', $qb->quoteIdentifier('f.uid')),
                $qb->expr()->eq('m0.sys_language_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                ...$this->isLive($qb, 'm0'),
            ))
            ->leftJoin('f', 'sys_file_metadata', 'ml', (string) $qb->expr()->and(
                $qb->expr()->eq('ml.file', $qb->quoteIdentifier('f.uid')),
                $qb->expr()->eq('ml.sys_language_uid', $qb->quoteIdentifier('r.sys_language_uid')),
                $qb->expr()->gt('r.sys_language_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                ...$this->isLive($qb, 'ml'),
            ))
            ->where(
                $qb->expr()->eq('f.type', $qb->createNamedParameter(FileType::IMAGE->value, Connection::PARAM_INT)),
                $qb->expr()->eq('r.tx_nrmcpagent_decorative', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->or(...$fieldConditions),
                $qb->expr()->or(
                    $qb->expr()->and(
                        $qb->expr()->isNotNull('r.alternative'),
                        $this->isBlank($qb, 'r.alternative'),
                    ),
                    $qb->expr()->and(
                        $qb->expr()->isNull('r.alternative'),
                        $qb->expr()->or(
                            $qb->expr()->and(
                                $qb->expr()->isNotNull('ml.uid'),
                                $this->isBlank($qb, 'ml.alternative'),
                            ),
                            $qb->expr()->and(
                                $qb->expr()->isNull('ml.uid'),
                                $this->isBlank($qb, 'm0.alternative'),
                            ),
                        ),
                    ),
                ),
            )
            ->orderBy('r.uid');

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Rows of a table by uid, deleted ones left out; in a workspace the live
     * rows and those new in it, not yet overlaid. Every column is read: the
     * caller needs the label, language, enable and editlock fields, which
     * differ per table.
     *
     * @param list<int> $uids
     *
     * @return array<int, array<string, mixed>> keyed by uid
     */
    public function findByUids(string $table, array $uids, int $workspace): array
    {
        $uids = array_values(array_unique(array_filter($uids, static fn (int $uid): bool => $uid > 0)));
        $rows = [];
        foreach (array_chunk($uids, self::CHUNK) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable($table);
            $qb->getRestrictions()
                ->removeAll()
                ->add(new DeletedRestriction())
                ->add(new WorkspaceRestriction($workspace));
            $result = $qb->select('*')
                ->from($table)
                ->where($qb->expr()->in('uid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeQuery();
            while (($row = $result->fetchAssociative()) !== false) {
                $uid = $row['uid'] ?? null;
                if (is_numeric($uid)) {
                    $rows[(int) $uid] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * The translations of the given pages, deleted ones left out; in a
     * workspace the live rows and those new in it, not yet overlaid.
     *
     * @param list<int> $pageUids pages in the default language
     *
     * @return list<array<string, mixed>>
     */
    public function findPageTranslations(array $pageUids, int $workspace): array
    {
        $pageUids = array_values(array_unique(array_filter($pageUids, static fn (int $uid): bool => $uid > 0)));
        $rows     = [];
        foreach (array_chunk($pageUids, self::CHUNK) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable('pages');
            $qb->getRestrictions()
                ->removeAll()
                ->add(new DeletedRestriction())
                ->add(new WorkspaceRestriction($workspace));
            $result = $qb->select('*')
                ->from('pages')
                ->where(
                    $qb->expr()->in('l10n_parent', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                    $qb->expr()->gt('sys_language_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->executeQuery();
            while (($row = $result->fetchAssociative()) !== false) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The row is the live version, not a draft of a workspace.
     *
     * @return list<string>
     */
    private function isLive(QueryBuilder $qb, string $alias): array
    {
        return [
            $qb->expr()->eq($alias . '.t3ver_wsid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            $qb->expr()->eq($alias . '.t3ver_oid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
        ];
    }

    /** `TRIM(column) = ''` or the column is NULL. */
    private function isBlank(QueryBuilder $qb, string $column): string
    {
        return (string) $qb->expr()->or(
            $qb->expr()->isNull($column),
            $qb->expr()->comparison('TRIM(' . $qb->quoteIdentifier($column) . ')', '=', $qb->createNamedParameter('')),
        );
    }
}
