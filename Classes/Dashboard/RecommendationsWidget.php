<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Dashboard;

use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Domain\Repository\PageChoiceRepository;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltTextList;
use Netresearch\NrMcpAgent\Service\Assistant\ChatStartUriBuilder;
use Netresearch\NrMcpAgent\Service\Assistant\DescriptionTour;
use Netresearch\NrMcpAgent\Service\Assistant\OpenPointReaderInterface;
use Netresearch\NrMcpAgent\Service\Assistant\SkillLabels;
use Netresearch\NrMcpAgent\Service\Assistant\SkillResolverInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;

/**
 * "Recommended for your website": number tiles for what the extension can
 * count exactly, then the improvement points guided tours left open.
 *
 * The tiles, each with one action:
 * - images without an alt text (ADR-026, MissingAltTextList::summary()): on
 *   how many pages and in how many records; "View" opens the list;
 * - page versions without a meta description (DescriptionTour): the action
 *   starts the SEO tour on the most recently changed of them, beside the
 *   page module (chat-start.js); left out where the user may not write a
 *   meta description or the SEO tour is not available to them.
 * A tile whose count is 0 says so instead of offering an action.
 * Nothing else is counted: there is no broken-link or outdated-content count
 * the extension could show exactly.
 *
 * The points come from OpenPointReaderInterface. Whatever the reader returns,
 * a point is shown only when the editor may access its page, the chat would
 * start on it in the point's language (PageChoiceRepository::mayStartIn()),
 * and its skill has a title and resolves (SkillResolverInterface); its
 * summary is model-written text and is rendered escaped. A point starts the
 * resolved skill on the page in the point's language, beside the page module.
 */
final class RecommendationsWidget extends AssistantListWidget
{
    /** How many points the widget lists. */
    public const LIMIT = 5;

    /**
     * How many points are read to fill the list: points the widget leaves out
     * (a page the editor may not access, a skill that is gone or unnamed, a
     * language the chat would not start in) must not take its places.
     */
    public const WINDOW = 50;

