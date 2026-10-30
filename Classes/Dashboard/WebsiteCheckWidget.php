<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Dashboard;

use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltTextList;
use Netresearch\NrMcpAgent\Service\Assistant\ChatStartUriBuilder;
use Netresearch\NrMcpAgent\Service\Assistant\DescriptionTour;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;

/**
 * "Website prüfen": the site-wide checks, one row each.
 * - "Fehlende Alt-Texte" (ADR-026), always listed, also when nothing is open:
 *   the list then says that every image has an alt text. Site-wide: it opens
 *   the list.
 * - "Seiten ohne Meta Description", listed while a page version the user may
 *   fix lacks one and the SEO tour is available to them (DescriptionTour):
 *   it starts the tour on the most recently changed of them, beside the page
 *   module (chat-start.js), as the tile in "Recommended for your website"
 *   does.
 */
final class WebsiteCheckWidget extends AssistantListWidget
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        WidgetConfigurationInterface $configuration,
        BackendViewFactory $backendViewFactory,
        private readonly ChatToolbarItem $chatAccess,
        private readonly MissingAltTextList $altTexts,
        private readonly ChatStartUriBuilder $chatStart,
        private readonly ?DescriptionTour $descriptions = null,
        array $options = [],
    ) {
        parent::__construct($configuration, $backendViewFactory, $options);
    }

    protected function templateName(): string
    {
        return 'Widget/WebsiteCheckWidget';
    }

    /**
     * @return array{available: bool, checks: list<array{label: string, uri: string, start: array{skill: string, page: int, language: int}|null}>}
     */
    public function templateVariables(): array
    {
        if (!$this->chatAccess->checkAccess()) {
            return ['available' => false, 'checks' => []];
        }

        $checks = [['label' => 'websiteCheck.altTexts', 'uri' => $this->altTexts->uri(), 'start' => null]];
        $start  = ($this->descriptions?->summary() ?? ['start' => null])['start'];
        if ($start !== null) {
            $checks[] = [
                'label' => 'websiteCheck.descriptions',
                'uri'   => $this->chatStart->build($start['skill'], $start['pageUid'], $start['languageUid']),
                'start' => ['skill' => $start['skill'], 'page' => $start['pageUid'], 'language' => $start['languageUid']],
            ];
        }

        return ['available' => true, 'checks' => $checks];
    }
}
