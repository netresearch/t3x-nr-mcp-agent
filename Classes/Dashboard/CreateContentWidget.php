<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Dashboard;

use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Service\Assistant\ChatStartUriBuilder;
use Netresearch\NrMcpAgent\Service\Assistant\ContentTypeProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;

/**
 * "Create content": one button per kind of record the chat can create for
 * the user (ContentTypeProvider). A button opens a new chat with the request
 * in its input ("I would like to create a new page."), for the editor to
 * complete or send (chat-start.js): a button never sends a message on the
 * user's behalf. The button is a link to the chat module, which opens without
 * the words where the chat panel is not available.
 */
final class CreateContentWidget extends AssistantListWidget
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        WidgetConfigurationInterface $configuration,
        BackendViewFactory $backendViewFactory,
        private readonly ChatToolbarItem $chatAccess,
        private readonly ContentTypeProvider $types,
        private readonly UriBuilder $uriBuilder,
        array $options = [],
    ) {
        parent::__construct($configuration, $backendViewFactory, $options);
    }

    protected function templateName(): string
    {
        return 'Widget/CreateContentWidget';
    }

    /**
     * @return array{available: bool, types: list<string>, uri: string}
     */
    public function templateVariables(): array
    {
        if (!$this->chatAccess->checkAccess()) {
            return ['available' => false, 'types' => [], 'uri' => ''];
        }

        return [
            'available' => true,
            'types'     => $this->types->available(),
            'uri'       => (string) $this->uriBuilder->buildUriFromRoute(ChatStartUriBuilder::ROUTE),
        ];
    }
}