    private const LL = 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_dashboard.xlf:';

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        WidgetConfigurationInterface $configuration,
        BackendViewFactory $backendViewFactory,
        private readonly ChatToolbarItem $chatAccess,
        private readonly OpenPointReaderInterface $openPoints,
        private readonly PageChoiceRepository $pages,
        private readonly SkillLabels $labels,
        private readonly SkillResolverInterface $resolver,
        private readonly ChatStartUriBuilder $chatStart,
        private readonly ?MissingAltTextList $altTexts = null,
        private readonly ?DescriptionTour $descriptions = null,
        array $options = [],
    ) {
        parent::__construct($configuration, $backendViewFactory, $options);
    }

    protected function templateName(): string
    {
        return 'Widget/RecommendationsWidget';
    }

    /**
     * @return array{available: bool, intro: string, tiles: list<array<string, mixed>>, points: list<array{summary: string, start: string, uri: string, skill: string, page: int, language: int}>}
     */
    public function templateVariables(): array
    {
        if (!$this->chatAccess->checkAccess()) {
            return ['available' => false, 'intro' => '', 'tiles' => [], 'points' => []];
        }

        $tiles  = array_values(array_filter([$this->altTextTile(), $this->descriptionTile()], static fn (?array $tile): bool => $tile !== null));
        $points = $this->points();
        $open   = $points !== [] || array_filter($tiles, static fn (array $tile): bool => $tile['count'] > 0) !== [];

        return [
            'available' => true,
            'intro'     => $this->label(match (true) {
                $open         => 'recommendations.intro',
                $tiles !== [] => 'recommendations.allDone',
                default       => 'recommendations.empty',
            }),
            'tiles'  => $tiles,
            'points' => $points,
        ];
    }

    /**
     * @return list<array{summary: string, start: string, uri: string, skill: string, page: int, language: int}>
     */
    private function points(): array
    {
        $points = [];
        foreach ($this->openPoints->findOpen(self::WINDOW) as $point) {
            $page       = $this->pages->findAccessible($point->pageUid);
            $skillTitle = $this->labels->title($point->skill);
            $skill      = $skillTitle === null ? null : $this->resolver->resolve($point->skill);
            if ($page === null || $skill === null || $skillTitle === null || !$this->pages->mayStartIn($point->pageUid, $point->languageUid)) {
                continue;
            }

            $points[] = [
                'summary'  => $point->summary,
                'start'    => sprintf($this->label('recommendations.start'), $skillTitle, $page['title']),
                'uri'      => $this->chatStart->build($skill, $point->pageUid, $point->languageUid),
                'skill'    => $skill,
                'page'     => $point->pageUid,
                'language' => $point->languageUid,
            ];
            if (count($points) === self::LIMIT) {
                break;
            }
        }

        return $points;
    }

    /**
     * The alt-text tile: the count, where the images are, and the list.
     *
     * @return array<string, mixed>|null
     */
    private function altTextTile(): ?array
    {
        $summary = $this->altTexts?->summary();
        if (!$this->altTexts instanceof MissingAltTextList || $summary === null) {
            return null;
        }

        $count = $summary['count'];
        $label = $this->label($count === 1 ? 'altTexts.labelOne' : 'altTexts.label');
        if ($count === 0) {
            return $this->doneTile('altTexts', $label, $this->label('altTexts.done'));
        }

        $parts = [];
        if ($summary['pages'] > 0) {
            $parts[] = $summary['pages'] === 1 ? $this->label('altTexts.onPage') : sprintf($this->label('altTexts.onPages'), $summary['pages']);
        }

        if ($summary['records'] > 0) {
            $parts[] = $summary['records'] === 1 ? $this->label('altTexts.inRecord') : sprintf($this->label('altTexts.inRecords'), $summary['records']);
        }

        $found = count($parts) === 2
            ? sprintf($this->label('altTexts.foundBoth'), $parts[0], $parts[1])
            : sprintf($this->label('altTexts.foundOne'), $parts[0] ?? '');

        return [
            'key'    => 'altTexts',
            'count'  => $count,
            'label'  => $label,
            'detail' => $found,
            'done'   => '',
            'action' => [
                'text'  => $this->label('altTexts.view'),
                'name'  => sprintf($this->label('tile.actionName'), $this->label('altTexts.view'), $count, $label),
                'uri'   => $this->altTexts->uri(),
                'start' => null,
            ],
        ];
    }

    /**
     * The meta-description tile: the count, and the SEO tour on the most
     * recently changed page version without one.
     *
     * @return array<string, mixed>|null
     */
    private function descriptionTile(): ?array
    {
        $summary = $this->descriptions?->summary();
        if ($summary === null) {
            return null;
        }

        $count = $summary['count'];
        $label = $this->label($this->descriptionLabel($count, $summary['translations']));
        $first = $summary['start'];
        if ($count === 0 || $first === null) {
            return $this->doneTile('descriptions', $label, $this->label('descriptions.done'));
        }

        $pageTitle = $first['title'] !== '' ? $first['title'] : $this->label('page.untitled');

        return [
            'key'    => 'descriptions',
            'count'  => $count,
            'label'  => $label,
            'detail' => sprintf($this->label('descriptions.firstPage'), $pageTitle),
            'done'   => '',
            'action' => [
                'text'  => $this->label('descriptions.start'),
                'name'  => sprintf($this->label('descriptions.startName'), $this->label('descriptions.start'), $count, $label, $pageTitle),
                'uri'   => $this->chatStart->build($first['skill'], $first['pageUid'], $first['languageUid']),
                'start' => ['skill' => $first['skill'], 'page' => $first['pageUid'], 'language' => $first['languageUid']],
            ],
        ];
    }

    /**
     * What the number counts: pages, translations or both; every language
     * version of a page counts on its own.
     */
    private function descriptionLabel(int $count, int $translations): string
    {
        return match (true) {
            $translations === 0      => $count === 1 ? 'descriptions.labelOne' : 'descriptions.label',
            $translations === $count => $count === 1 ? 'descriptions.translationOne' : 'descriptions.translations',
            default                  => 'descriptions.labelMixed',
        };
    }

    /**
     * A tile whose count reached 0: the short success state, no action.
     *
     * @return array<string, mixed>
     */
    private function doneTile(string $key, string $label, string $done): array
    {
        return ['key' => $key, 'count' => 0, 'label' => $label, 'detail' => '', 'done' => $done, 'action' => null];
    }

    private function label(string $key): string
    {
        $language = $GLOBALS['LANG'] ?? null;
        $label    = $language instanceof LanguageService ? $language->sL(self::LL . $key) : '';

        return $label !== '' ? $label : $key;
    }
}
