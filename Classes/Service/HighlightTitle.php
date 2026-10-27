<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Throwable;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The name of what a guided process step is about, for the element chip
 * above the chat input (NEXT-221): a content element's header, else the label
 * of its type; for a property of the conversation's page, the property's TCA
 * label in the reader's language.
 *
 * Read when the chat asks, never stored: the guided state keeps only table
 * and uid, and a page property's column (ADR-020). The element is named only while the highlight still
 * passes the policy with the element's current page, and only to a reader who
 * may read content elements and show that page; otherwise the name is ''.
 */
readonly class HighlightTitle
{
    public function __construct(
        private ?HighlightTargetPolicyInterface $highlightPolicy = null,
    ) {}

    /**
     * @param array{table: string, uid: int, field?: string}|null $highlight
     */
    public function of(Conversation $conversation, ?array $highlight, BackendUserAuthentication $user): string
    {
        if ($highlight !== null && $highlight['table'] === 'pages') {
            return $this->pageProperty($conversation, $highlight['uid'], $highlight['field'] ?? '', $user);
        }

        if ($highlight === null || $highlight['table'] !== 'tt_content' || $highlight['uid'] <= 0) {
            return '';
        }

        try {
            $row = BackendUtility::getRecord('tt_content', $highlight['uid'], 'uid,pid,header,CType');
        } catch (Throwable) {
            return '';
        }

        $pid = is_array($row) && is_numeric($row['pid'] ?? null) ? (int) $row['pid'] : 0;
        if (!is_array($row)
            || !($this->highlightPolicy ?? new ConversationPageHighlightPolicy())->allows($conversation, 'tt_content', $highlight['uid'], $pid)
            || !$user->check('tables_select', 'tt_content')
            || !$this->mayShowPage($pid, $user)
        ) {
            return '';
        }

        $header = is_string($row['header'] ?? null) ? trim($row['header']) : '';
        if ($header !== '') {
            return $header;
        }

        return $this->typeLabel($row['CType'] ?? null);
    }

    /**
     * The label of a page property, only while the page is the conversation's
     * (the policy), the reader may show it and may edit the property.
     */
    private function pageProperty(Conversation $conversation, int $pageUid, string $field, BackendUserAuthentication $user): string
    {
        if ($field === ''
            || !($this->highlightPolicy ?? new ConversationPageHighlightPolicy())->allows($conversation, 'pages', $pageUid, $pageUid)
            || !$this->mayShowPage($pageUid, $user)
            || !PageProperty::isAllowedField($field, $user)
        ) {
            return '';
        }

        $language = $GLOBALS['LANG'] ?? null;

        return PageProperty::label($field, $language instanceof LanguageService ? $language : null);
    }

    private function mayShowPage(int $pageUid, BackendUserAuthentication $user): bool
    {
        try {
            $page = BackendUtility::readPageAccess($pageUid, $user->getPagePermsClause(Permission::PAGE_SHOW));
        } catch (Throwable) {
            return false;
        }

        $uid = is_array($page) ? ($page['uid'] ?? null) : null;

        return is_numeric($uid) && (int) $uid === $pageUid;
    }

    /** The type's label in the reader's language, '' where TYPO3 has none. */
    private function typeLabel(mixed $type): string
    {
        if (!is_string($type) || $type === '') {
            return '';
        }

        try {
            $label = BackendUtility::getProcessedValue('tt_content', 'CType', $type);
        } catch (Throwable) {
            return '';
        }

        return is_string($label) ? trim($label) : '';
    }
}
