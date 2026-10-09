<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

use Netresearch\NrMcpAgent\Domain\Repository\PageChoiceRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Page suggestions from stored records, per tour:
 * - every tour: its open points first (OpenPointReaderInterface, points whose
 *   skill has the tour's slug), in the reader's order;
 * - the SEO tour (`seo-optimieren`) then: pages without a meta description,
 *   with a title shorter than 30 characters, or without a social-media image
 *   (PageChoiceRepository::findWithSeoDefects()), newest change first;
 * - any other tour: nothing more.
 * Each suggestion passes PageChoiceRepository::findEditable() for its
 * language; a page with an open point and an SEO defect appears once, for
 * its open point.
 */
final readonly class DeterministicPageSuggestionProvider implements PageSuggestionProviderInterface
{
    /** The slug of the tour the SEO checks belong to. */
    public const SEO_SLUG = 'seo-optimieren';

    /** Open points read, at most, to find the ones of one tour. */
    private const OPEN_POINT_WINDOW = 50;

    private const LABELS = 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_dashboard.xlf:suggestion.reason.';

    public function __construct(
        private OpenPointReaderInterface $openPoints,
        private PageChoiceRepository $pages,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function suggest(string $skill, int $limit = self::MAX_SUGGESTIONS): array
    {
        $limit = min($limit, self::MAX_SUGGESTIONS);
        if ($limit < 1) {
            return [];
        }

        $slug = $this->slug($skill);
        $suggestions = [];

        foreach ($this->openPoints->findOpen(self::OPEN_POINT_WINDOW) as $point) {
            $key = $point->pageUid . ':' . $point->languageUid;
            if ($this->slug($point->skill) !== $slug || isset($suggestions[$key])) {
                continue;
            }

            $page = $this->pages->findEditable($point->pageUid, $point->languageUid);
            if ($page !== null) {
                $suggestions[$key] = new PageSuggestion($page['uid'], $point->languageUid, $page['title'], $this->reason('openPoint'));
                if (count($suggestions) === $limit) {
                    return array_values($suggestions);
                }
            }
        }

        if ($slug === self::SEO_SLUG) {
            foreach ($this->pages->findWithSeoDefects($limit + count($suggestions)) as $page) {
                $key = $page['uid'] . ':0';
                if (isset($suggestions[$key])) {
                    continue;
                }

                $reasons = array_map($this->reason(...), $page['defects']);
                $suggestions[$key] = new PageSuggestion($page['uid'], 0, $page['title'], implode(', ', $reasons));
                if (count($suggestions) === $limit) {
                    break;
                }
            }
        }

        return array_values($suggestions);
    }

    /** The part of a skill identifier after `<source uid>:`, or the identifier itself. */
    private function slug(string $skill): string
    {
        return preg_match('/^\d+:(.+)$/', $skill, $match) === 1 ? $match[1] : $skill;
    }

    private function reason(string $key): string
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $this->languageServiceFactory
            ->createFromUserPreferences($user instanceof BackendUserAuthentication ? $user : null)
            ->sL(self::LABELS . $key);
    }
}
