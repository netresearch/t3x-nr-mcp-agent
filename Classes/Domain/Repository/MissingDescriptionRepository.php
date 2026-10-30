<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Domain\Repository;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The pages without a meta description the current backend user could add
 * one to: the number the dashboard shows, and the page the SEO tour starts on.
 *
 * A page version counts when, as the user sees it in their workspace:
 * - its `description` is empty or holds only spaces (as SQL's TRIM()
 *   reads it, so the live query and a workspace overlay agree);
 * - it is not hidden, and for a translation, its default-language page is not
 *   hidden either, as the frontend then shows neither;
 * - its type renders a page of its own: not a link, shortcut, mount point,
 *   backend user section, spacer, folder or recycler (EXCLUDED_DOKTYPES);
 * - the user may edit it: "show page" in their web mounts and "edit page"
 *   on its default-language page, which is not locked for editing
 *   (`editlock`, binding everyone but admins), the table `pages` and its
 *   field `description` (where the TCA excludes it) granted, the language
 *   granted, and the page's site has the language
 *   (PageChoiceRepository::mayStartIn(), the rule the chat starts by).
 * Every language version counts on its own: each needs its own description.
 *
 * In a workspace each row is overlaid with its workspace version, so a
 * description written there counts as written and one emptied there as
 * empty; a page new in the workspace counts, one deleted there does not.
 *
 * The count is exact: every candidate row is read. Rows are read without the
 * description condition in a workspace, where the overlay decides it.
 */
readonly class MissingDescriptionRepository
{
    /**
     * Page types that render no page of their own, or none search engines
     * index: link, shortcut, backend user section, mount point, spacer,
     * folder, recycler (255, a constant TYPO3 14 no longer has).
     */
    public const EXCLUDED_DOKTYPES = [
        PageRepository::DOKTYPE_LINK,
        PageRepository::DOKTYPE_SHORTCUT,
        PageRepository::DOKTYPE_BE_USER_SECTION,
        PageRepository::DOKTYPE_MOUNTPOINT,
        PageRepository::DOKTYPE_SPACER,
        PageRepository::DOKTYPE_SYSFOLDER,
        255,
    ];

    /** sys_language_uid values above this are languages; -1 ("all") is none a page can have. */
    private const DEFAULT_LANGUAGE = 0;

    /** pages.t3ver_state of a page deleted in a workspace. */
    private const DELETE_PLACEHOLDER = 2;

    public function __construct(
        private ConnectionPool $connectionPool,
        private PageChoiceRepository $pages,
    ) {}

    /**
     * How many page versions lack a meta description, and the most recently
     * changed of them; null when the user may not write a meta description
     * at all, so there is nothing to count for them.
     *
     * @return array{count: int, first: array{pageUid: int, languageUid: int, title: string}|null}|null
     */
    public function summary(): ?array
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        // A workspace below 0 is none: the user may work in no workspace.
        if (!$user instanceof BackendUserAuthentication || $user->workspace < 0 || !$this->mayWriteDescriptions($user)) {
            return null;
        }

        $count   = 0;
        $first   = null;
        $access  = [];
        $overlay = $user->workspace !== 0;
        foreach ($this->candidates($user->workspace) as $row) {
            if ($overlay) {
                BackendUtility::workspaceOL('pages', $row);
                if (!is_array($row) || $this->int($row['t3ver_state'] ?? 0) === self::DELETE_PLACEHOLDER) {
                    continue;
                }
            }

            if (!$this->lacksDescription($row)) {
                continue;
            }

            $language = $this->int($row['sys_language_uid'] ?? 0);
            $pageUid  = $language > self::DEFAULT_LANGUAGE ? $this->int($row['l10n_parent'] ?? 0) : $this->int($row['uid'] ?? 0);
            $page     = $access[$pageUid] ??= $this->editablePage($user, $pageUid);
            // mayStartIn() checks the language grant and that the site has the language.
            if ($page === false || !$this->pages->mayStartIn($pageUid, $language)) {
                continue;
            }

            ++$count;
            $first ??= ['pageUid' => $pageUid, 'languageUid' => $language, 'title' => $this->string($row['title'] ?? '')];
        }

        return ['count' => $count, 'first' => $first];
    }

    /**
     * Whether the user may write a page's meta description anywhere: the
     * table, and the field where the TCA makes it an excluded field.
     */
    private function mayWriteDescriptions(BackendUserAuthentication $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (!$user->check('tables_modify', 'pages')) {
            return false;
        }

        $tca     = $GLOBALS['TCA'] ?? null;
        $pages   = is_array($tca) ? ($tca['pages'] ?? null) : null;
        $columns = is_array($pages) ? ($pages['columns'] ?? null) : null;
        $column  = is_array($columns) ? ($columns['description'] ?? null) : null;
        if (!is_array($column)) {
            return false;
        }

        return !(bool) ($column['exclude'] ?? false) || $user->check('non_exclude_fields', 'pages:description');
    }

    /**
     * The default-language page if the user may edit its properties, else
     * false. Hidden counts here too: a translation of a hidden page is not
     * shown either.
     *
     * @return array<mixed>|false
     */
    private function editablePage(BackendUserAuthentication $user, int $uid): array|false
    {
        if ($uid < 1) {
            return false;
        }

        $row = BackendUtility::readPageAccess($uid, $user->getPagePermsClause(Permission::PAGE_SHOW));
        if (!is_array($row) || !$user->doesUserHaveAccess($row, Permission::PAGE_EDIT)) {
            return false;
        }

        if ((!$user->isAdmin() && (bool) ($row['editlock'] ?? false)) || (bool) ($row['hidden'] ?? false)) {
            return false;
        }

        return $row;
    }

    /**
     * @param array<mixed> $row
     */
    private function lacksDescription(array $row): bool
    {
        return trim($this->string($row['description'] ?? ''), ' ') === ''
            && !(bool) ($row['hidden'] ?? false)
            && !in_array($this->int($row['doktype'] ?? 0), self::EXCLUDED_DOKTYPES, true)
            && $this->int($row['sys_language_uid'] ?? 0) >= self::DEFAULT_LANGUAGE;
    }

    /**
     * Live rows and the rows new in the workspace, newest change first. In
     * the live workspace the database decides the description and hidden
     * conditions; in a draft workspace the overlay does.
     *
     * The rows are read in one go, not streamed: checking access reads the
     * rootline, which may write to a database cache, and on SQLite a write
     * on the connection ends the result being read.
     *
     * @return list<array<string, mixed>>
     */
    private function candidates(int $workspace): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($workspace));

        // The workspace overlay reads the version's values of exactly these.
        $qb->select('uid', 'pid', 'title', 'description', 'hidden', 'doktype', 'sys_language_uid', 'l10n_parent', 't3ver_wsid', 't3ver_oid', 't3ver_state', 'tstamp')
            ->from('pages')
            ->where(
                $qb->expr()->gte('sys_language_uid', $qb->createNamedParameter(self::DEFAULT_LANGUAGE, Connection::PARAM_INT)),
                $qb->expr()->notIn('doktype', $qb->createNamedParameter(self::EXCLUDED_DOKTYPES, Connection::PARAM_INT_ARRAY)),
            )
            ->orderBy('tstamp', 'DESC')
            ->addOrderBy('uid', 'DESC');

        if ($workspace === 0) {
            $qb->andWhere(
                $qb->expr()->eq('hidden', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->or(
                    $qb->expr()->isNull('description'),
                    $qb->expr()->comparison($qb->expr()->trim('description'), '=', $qb->createNamedParameter('')),
                ),
            );
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
