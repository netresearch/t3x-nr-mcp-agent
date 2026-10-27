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
use Netresearch\NrMcpAgent\Service\Assistant\OpenPointReaderInterface;
use Netresearch\NrMcpAgent\Service\Assistant\SkillLabels;
use Netresearch\NrMcpAgent\Service\Assistant\SkillResolverInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;

/**
 * "Recommended for your website": the improvement points guided tours left
 * open, each a link that starts the matching tour on its page.
 *
 * The points come from OpenPointReaderInterface. Whatever the reader returns,
 * a point is shown only when the editor may access its page, the chat would
 * start on it in the point's language (PageChoiceRepository::mayStartIn()),
 * and its skill has a title and resolves (SkillResolverInterface); its
 * summary is model-written text and is rendered escaped. The link starts the
 * resolved skill on the page in the point's language.
 *
 * Above the points, a tile for the check of missing alt texts (ADR-026):
 * how many images lack one, on how many pages and in how many records, and
 * a link to the list. It is left out while none is open.
 */
final class RecommendationsWidget extends AbstractAssistantWidget
{
    /** How many points the widget lists. */
    public const LIMIT = 5;

    /**
     * How many points are read to fill the list: points the widget leaves out
     * (a page the editor may not access, a skill that is gone or unnamed, a
     * language the chat would not start in) must not take its places.
     */
    public const WINDOW = 50;

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
        array $options = [],
    ) {
        parent::__construct($configuration, $backendViewFactory, $options);
    }

    protected function templateName(): string
    {
        return 'Widget/RecommendationsWidget';
    }

    /**
     * @return array{available: bool, points: list<array{summary: string, pageTitle: string, skillTitle: string, uri: string}>, altTexts: array{count: int, found: string, uri: string}|null}
     */
    public function templateVariables(): array
    {
        if (!$this->chatAccess->checkAccess()) {
            return ['available' => false, 'points' => [], 'altTexts' => null];
        }

        $points = [];
        foreach ($this->openPoints->findOpen(self::WINDOW) as $point) {
            $page       = $this->pages->findAccessible($point->pageUid);
            $skillTitle = $this->labels->title($point->skill);
            $skill      = $skillTitle === null ? null : $this->resolver->resolve($point->skill);
            if ($page === null || $skill === null || $skillTitle === null || !$this->pages->mayStartIn($point->pageUid, $point->languageUid)) {
                continue;
            }

            $points[] = [
                'summary'    => $point->summary,
                'pageTitle'  => $page['title'],
                'skillTitle' => $skillTitle,
                'uri'        => $this->chatStart->build($skill, $point->pageUid, $point->languageUid),
            ];
            if (count($points) === self::LIMIT) {
                break;
            }
        }

        return ['available' => true, 'points' => $points, 'altTexts' => $this->altTextTile()];
    }

    /**
     * The alt-text tile, or null when no image lacks one.
     *
     * @return array{count: int, found: string, uri: string}|null
     */
    private function altTextTile(): ?array
    {
        $summary = $this->altTexts?->summary();
        if (!$this->altTexts instanceof MissingAltTextList || $summary === null || $summary['count'] === 0) {
            return null;
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

        return ['count' => $summary['count'], 'found' => $found, 'uri' => $this->altTexts->uri()];
    }

    private function label(string $key): string
    {
        $language = $GLOBALS['LANG'] ?? null;
        $label    = $language instanceof LanguageService ? $language->sL('LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_dashboard.xlf:' . $key) : '';

        return $label !== '' ? $label : $key;
    }
}
