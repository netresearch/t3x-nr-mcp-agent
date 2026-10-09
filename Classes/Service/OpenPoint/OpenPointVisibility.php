<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\OpenPoint;

use Netresearch\NrMcpAgent\Domain\Repository\OpenPointRepository;
use Throwable;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The open points of a page as one backend user may see them (ADR-022): the
 * one place that decides, for the list tool and for the system prompt alike.
 *
 * - The page must pass `readPageAccess()` with PAGE_SHOW, the page module's
 *   own condition; otherwise there is no list at all, and the caller answers
 *   as for a page that does not exist.
 * - A point is left out when the user may not read its table, when its
 *   record is gone (deleted, or never there), or when the record is in a
 *   language the user may not access.
 */
readonly class OpenPointVisibility
{
    public function __construct(
        private OpenPointRepository $repository,
    ) {}

    /**
     * Whether the user may show the page.
     */
    public function mayShowPage(BackendUserAuthentication $user, int $pageUid): bool
    {
        if ($pageUid <= 0) {
            return false;
        }

        try {
            $row = BackendUtility::readPageAccess($pageUid, $user->getPagePermsClause(Permission::PAGE_SHOW));
        } catch (Throwable) {
            return false;
        }

        $uid = is_array($row) ? ($row['uid'] ?? null) : null;

        return is_numeric($uid) && (int) $uid === $pageUid;
    }

    /**
     * The page's open points the user may see, or null when the user may not
     * show the page.
     *
     * @return list<array{skillUid: int, targetTable: string, targetUid: int, field: string, crdate: int}>|null
     */
    public function forPage(BackendUserAuthentication $user, int $pageUid, int $skillUid = 0, int $limit = 50): ?array
    {
        if (!$this->mayShowPage($user, $pageUid)) {
            return null;
        }

        $visible = [];
        foreach ($this->repository->findBySubject(OpenPointTracker::SUBJECT_TABLE, $pageUid, $skillUid, $limit) as $point) {
            if ($this->mayRead($user, $point['targetTable'], $point['targetUid'])) {
                $visible[] = $point;
            }
        }

        return $visible;
    }

    private function mayRead(BackendUserAuthentication $user, string $table, int $uid): bool
    {
        if (!$user->check('tables_select', $table)) {
            return false;
        }

        $languageField = $this->languageField($table);

        try {
            $record = BackendUtility::getRecord($table, $uid, $languageField !== '' ? 'uid,' . $languageField : 'uid');
        } catch (Throwable) {
            return false;
        }

        if (!is_array($record)) {
            return false;
        }

        $language = $languageField !== '' ? ($record[$languageField] ?? 0) : 0;

        return $languageField === '' || $user->checkLanguageAccess(is_numeric($language) ? (int) $language : 0);
    }

    /** The table's language field from its TCA, '' for a table without one. */
    private function languageField(string $table): string
    {
        $tca = $GLOBALS['TCA'] ?? null;
        $config = is_array($tca) ? ($tca[$table] ?? null) : null;
        $ctrl = is_array($config) ? ($config['ctrl'] ?? null) : null;
        $field = is_array($ctrl) ? ($ctrl['languageField'] ?? null) : null;

        return is_string($field) ? $field : '';
    }
}
