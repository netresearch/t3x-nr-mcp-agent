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
use Netresearch\NrMcpAgent\Service\Assistant\QuickTaskProviderInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;

/**
 * "Frequent tasks": configured shortcuts into the chat, each starting one
 * skill. A task that works on one page asks for the page first, from the
 * same choice of pages as "Improve a page"; any other task is a link.
 */
final class QuickTasksWidget extends AbstractAssistantWidget
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        WidgetConfigurationInterface $configuration,
        BackendViewFactory $backendViewFactory,
        private readonly ChatToolbarItem $chatAccess,
        private readonly QuickTaskProviderInterface $tasks,
        private readonly PageChoiceRepository $pages,
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
     * @return array{available: bool, tasks: list<array{label: string, skill: string, needsPage: bool, uri: string}>, pages: list<array{uid: int, title: string}>, form: array{action: string, hidden: array<string, string>, skillField: string, pageField: string, languageField: string}|null, idPrefix: string}
     */
    public function templateVariables(): array
    {
        if (!$this->chatAccess->checkAccess()) {
            return ['available' => false, 'tasks' => [], 'pages' => [], 'form' => null, 'idPrefix' => ''];
        }

        $tasks = [];
        $needsPages = false;
        foreach ($this->tasks->getQuickTasks() as $task) {
            $needsPages = $needsPages || $task->needsPage;
            $tasks[] = [
                'label' => $task->label,
                'skill' => $task->skill,
                'needsPage' => $task->needsPage,
                'uri' => $task->needsPage ? '' : $this->chatStart->build($task->skill),
            ];
        }

        return [
            'available' => true,
            'tasks' => $tasks,
            'pages' => $needsPages ? $this->pages->findRecentlyChanged(ImprovePageWidget::PAGE_LIMIT) : [],
            'form' => $this->chatStart->formTarget(),
            'idPrefix' => 'nr-mcp-agent-tasks-' . bin2hex(random_bytes(4)),
        ];
    }
}
