<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Dashboard;

use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Service\Assistant\ChatStartUriBuilder;
use Netresearch\NrMcpAgent\Service\Assistant\GuidedSkill;
use Netresearch\NrMcpAgent\Service\Assistant\GuidedSkillProviderInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;

/**
 * "Improve a page": one row per guided tour. A row opens the page module and
 * starts the tour in the chat beside it; the tour asks for the page, which
 * the editor chooses in the page tree (chat-start.js, ADR-023 page choice).
 *
 * The tours come from GuidedSkillProviderInterface. Each row is a link to the
 * chat module that starts the tour (ChatStartUriBuilder), so a click where the
 * chat panel is not available, or one that opens a new tab, starts it there.
 *
 * Who may use the chat is decided as for the toolbar button and the "AI Chat"
 * widget; for anyone else the widget says the chat is not available.
 */
final class ImprovePageWidget extends AssistantListWidget
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        WidgetConfigurationInterface $configuration,
        BackendViewFactory $backendViewFactory,
        private readonly ChatToolbarItem $chatAccess,
        private readonly GuidedSkillProviderInterface $skills,
        private readonly ChatStartUriBuilder $chatStart,
        array $options = [],
    ) {
        parent::__construct($configuration, $backendViewFactory, $options);
    }

    protected function templateName(): string
    {
        return 'Widget/ImprovePageWidget';
    }

    /**
     * @return array{available: bool, rows: list<array{label: string, description: string, uri: string, skill: string}>}
     */
    public function templateVariables(): array
    {
        if (!$this->chatAccess->checkAccess()) {
            return ['available' => false, 'rows' => []];
        }

        return ['available' => true, 'rows' => array_map(
            fn (GuidedSkill $skill): array => [
                'label'       => $skill->title,
                'description' => $skill->description,
                'uri'         => $this->chatStart->build($skill->identifier),
                'skill'       => $skill->identifier,
            ],
            $this->skills->getGuidedSkills(),
        )];
    }
}
