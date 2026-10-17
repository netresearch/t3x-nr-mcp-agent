<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Dashboard;

use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Domain\Repository\PageChoiceRepository;
use Netresearch\NrMcpAgent\Service\Assistant\ChatStartUriBuilder;
use Netresearch\NrMcpAgent\Service\Assistant\OpenPointReaderInterface;
use Netresearch\NrMcpAgent\Service\Assistant\SkillLabels;
use Netresearch\NrMcpAgent\Service\Assistant\SkillResolverInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
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
 */
final class RecommendationsWidget extends AbstractAssistantWidget
{
    /** How many points the widget lists. */
    public const LIMIT = 5;

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
        array $options = [],
    ) {
        parent::__construct($configuration, $backendViewFactory, $options);
    }

    protected function templateName(): string
    {
        return 'Widget/RecommendationsWidget';
    }

    /**
     * @return array{available: bool, points: list<array{summary: string, pageTitle: string, skillTitle: string, uri: string}>}
     */
    public function templateVariables(): array
    {
        if (!$this->chatAccess->checkAccess()) {
            return ['available' => false, 'points' => []];
        }

        $points = [];
        foreach ($this->openPoints->findOpen(self::LIMIT) as $point) {
            $page = $this->pages->findAccessible($point->pageUid);
            $skillTitle = $this->labels->title($point->skill);
            $skill = $skillTitle === null ? null : $this->resolver->resolve($point->skill);
            if ($page === null || $skill === null || $skillTitle === null || !$this->pages->mayStartIn($point->pageUid, $point->languageUid)) {
                continue;
            }

            $points[] = [
                'summary' => $point->summary,
                'pageTitle' => $page['title'],
                'skillTitle' => $skillTitle,
                'uri' => $this->chatStart->build($skill, $point->pageUid, $point->languageUid),
            ];
        }

        return ['available' => true, 'points' => $points];
    }
}
