<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Dashboard;

use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltTextList;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;

/**
 * "Website prüfen": the site-wide checks (ADR-026). One for now, "Fehlende
 * Alt-Texte", always listed, also when nothing is open: the list then says
 * that every image has an alt text.
 */
final class WebsiteCheckWidget extends AbstractAssistantWidget
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        WidgetConfigurationInterface $configuration,
        BackendViewFactory $backendViewFactory,
        private readonly ChatToolbarItem $chatAccess,
        private readonly MissingAltTextList $altTexts,
        array $options = [],
    ) {
        parent::__construct($configuration, $backendViewFactory, $options);
    }

    protected function templateName(): string
    {
        return 'Widget/WebsiteCheckWidget';
    }

    /**
     * @return array{available: bool, checks: list<array{label: string, uri: string}>}
     */
    public function templateVariables(): array
    {
        if (!$this->chatAccess->checkAccess()) {
            return ['available' => false, 'checks' => []];
        }

        return ['available' => true, 'checks' => [
            ['label' => 'websiteCheck.altTexts', 'uri' => $this->altTexts->uri()],
        ]];
    }
}
