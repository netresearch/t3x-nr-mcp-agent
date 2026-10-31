<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Throwable;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * A content element a guided point may be about (ADR-020): it exists, the
 * user may select `tt_content`, may use the element's language, and may show
 * the element's page — the page module's own condition. One answer for "does
 * not exist" and "may not see".
 *
 * Shared by `chat_highlight_element` and the highlight the chat derives from
 * a pending proposal (amendment of 2026-10-31), so a derived highlight can
 * never name an element the tool would refuse.
 */
final class VisibleContentElement
{
    private const TABLE = 'tt_content';

    /**
     * @return array{uid: int, pid: int}|null the element and its page, or null when the user may not see it
     */
    public static function resolve(BackendUserAuthentication $user, int $uid): ?array
    {
        $record   = $uid > 0 ? BackendUtility::getRecord(self::TABLE, $uid, 'uid,pid,sys_language_uid') : null;
        $pid      = is_array($record) && is_numeric($record['pid'] ?? null) ? (int) $record['pid'] : 0;
        $language = is_array($record) && is_numeric($record['sys_language_uid'] ?? null) ? (int) $record['sys_language_uid'] : 0;
        if ($pid <= 0
            || !$user->check('tables_select', self::TABLE)
            || !$user->checkLanguageAccess($language)
            || !self::mayShowPage($user, $pid)
        ) {
            return null;
        }

        return ['uid' => $uid, 'pid' => $pid];
    }

    private static function mayShowPage(BackendUserAuthentication $user, int $pageUid): bool
    {
        try {
            $row = BackendUtility::readPageAccess($pageUid, $user->getPagePermsClause(Permission::PAGE_SHOW));
        } catch (Throwable) {
            return false;
        }

        $uid = is_array($row) ? ($row['uid'] ?? null) : null;

        return is_numeric($uid) && (int) $uid === $pageUid;
    }
}
