<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Dashboard;

use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Service\Assistant\ChatStartUriBuilder;
use Netresearch\NrMcpAgent\Service\Assistant\QuickTaskProviderInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;

/**
 * "Frequent tasks": configured shortcuts into the chat, one row each, each
 * starting one skill. A task that works on one page opens the page module
 * and starts the skill in the chat beside it, which asks for the page
 * (chat-start.js); any other task opens the chat module on it, as before.
 * Every row is a link to the chat module that starts the skill, so it works
 * without the chat panel too.
 */
final class QuickTasksWidget extends AssistantListWidget
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        WidgetConfigurationInterface $configuration,
        BackendViewFactory $backendViewFactory,
        private readonly ChatToolbarItem $chatAccess,
        private readonly QuickTaskProviderInterface $tasks,
        private readonly ChatStartUriBuilder $chatStart,
        array $options = [],
    ) {
        parent::__construct($configuration, $backendViewFactory, $options);
    }

    protected function templateName(): string
    {
        return 'Widget/QuickTasksWidget';
    }

    /**
     * @return array{available: bool, rows: list<array{label: string, skill: string, needsPage: bool, uri: string}>}
     */
    public function templateVariables(): array
    {
        if (!$this->chatAccess->checkAccess()) {
            return ['available' => false, 'rows' => []];
        }

        $rows = [];
        foreach ($this->tasks->getQuickTasks() as $task) {
            $rows[] = [
                'label'     => $task->label,
                'skill'     => $task->skill,
                'needsPage' => $task->needsPage,
                'uri'       => $this->chatStart->build($task->skill),
            ];
        }

        return ['available' => true, 'rows' => $rows];
    }
}
