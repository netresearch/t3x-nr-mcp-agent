<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Dashboard;

use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;

/**
 * "Edit a page": one button that opens the page module, where the editor
 * chooses the page in the page tree, with a new chat beside it. The chat
 * follows the page the page module shows (NEXT-221 context row), so whatever
 * the editor then asks is about that page (chat-start.js).
 *
 * The button is a link to the page module: without the chat panel it opens
 * the page module alone. Who may use the chat is decided as for the other
 * assistant widgets.
 */
final class EditPageWidget extends AssistantListWidget
{
    /** The page module's route. */
    public const PAGE_MODULE = 'web_layout';

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        WidgetConfigurationInterface $configuration,
        BackendViewFactory $backendViewFactory,
        private readonly ChatToolbarItem $chatAccess,
        private readonly UriBuilder $uriBuilder,
        array $options = [],
    ) {
        parent::__construct($configuration, $backendViewFactory, $options);
    }

    protected function templateName(): string
    {
        return 'Widget/EditPageWidget';
    }

    /**
     * @return array{available: bool, uri: string}
     */
    public function templateVariables(): array
    {
        if (!$this->chatAccess->checkAccess()) {
            return ['available' => false, 'uri' => ''];
        }

        try {
            $uri = (string) $this->uriBuilder->buildUriFromRoute(self::PAGE_MODULE);
        } catch (RouteNotFoundException) {
            // Without the page module there is no page to choose.
            return ['available' => false, 'uri' => ''];
        }

        return ['available' => true, 'uri' => $uri];
    }
}
