<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Domain\Repository;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The pages without a meta description the current backend user could add
 * one to: the number the dashboard shows, and the page the SEO tour starts on.
 *
 * A page version counts when, as the user sees it in their workspace:
 * - its `description` is empty or holds only spaces;
 * - it is not hidden, and for a translation, its default-language page is not
 *   hidden either, as the frontend then shows neither;
 * - its type renders a page of its own: not a link, shortcut, mount point,
 *   backend user section, spacer, folder or recycler (EXCLUDED_DOKTYPES);
 * - the user may edit it: its default-language page lies in their web
 *   mounts, reached through pages that grant them "show page" (the rootline
 *   BackendUserAuthentication::isInWebMount() reads stops at the first that
 *   does not), grants them "show page" and "edit page", and is not locked for
 *   editing (`editlock`, binding everyone but admins); the table `pages` and
 *   its field `description` (where the TCA excludes it) are granted;
 * - the chat would start on it in its language: the user may edit the
 *   language and the page's site has it, a page outside every site only its
 *   default language (the rule of PageChoiceRepository::mayStartIn()).
 * Every language version counts on its own: each needs its own description.
 *
 * In a workspace each row is overlaid with its workspace version (title,
 * description, hidden, editlock, type, change time), so a description written
 * there counts as written and one emptied there as empty; a page new in the
 * workspace counts, one deleted there does not. Permissions and the place in
 * the tree are the live ones.
 *
 * The count is exact and costs a fixed number of queries: the languages in
 * use, the page tree (uid and parent of every default-language page), for
 * an editor the pages they may show, the pages they may edit (the permission
 * clauses in SQL), the workspace's
 * versions in a draft workspace, and the translations of those pages in the
 * languages the user may edit, 1000 pages per query. Mounts and sites are
 * resolved from the tree in memory.
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

    /** pages.t3ver_state of a page deleted in a workspace. */
    private const DELETE_PLACEHOLDER = 2;

    /** Page uids per query for the translations. */
    private const CHUNK = 1000;

    /** The columns read, and overlaid from a workspace version. */
    private const COLUMNS = ['uid', 'pid', 'title', 'description', 'hidden', 'editlock', 'doktype', 'sys_language_uid', 'l10n_parent', 'tstamp'];

    public function __construct(
        private ConnectionPool $connectionPool,
        private SiteFinder $siteFinder,
    ) {}

    /**
     * How many page versions lack a meta description, how many of them are
     * translations, and the most recently changed of them; null when the user
     * may not write a meta description at all, so there is nothing to count.
     *
     * @return array{count: int, translations: int, first: array{pageUid: int, languageUid: int, title: string}|null}|null
     */
    public function summary(): ?array
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        // A workspace below 0 is none: the user may work in no workspace.
        if (!$user instanceof BackendUserAuthentication || $user->workspace < 0 || !$this->mayWriteDescriptions($user)) {
            return null;
        }

        $languages = array_values(array_filter($this->languagesInUse($user->workspace), $user->checkLanguageAccess(...)));
        if ($languages === []) {
            return ['count' => 0, 'translations' => 0, 'first' => null];
        }

        $versions = $user->workspace === 0 ? [] : $this->versions($user->workspace);
        $tree     = $this->tree($user->workspace);
        $mounts   = $user->isAdmin() ? null : array_fill_keys(array_map(intval(...), $user->getWebmounts()), true);
        $readable = $user->isAdmin() ? null : $this->readablePages($user);
        $sites    = $this->sitesByRoot();
        $places   = [];

        // The default-language pages the user may edit, keyed by uid.
        $pages = [];
        foreach ($this->editablePages($user) as $row) {
            $row = $this->overlay($row, $versions);
            $uid = $row === null ? 0 : $this->int($row['uid'] ?? 0);
            if ($row === null || $uid < 1 || (!$user->isAdmin() && (bool) ($row['editlock'] ?? false))) {
                continue;
            }

            $place = $places[$uid] ??= $this->place($uid, $tree, $mounts, $readable, $sites);
            if ($place['inMount']) {
                $pages[$uid] = ['row' => $row, 'site' => $place['site']];
            }
        }

        $count        = 0;
        $translations = 0;
        $first        = null;
        $firstKey     = null;
        $languageSet  = array_fill_keys($languages, true);
        $rows         = isset($languageSet[0]) ? array_column($pages, 'row') : [];
        foreach ($this->translations(array_keys($pages), array_values(array_filter($languages, static fn (int $language): bool => $language > 0)), $user->workspace) as $row) {
            $row = $this->overlay($row, $versions);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        foreach ($rows as $row) {
            $language = $this->int($row['sys_language_uid'] ?? 0);
            $pageUid  = $language > 0 ? $this->int($row['l10n_parent'] ?? 0) : $this->int($row['uid'] ?? 0);
            $page     = $pages[$pageUid] ?? null;
            if ($page === null || !$this->lacksDescription($row) || (bool) ($page['row']['hidden'] ?? false) || !$this->siteHas($page['site'], $language)) {
                continue;
            }

            ++$count;
            if ($language > 0) {
                ++$translations;
            }

            // The most recently changed, as the user sees it; the higher uid on a tie.
            $key = [$this->int($row['tstamp'] ?? 0), $this->int($row['uid'] ?? 0)];
            if ($firstKey === null || $key > $firstKey) {
                $firstKey = $key;
                $first    = ['pageUid' => $pageUid, 'languageUid' => $language, 'title' => $this->string($row['title'] ?? '')];
            }
        }

        return ['count' => $count, 'translations' => $translations, 'first' => $first];
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
     * @param array<string, mixed> $row
     */
    private function lacksDescription(array $row): bool
    {
        return trim($this->string($row['description'] ?? ''), " \t\n\r\0\x0B") === ''
            && !(bool) ($row['hidden'] ?? false)
            && !in_array($this->int($row['doktype'] ?? 0), self::EXCLUDED_DOKTYPES, true);
    }

    /**
     * Where a page lies: in the user's mounts or not (always for an admin),
     * and the site whose root it lies under, from the nearest ancestor that
     * is a site root, walking the tree in memory.
     *
     * As in BackendUserAuthentication::isInWebMount(), a mount counts only
     * below the first page the user may not show: a page under one they
     * cannot see is not in their mounts, as the page tree hides it and
     * DataHandler refuses to save it.
     *
     * @param array<int, int>       $tree     page uid => parent uid
     * @param array<int, true>|null $mounts   mount uids, null for an admin
     * @param array<int, true>|null $readable pages the user may show, null for an admin
     * @param array<int, Site>      $sites    root page uid => site
     *
     * @return array{inMount: bool, site: Site|null}
     */
    private function place(int $uid, array $tree, ?array $mounts, ?array $readable, array $sites): array
    {
        $inMount  = $mounts === null;
        $reaching = true;
        $site     = null;
        $seen     = [];
        for ($current = $uid; $current > 0 && !isset($seen[$current]); $current = $tree[$current] ?? 0) {
            $seen[$current] = true;
            $reaching       = $reaching && ($readable === null || isset($readable[$current]));
            $inMount        = $inMount || ($reaching && isset($mounts[$current]));
            $site ??= $sites[$current] ?? null;
        }

        return ['inMount' => $inMount, 'site' => $site];
    }

    /** The chat starts on a page in a language its site has; outside every site only in the default language. */
    private function siteHas(?Site $site, int $language): bool
    {
        if (!$site instanceof Site) {
            return $language === 0;
        }

        return array_key_exists($language, $site->getAllLanguages());
    }

    /**
     * @return array<int, Site> root page uid => site
     */
    private function sitesByRoot(): array
    {
        $sites = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $sites[$site->getRootPageId()] = $site;
        }

        return $sites;
    }

    /**
     * The row as the workspace shows it, or null when it is deleted there.
     *
     * @param array<string, mixed>             $row
     * @param array<int, array<string, mixed>> $versions live uid => version row
     *
     * @return array<string, mixed>|null
     */
    private function overlay(array $row, array $versions): ?array
    {
        $version = $versions[$this->int($row['uid'] ?? 0)] ?? null;
        if ($version === null) {
            return $row;
        }

        if ($this->int($version['t3ver_state'] ?? 0) === self::DELETE_PLACEHOLDER) {
            return null;
        }

        foreach (['title', 'description', 'hidden', 'editlock', 'doktype', 'tstamp'] as $column) {
            $row[$column] = $version[$column] ?? null;
        }

        return $row;
    }

    /**
     * The sys_language_uid values of the pages, the default language always.
     *
     * @return list<int>
     */
    private function languagesInUse(int $workspace): array
    {
        $qb        = $this->queryBuilder($workspace);
        $languages = $qb->select('sys_language_uid')
            ->from('pages')
            ->andWhere($qb->expr()->gt('sys_language_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)))
            ->groupBy('sys_language_uid')
            ->executeQuery()
            ->fetchFirstColumn();

        return [0, ...array_map($this->int(...), $languages)];
    }

    /**
     * Every default-language page's parent, the tree the mounts and sites
     * are resolved on.
     *
     * @return array<int, int>
     */
    private function tree(int $workspace): array
    {
        $qb   = $this->queryBuilder($workspace);
        $tree = [];
        foreach ($qb->select('uid', 'pid')
            ->from('pages')
            ->andWhere($qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)))
            ->executeQuery()
            ->iterateAssociative() as $row) {
            $tree[$this->int($row['uid'] ?? 0)] = $this->int($row['pid'] ?? 0);
        }

        return $tree;
    }

    /**
     * The default-language pages that grant the user "show page", live and
     * new in their workspace, keyed by uid.
     *
     * @return array<int, true>
     */
    private function readablePages(BackendUserAuthentication $user): array
    {
        $qb = $this->queryBuilder($user->workspace);
        $qb->select('uid')
            ->from('pages')
            ->andWhere($qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)));
        $clause = QueryHelper::stripLogicalOperatorPrefix($user->getPagePermsClause(Permission::PAGE_SHOW));
        if ($clause !== '') {
            $qb->andWhere($clause);
        }

        $readable = [];
        foreach ($qb->executeQuery()->iterateAssociative() as $row) {
            $readable[$this->int($row['uid'] ?? 0)] = true;
        }

        return $readable;
    }

    /**
     * The default-language pages that grant the user "show page" and "edit
     * page", live and new in their workspace.
     *
     * @return list<array<string, mixed>>
     */
    private function editablePages(BackendUserAuthentication $user): array
    {
        $qb = $this->queryBuilder($user->workspace);
        $qb->select(...self::COLUMNS)
            ->from('pages')
            ->andWhere($qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)));

        foreach ([Permission::PAGE_SHOW, Permission::PAGE_EDIT] as $permission) {
            $clause = QueryHelper::stripLogicalOperatorPrefix($user->getPagePermsClause($permission));
            if ($clause !== '') {
                $qb->andWhere($clause);
            }
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * The translations of the pages into the languages, live and new in the
     * workspace.
     *
     * @param list<int> $pageUids
     * @param list<int> $languages
     *
     * @return iterable<array<string, mixed>>
     */
    private function translations(array $pageUids, array $languages, int $workspace): iterable
    {
        if ($languages === []) {
            return;
        }

        foreach (array_chunk($pageUids, self::CHUNK) as $chunk) {
            $qb = $this->queryBuilder($workspace);
            yield from $qb->select(...self::COLUMNS)
                ->from('pages')
                ->andWhere(
                    $qb->expr()->in('sys_language_uid', $qb->createNamedParameter($languages, Connection::PARAM_INT_ARRAY)),
                    $qb->expr()->in('l10n_parent', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                )
                ->executeQuery()
                ->fetchAllAssociative();
        }
    }

    /**
     * The versions of pages in the workspace, keyed by the live uid.
     *
     * @return array<int, array<string, mixed>>
     */
    private function versions(int $workspace): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $versions = [];
        foreach ($qb->select('t3ver_oid', 't3ver_state', 'title', 'description', 'hidden', 'editlock', 'doktype', 'tstamp')
            ->from('pages')
            ->where(
                $qb->expr()->eq('t3ver_wsid', $qb->createNamedParameter($workspace, Connection::PARAM_INT)),
                $qb->expr()->gt('t3ver_oid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->iterateAssociative() as $row) {
            $versions[$this->int($row['t3ver_oid'] ?? 0)] = $row;
        }

        return $versions;
    }

    /**
     * Live rows and the rows new in the workspace; no version rows, which the
     * overlay reads.
     */
    private function queryBuilder(int $workspace): QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($workspace));
        $qb->andWhere($qb->expr()->eq('t3ver_oid', $qb->createNamedParameter(0, Connection::PARAM_INT)));

        return $qb;
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
