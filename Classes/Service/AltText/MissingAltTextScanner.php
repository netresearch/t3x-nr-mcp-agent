<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

use Netresearch\NrMcpAgent\Domain\Repository\MissingAltTextRepository;
use Netresearch\NrMcpAgent\Enum\HiddenReason;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The "missing alt texts" check (ADR-026): every use of an image without an
 * alt text that the backend user may fix, hidden and timed content included.
 * A database query over the image references, nothing is rendered or
 * crawled; the open-hit rules are MissingAltTextRepository::findOpenReferences().
 *
 * Each reference is followed to the record it hangs on and, through inline
 * fields, to that record's owners (ImageFieldDiscovery::ownersOf()). The
 * outermost record decides the tab: a page or a content element puts the hit
 * under "pages", any other table under that table. A deleted record anywhere
 * on the way, or a deleted page, drops the hit.
 *
 * A hit is kept only when the user may change the reference's alt text, the
 * same conditions DataHandler applies:
 * - the page passes readPageAccess() with "edit content", and for a page
 *   property "edit page" as well (records: the folder they lie in); that
 *   covers the web mounts;
 * - neither the page nor a record on the way is locked for editing;
 * - every table on the way, and sys_file_reference, may be modified, and
 *   the values of each record's authMode fields (the content type) are
 *   allowed;
 * - the image field, every inline field on the way and the reference's
 *   "Alternative text" are not excluded fields, or are granted;
 * - the user may edit the reference's language and each record's.
 * Admins pass all but the page's existence.
 *
 * In a workspace each row is overlaid with its workspace version, so an alt
 * text or decorative mark set there, and a record deleted there, count;
 * records of tables that are not workspace-aware are left out (ADR-026 names
 * what the overlay does not cover).
 */
class MissingAltTextScanner
{
    /** How many inline levels are followed from the record holding the reference. */
    private const MAX_OWNER_DEPTH = 4;

    /** sys_file_reference.t3ver_state of a record deleted in a workspace. */
    private const DELETE_PLACEHOLDER = 2;

    /** @var array<string, array<int, array<string, mixed>|null>> rows per table and uid, null when gone */
    private array $records = [];

    /** @var array<string, array<mixed>|null> */
    private array $pageAccess = [];

    /** @var array<string, list<InlineOwner>> */
    private array $owners = [];

    /** @var array<int, string> */
    private array $treePaths = [];

    /** @var array<int, array{0: HiddenReason|null, 1: int}> */
    private array $hiddenByParent = [];

    /** @var array<string, array<string, mixed>|null> page translations by "page:language", null when there is none */
    private array $pageTranslations = [];

    private int $workspace = 0;

    public function __construct(
        private readonly ImageFieldDiscovery $discovery,
        private readonly MissingAltTextRepository $repository,
        private readonly Context $context,
    ) {}

