<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\ChangeList;

use Throwable;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The name of a content language, as the site of a page calls it.
 *
 * A language id means something only inside a site, so the page the record or
 * the card is about decides. Where no site can be found (a page outside every
 * site, a page that is gone) the label is "Language <id>": an honest name for
 * an id this class cannot resolve, never the default language's.
 */
final readonly class LanguageLabel
{
    private const ALL_LANGUAGES = 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.allLanguages';

    private const MAX_LEVELS = 8;

    private const UNKNOWN = 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf:chat.languageUnknown';

    public function __construct(
        private SiteFinder $siteFinder,
    ) {}

    public function of(int $language, int $pageId, ?LanguageService $labels): string
    {
        if ($language === -1) {
            return $this->resolve(self::ALL_LANGUAGES, $labels, 'All languages');
        }

        // A page new in a workspace is not in the live rootline, so the site
        // is looked up from the page or the nearest ancestor that has one.
        for ($level = 0; $pageId > 0 && $level < self::MAX_LEVELS; ++$level) {
            try {
                $title = $this->siteFinder->getSiteByPageId($pageId)->getLanguageById($language)->getTitle();

                return $title !== '' ? $title : sprintf($this->resolve(self::UNKNOWN, $labels, 'Language %d'), $language);
            } catch (Throwable) {
                $parent = BackendUtility::getRecord('pages', $pageId, 'pid');
                $pageId = is_array($parent) && is_numeric($parent['pid'] ?? null) ? (int) $parent['pid'] : 0;
            }
        }

        return sprintf($this->resolve(self::UNKNOWN, $labels, 'Language %d'), $language);
    }

    private function resolve(string $reference, ?LanguageService $labels, string $fallback): string
    {
        $text = $labels instanceof LanguageService ? trim($labels->sL($reference)) : '';

        return $text !== '' ? $text : $fallback;
    }
}
