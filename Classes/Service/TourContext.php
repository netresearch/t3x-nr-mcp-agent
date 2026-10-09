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
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The page and the language a guided process runs on, for the chat's header
 * (ADR-023): from the conversation (ADR-019), never from the model.
 */
readonly class TourContext
{
    public function __construct(
        private ?SiteFinder $siteFinder = null,
    ) {}

    /**
     * The page title and the language's name, or null for a conversation
     * without a skill or a page, and for a page the user may not show. The
     * language is the conversation's, else the site's default, named by its
     * title, never its id; '' for a page outside every site.
     *
     * The page's uid and language travel along, so the chat can tell when the
     * page module shows another page (ADR-023).
     *
     * @return array{pageUid: int, languageUid: int, pageTitle: string, languageName: string}|null
     */
    public function of(Conversation $conversation, BackendUserAuthentication $user): ?array
    {
        $context = $conversation->getViewContext();
        $pageUid = $context['pageId'];
        if ($conversation->getSkillIdentifier() === '' || $pageUid <= 0) {
            return null;
        }

        try {
            $row = BackendUtility::readPageAccess($pageUid, $user->getPagePermsClause(Permission::PAGE_SHOW));
        } catch (Throwable) {
            return null;
        }

        $uid = is_array($row) ? ($row['uid'] ?? null) : null;
        if (!is_array($row) || !is_numeric($uid) || (int) $uid !== $pageUid) {
            return null;
        }

        $title = $row['title'] ?? '';

        $languageUid = max(0, $context['languageId']);

        return [
            'pageUid' => $pageUid,
            'languageUid' => $languageUid,
            'pageTitle' => is_string($title) ? $title : '',
            'languageName' => $this->languageName($pageUid, $languageUid),
        ];
    }

    private function languageName(int $pageUid, int $languageUid): string
    {
        try {
            return $this->siteFinder?->getSiteByPageId($pageUid)->getLanguageById($languageUid)->getTitle() ?? '';
        } catch (Throwable) {
            return '';
        }
    }
}