    /**
     * The scan for the current backend user; empty without one.
     */
    public function scanForCurrentUser(): MissingAltTextScan
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? $this->scan($user) : new MissingAltTextScan([]);
    }

    public function scan(BackendUserAuthentication $user): MissingAltTextScan
    {
        $this->records        = [];
        $this->pageAccess     = [];
        $this->owners         = [];
        $this->treePaths      = [];
        $this->hiddenByParent = [];

        $this->pageTranslations = [];

        $fieldsByTable   = $this->discovery->fieldsByTable();
        $workspace       = $user->workspace;
        $this->workspace = $workspace;

        $rows = $this->repository->findOpenReferences($fieldsByTable, $workspace);
        if ($workspace !== 0) {
            $this->load(array_map(fn (array $row): array => ['sys_file_metadata', $this->int($row['_metadata_uid'] ?? 0), ''], $rows), $workspace);
        }

        $references = [];
        foreach ($rows as $row) {
            $fileName    = $this->string($row['_file_name'] ?? null);
            $metadataUid = $this->int($row['_metadata_uid'] ?? 0);
            unset($row['_file_name'], $row['_metadata_uid']);
            if ($workspace !== 0) {
                // The query decided on the live rows; the workspace versions
                // may have the alt text or the mark, or be deleted.
                $row = $this->overlay('sys_file_reference', $row, $workspace);
                if ($row === null || $this->int($row['tx_nrmcpagent_decorative'] ?? 0) !== 0 || trim($this->string($row['alternative'] ?? null)) !== '') {
                    continue;
                }

                $metadata = $this->records['sys_file_metadata'][$metadataUid] ?? null;
                if ($row['alternative'] === null && trim($this->string($metadata['alternative'] ?? null)) !== '') {
                    continue;
                }
            }

            $references[] = ['row' => $row, 'fileName' => $fileName];
        }

        $chains = $this->chains($references, $workspace);
        $this->loadPages($chains, $workspace);
        $now   = $this->now();
        $keyed = [];
        foreach ($references as $index => $reference) {
            $chain = $chains[$index] ?? null;
            $hit   = $chain === null ? null : $this->hit($user, $reference['row'], $reference['fileName'], $chain, $now);
            if ($hit !== null) {
                $keyed[] = $hit;
            }
        }

        usort($keyed, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        return new MissingAltTextScan(array_map(static fn (array $entry): MissingAltText => $entry['hit'], $keyed));
    }

    /**
     * The record each reference hangs on, followed by its inline owners, read
     * level by level with one query per table. A reference whose record or
     * owner is gone has no chain.
     *
     * @param list<array{row: array<string, mixed>, fileName: string}> $references
     *
     * @return array<int, non-empty-list<array{table: string, row: array<string, mixed>, via: string}>>
     */
    private function chains(array $references, int $workspace): array
    {
        $chains  = [];
        $pending = [];
        foreach ($references as $index => $reference) {
            $chains[$index]  = [];
            $pending[$index] = [$this->string($reference['row']['tablenames'] ?? null), $this->int($reference['row']['uid_foreign'] ?? 0), $this->string($reference['row']['fieldname'] ?? null)];
        }

        for ($depth = 0; $pending !== [] && $depth <= self::MAX_OWNER_DEPTH; ++$depth) {
            $this->load(array_values($pending), $workspace);
            $next = [];
            foreach ($pending as $index => [$table, $uid, $via]) {
                $row = $this->records[$table][$uid] ?? null;
                if ($row === null) {
                    unset($chains[$index]);
                    continue;
                }

                $chains[$index][] = ['table' => $table, 'row' => $row, 'via' => $via];
                $owner            = $this->ownerOf($table, $row);
                if ($owner !== null) {
                    $next[$index] = $owner;
                }
            }

            $pending = $next;
        }

        // An owner chain deeper than MAX_OWNER_DEPTH is not resolved: such a
        // hit would be filed under the wrong record.
        $resolved = [];
        foreach ($chains as $index => $chain) {
            if ($chain !== [] && !array_key_exists($index, $pending)) {
                $resolved[$index] = $chain;
            }
        }

        return $resolved;
    }

    /**
     * Reads the rows not read yet, one query per table.
     *
     * @param list<array{0: string, 1: int, 2: string}> $wanted
     */
    private function load(array $wanted, int $workspace): void
    {
        $missing = [];
        foreach ($wanted as [$table, $uid]) {
            if ($table !== '' && !array_key_exists($uid, $this->records[$table] ?? [])) {
                $missing[$table][] = $uid;
            }
        }

        foreach ($missing as $table => $uids) {
            $rows = $this->repository->findByUids($table, $uids, $workspace);
            foreach ($uids as $uid) {
                $row                         = $rows[$uid] ?? null;
                $this->records[$table][$uid] = $row === null ? null : $this->overlay($table, $row, $workspace);
            }
        }
    }

    /**
     * The owner of a row through an inline field, as [table, uid, field].
     *
     * @param array<string, mixed> $row
     *
     * @return array{0: string, 1: int, 2: string}|null
     */
    private function ownerOf(string $table, array $row): ?array
    {
        $this->owners[$table] ??= $this->discovery->ownersOf($table);
        foreach ($this->owners[$table] as $owner) {
            $uid = $this->int($row[$owner->foreignField] ?? 0);
            if ($uid <= 0) {
                continue;
            }

            if ($owner->foreignTableField !== '' && $this->string($row[$owner->foreignTableField] ?? null) !== $owner->table) {
                continue;
            }

            return [$owner->table, $uid, $owner->field];
        }

        return null;
    }

    /**
     * The hit with its sort key, or null when the user may not fix it.
     *
     * @param array<string, mixed>                                                         $reference
     * @param non-empty-list<array{table: string, row: array<string, mixed>, via: string}> $chain     from the record holding the reference to the outermost owner
     *
     * @return array{key: list<int|string>, hit: MissingAltText}|null
     */
    private function hit(BackendUserAuthentication $user, array $reference, string $fileName, array $chain, int $now): ?array
    {
        $top       = $chain[count($chain) - 1];
        $isPages   = in_array($top['table'], ['pages', 'tt_content'], true);
        $type      = $isPages ? MissingAltTextScan::PAGES : $top['table'];
        $workspace = $user->workspace;
        foreach ($chain as $link) {
            if ($this->discovery->isExcludedTable($link['table']) || ($workspace !== 0 && !$this->isWorkspaceAware($link['table']))) {
                return null;
            }

            if (!$this->discovery->isShown($link['table'], (string) BackendUtility::getTCAtypeValue($link['table'], $link['row']), $link['via'])) {
                return null;
            }
        }

        $pageUid = $this->pageUidOf($top);
        // An image of the page itself needs both: the form edits it as a page
        // property ("edit page"), and the DataHandler, writing the reference
        // row alone, asks "edit content" on its page (it asks "edit page" only
        // with `pages` in the same datamap).
        $permission = $top['table'] === 'pages' ? Permission::PAGE_EDIT | Permission::CONTENT_EDIT : Permission::CONTENT_EDIT;
        $page       = $this->editablePage($user, $pageUid, $permission);
        if ($page === null || !$this->mayEdit($user, $reference, $chain)) {
            return null;
        }

        $element = null;
        foreach ($chain as $link) {
            if ($link['table'] === 'tt_content') {
                $element = $link['row'];
                break;
            }
        }

        $pageTitle       = $this->string($page['title'] ?? null);
        $language        = $this->languageOf($top);
        [$reason, $date] = $this->hiddenReason($reference, $chain, $isPages ? $pageUid : 0, $language, $now);

        $hit = new MissingAltText(
            referenceUid: $this->int($reference['uid'] ?? 0),
            fileUid: $this->int($reference['uid_local'] ?? 0),
            fileName: $fileName,
            crop: $this->string($reference['crop'] ?? null),
            type: $type,
            table: $chain[0]['table'],
            recordUid: $this->int($chain[0]['row']['uid'] ?? 0),
            field: $this->string($reference['fieldname'] ?? null),
            languageUid: $this->int($reference['sys_language_uid'] ?? 0),
            pageUid: $pageUid,
            pageTitle: $pageTitle,
            groupUid: $isPages ? $pageUid : $this->int($top['row']['uid'] ?? 0),
            groupTitle: $isPages ? $pageTitle : BackendUtility::getRecordTitle($top['table'], $top['row']),
            elementUid: $element === null ? 0 : $this->int($element['uid'] ?? 0),
            elementType: $element === null ? '' : $this->string($element['CType'] ?? null),
            elementHeader: $element === null ? '' : $this->string($element['header'] ?? null),
            hiddenReason: $reason,
            hiddenDate: $date,
        );

        return ['key' => $this->sortKey($hit, $element, $chain, $this->int($reference['sorting_foreign'] ?? 0)), 'hit' => $hit];
    }

    /**
     * Table, field and language rights, and editlock, for the reference and
     * every record on its chain.
     *
     * @param array<string, mixed>                                                         $reference
     * @param non-empty-list<array{table: string, row: array<string, mixed>, via: string}> $chain
     */
    private function mayEdit(BackendUserAuthentication $user, array $reference, array $chain): bool
    {
        if (!$user->checkLanguageAccess($this->int($reference['sys_language_uid'] ?? 0))) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if (!$user->check('tables_modify', 'sys_file_reference') || !$this->mayEditField($user, 'sys_file_reference', 'alternative')) {
            return false;
        }

        foreach ($chain as $link) {
            $table = $link['table'];
            $ctrl  = $this->ctrl($table);
            if (!$user->check('tables_modify', $table) || !$this->mayEditField($user, $table, $link['via'])) {
                return false;
            }

            $languageField = is_string($ctrl['languageField'] ?? null) ? $ctrl['languageField'] : '';
            if ($languageField !== '' && !$user->checkLanguageAccess($this->int($link['row'][$languageField] ?? 0))) {
                return false;
            }

            $editlock = is_string($ctrl['editlock'] ?? null) ? $ctrl['editlock'] : '';
            if ($editlock !== '' && $this->int($link['row'][$editlock] ?? 0) !== 0) {
                return false;
            }

            if (!$this->mayUseValues($user, $table, $link['row'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The values of the record's `authMode` fields, the content type above
     * all, are ones the user is allowed (explicit_allowdeny), as nr-llm's
     * alt text writer asks it of the record before its card.
     *
     * @param array<string, mixed> $row
     */
    private function mayUseValues(BackendUserAuthentication $user, string $table, array $row): bool
    {
        $columns = $this->tableConfig($table)['columns'] ?? null;
        foreach (is_array($columns) ? $columns : [] as $name => $column) {
            $config = is_array($column) ? ($column['config'] ?? null) : null;
            if (!is_array($config) || ($config['type'] ?? null) !== 'select' || !(bool) ($config['authMode'] ?? false)) {
                continue;
            }

            if (array_key_exists($name, $row) && !$user->checkAuthMode($table, $name, $this->string($row[$name]))) {
                return false;
            }
        }

        return true;
    }

    private function mayEditField(BackendUserAuthentication $user, string $table, string $field): bool
    {
        $column = $this->column($table, $field);

        return !(bool) ($column['exclude'] ?? false) || $user->check('non_exclude_fields', $table . ':' . $field);
    }

    /**
     * The page row when the user may edit it with the permission and it is
     * not locked for editing (admins excepted), else null.
     *
     * @return array<mixed>|null
     */
    private function editablePage(BackendUserAuthentication $user, int $pageUid, int $permission): ?array
    {
        $key = $pageUid . ':' . $permission;
        if (!array_key_exists($key, $this->pageAccess)) {
            $row = $pageUid < 0 ? false : BackendUtility::readPageAccess($pageUid, $user->getPagePermsClause($permission));
            if (is_array($row) && !$user->isAdmin() && (bool) ($row['editlock'] ?? false)) {
                $row = false;
            }

            $this->pageAccess[$key] = is_array($row) ? $row : null;
        }

        return $this->pageAccess[$key];
    }

    /**
     * Why the image is not shown in the frontend now, and the date that goes
     * with it. The level highest up wins: the parent pages, the page, its
     * translation in the language of the outermost record, then each record
     * from the outermost down, then the reference; on one level being hidden
     * before a start time before an end time.
     *
     * @param array<string, mixed>                                                         $reference
     * @param non-empty-list<array{table: string, row: array<string, mixed>, via: string}> $chain
     *
     * @return array{0: HiddenReason|null, 1: int}
     */
    private function hiddenReason(array $reference, array $chain, int $pageUid, int $language, int $now): array
    {
        $levels = [];
        if ($pageUid > 0) {
            $byParent = $this->hiddenByParent($pageUid, $now);
            if ($byParent[0] !== null) {
                return $byParent;
            }

            $page = $this->pageRow($pageUid);
            if ($page !== null) {
                $levels[] = ['pages', $page, HiddenReason::Page];
            }

            $translation = $language > 0 ? ($this->pageTranslations[$pageUid . ':' . $language] ?? null) : null;
            if ($translation !== null) {
                $levels[] = ['pages', $translation, HiddenReason::Page];
            }
        }

        $outermost = count($chain) - 1;
        for ($index = $outermost; $index >= 0; --$index) {
            $table    = $chain[$index]['table'];
            $levels[] = [$table, $chain[$index]['row'], match (true) {
                $table === 'pages'                      => HiddenReason::Page,
                $pageUid === 0 && $index === $outermost => HiddenReason::Record,
                default                                 => HiddenReason::Element,
            }];
        }

        foreach ($levels as [$table, $row, $reason]) {
            $enable = $this->enableColumns($table);
            if ($enable['disabled'] !== '' && $this->int($row[$enable['disabled']] ?? 0) !== 0) {
                return [$reason, 0];
            }

            $start = $enable['starttime'] !== '' ? $this->int($row[$enable['starttime']] ?? 0) : 0;
            if ($start > $now) {
                return [HiddenReason::Scheduled, $start];
            }

            $end = $enable['endtime'] !== '' ? $this->int($row[$enable['endtime']] ?? 0) : 0;
            if ($end > 0 && $end <= $now) {
                return [HiddenReason::Expired, $end];
            }
        }

        return $this->int($reference['hidden'] ?? 0) !== 0 ? [HiddenReason::Image, 0] : [null, 0];
    }

    /**
     * Why a page above this one hides it: one that passes its visibility on
     * to its subpages (`extendToSubpages`) and is hidden, not started yet or
     * expired, as the frontend treats it. The page highest up wins.
     *
     * @return array{0: HiddenReason|null, 1: int}
     */
    private function hiddenByParent(int $pageUid, int $now): array
    {
        if (!array_key_exists($pageUid, $this->hiddenByParent)) {
            $reason = [null, 0];
            foreach (array_reverse($this->rootLine($pageUid)) as $page) {
                if ($this->int($page['uid'] ?? 0) === $pageUid || $this->int($page['extendToSubpages'] ?? 0) === 0) {
                    continue;
                }

                $start  = $this->int($page['starttime'] ?? 0);
                $end    = $this->int($page['endtime'] ?? 0);
                $reason = match (true) {
                    $this->int($page['hidden'] ?? 0) !== 0 => [HiddenReason::ParentPage, 0],
                    $start > $now                          => [HiddenReason::Scheduled, $start],
                    $end > 0 && $end <= $now               => [HiddenReason::Expired, $end],
                    default                                => [null, 0],
                };
                if ($reason[0] !== null) {
                    break;
                }
            }

            $this->hiddenByParent[$pageUid] = $reason;
        }

        return $this->hiddenByParent[$pageUid];
    }

    /**
     * The page's position in the page tree as a string that sorts like the
     * tree: each level's `sorting` and uid from the root down.
     */
    private function treePath(int $pageUid): string
    {
        if (!array_key_exists($pageUid, $this->treePaths)) {
            $segments = [];
            foreach (array_reverse($this->rootLine($pageUid)) as $page) {
                $segments[] = sprintf('%011d.%011d', $this->int($page['sorting'] ?? 0), $this->int($page['uid'] ?? 0));
            }

            $this->treePaths[$pageUid] = 'p/' . implode('/', $segments);
        }

        return $this->treePaths[$pageUid];
    }

    /**
     * From the page up to the root.
     *
     * @return list<array<string, mixed>>
     */
    private function rootLine(int $pageUid): array
    {
        $rootLine = BackendUtility::BEgetRootLine($pageUid, '', true, ['hidden', 'starttime', 'endtime', 'extendToSubpages', 'sorting']);
        $pages    = [];
        foreach ($rootLine as $page) {
            if (!is_array($page) || $this->int($page['uid'] ?? 0) <= 0) {
                continue;
            }

            $row = [];
            foreach ($page as $key => $value) {
                $row[(string) $key] = $value;
            }

            $pages[] = $row;
        }

        return $pages;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pageRow(int $pageUid): ?array
    {
        if (!array_key_exists($pageUid, $this->records['pages'] ?? [])) {
            $this->load([['pages', $pageUid, '']], $this->workspace);
        }

        return $this->records['pages'][$pageUid] ?? null;
    }

    /**
     * Reads the page of every chain, and its translation in the language of
     * the chain's outermost record, with one query per chunk of pages.
     *
     * @param array<int, non-empty-list<array{table: string, row: array<string, mixed>, via: string}>> $chains
     */
    private function loadPages(array $chains, int $workspace): void
    {
        $pages        = [];
        $translations = [];
        foreach ($chains as $chain) {
            $top      = $chain[count($chain) - 1];
            $pageUid  = $this->pageUidOf($top);
            $pages[]  = ['pages', $pageUid, ''];
            $language = $this->languageOf($top);
            if ($language > 0 && in_array($top['table'], ['pages', 'tt_content'], true)) {
                $translations[$pageUid . ':' . $language] = $pageUid;
            }
        }

        $this->load($pages, $workspace);

        foreach (array_keys($translations) as $key) {
            $this->pageTranslations[$key] = null;
        }

        foreach ($this->repository->findPageTranslations(array_values(array_unique($translations)), $workspace) as $row) {
            $key = $this->int($row['l10n_parent'] ?? 0) . ':' . $this->int($row['sys_language_uid'] ?? 0);
            if (array_key_exists($key, $this->pageTranslations)) {
                $this->pageTranslations[$key] = $this->overlay('pages', $row, $workspace);
            }
        }
    }

    /**
     * The page a chain's outermost record is filed under: the page itself in
     * the default language, or the page the record lies on.
     *
     * @param array{table: string, row: array<string, mixed>, via: string} $top
     */
    private function pageUidOf(array $top): int
    {
        return $top['table'] === 'pages' ? $this->defaultLanguageUid($top['row']) : $this->int($top['row']['pid'] ?? 0);
    }

    /**
     * The language of a record, 0 for a table without languages.
     *
     * @param array{table: string, row: array<string, mixed>, via: string} $link
     */
    private function languageOf(array $link): int
    {
        $field = $this->ctrl($link['table'])['languageField'] ?? null;

        return is_string($field) ? $this->int($link['row'][$field] ?? 0) : 0;
    }

    /**
     * Pages: page tree, then within the page the page properties before the
     * content, content by language, column and sorting. Records: table,
     * folder, title, record. Then the reference's own order.
     *
     * @param array<string, mixed>|null                                                    $element
     * @param non-empty-list<array{table: string, row: array<string, mixed>, via: string}> $chain
     *
     * @return list<int|string>
     */
    private function sortKey(MissingAltText $hit, ?array $element, array $chain, int $referenceSorting): array
    {
        // A record inside the owner (an item, a content element in a record)
        // sorts by its own position, then the references by theirs.
        $holder    = count($chain) > 1 ? $this->int($chain[0]['row']['sorting'] ?? 0) : 0;
        $reference = [$holder, $hit->recordUid, $referenceSorting, $hit->referenceUid];
        if ($hit->type === MissingAltTextScan::PAGES) {
            return [
                0,
                $this->treePath($hit->pageUid),
                $element === null ? 0 : 1,
                $element === null ? $hit->languageUid : $this->int($element['sys_language_uid'] ?? 0),
                $element === null ? 0 : $this->int($element['colPos'] ?? 0),
                $element === null ? 0 : $this->int($element['sorting'] ?? 0),
                $hit->elementUid,
                ...$reference,
            ];
        }

        return [
            1,
            't/' . $hit->type,
            't/' . mb_strtolower($hit->pageTitle),
            't/' . mb_strtolower($hit->groupTitle),
            $hit->pageUid,
            $hit->groupUid,
            0,
            ...$reference,
        ];
    }

    /**
     * The row overlaid with its version in the workspace; null when the row
     * is deleted there.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private function overlay(string $table, array $row, int $workspace): ?array
    {
        if ($workspace === 0) {
            return $row;
        }

        BackendUtility::workspaceOL($table, $row, $workspace);
        if (!is_array($row) || $this->int($row['t3ver_state'] ?? 0) === self::DELETE_PLACEHOLDER) {
            return null;
        }

        $overlaid = [];
        foreach ($row as $key => $value) {
            $overlaid[(string) $key] = $value;
        }

        return $overlaid;
    }

    /**
     * @param array<string, mixed> $page
     */
    private function defaultLanguageUid(array $page): int
    {
        $parent = $this->int($page['l10n_parent'] ?? 0);

        return $parent > 0 ? $parent : $this->int($page['uid'] ?? 0);
    }

    private function isWorkspaceAware(string $table): bool
    {
        return (bool) ($this->ctrl($table)['versioningWS'] ?? false);
    }

    /**
     * @return array{disabled: string, starttime: string, endtime: string}
     */
    private function enableColumns(string $table): array
    {
        $enable = $this->ctrl($table)['enablecolumns'] ?? null;
        $enable = is_array($enable) ? $enable : [];

        return [
            'disabled'  => is_string($enable['disabled'] ?? null) ? $enable['disabled'] : '',
            'starttime' => is_string($enable['starttime'] ?? null) ? $enable['starttime'] : '',
            'endtime'   => is_string($enable['endtime'] ?? null) ? $enable['endtime'] : '',
        ];
    }

    /**
     * @return array<mixed>
     */
    private function ctrl(string $table): array
    {
        $ctrl = $this->tableConfig($table)['ctrl'] ?? null;

        return is_array($ctrl) ? $ctrl : [];
    }

    /**
     * @return array<mixed>
     */
    private function column(string $table, string $field): array
    {
        $columns = $this->tableConfig($table)['columns'] ?? null;
        $column  = is_array($columns) ? ($columns[$field] ?? null) : null;

        return is_array($column) ? $column : [];
    }

    /**
     * @return array<mixed>
     */
    private function tableConfig(string $table): array
    {
        $tca    = $GLOBALS['TCA'] ?? null;
        $config = is_array($tca) ? ($tca[$table] ?? null) : null;

        return is_array($config) ? $config : [];
    }

    private function now(): int
    {
        $now = $this->context->getPropertyFromAspect('date', 'timestamp');

        return is_int($now) ? $now : time();
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
