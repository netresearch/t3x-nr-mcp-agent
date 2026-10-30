<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

use Netresearch\NrMcpAgent\Dashboard\AiChatWidget;
use Netresearch\NrMcpAgent\Dashboard\CreateContentWidget;
use Netresearch\NrMcpAgent\Dashboard\EditPageWidget;
use Netresearch\NrMcpAgent\Dashboard\ImprovePageWidget;
use Netresearch\NrMcpAgent\Dashboard\PromptWidget;
use Netresearch\NrMcpAgent\Dashboard\QuickTasksWidget;
use Netresearch\NrMcpAgent\Dashboard\RecommendationsWidget;
use Netresearch\NrMcpAgent\Dashboard\WebsiteCheckWidget;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Dashboard\WidgetRegistry;

/**
 * The dashboard widgets are registered only when EXT:dashboard is installed:
 * their classes implement the dashboard's interfaces, which do not exist without
 * it (Services.yaml excludes Classes/Dashboard/ for that reason). The
 * dashboard's own compiler pass adds the widget configuration argument.
 */
return static function (ContainerConfigurator $configurator): void {
    // The package manager is not available while the container is built, so
    // "is EXT:dashboard loaded" cannot be asked here. What the registration
    // actually needs is the dashboard's classes, and those exist exactly when
    // the dashboard is installed.
    if (!class_exists(WidgetRegistry::class)) {
        return;
    }

    $configurator->services()
        ->set('dashboard.widget.nrMcpAgentAiChat')
        ->class(AiChatWidget::class)
        ->autowire()
        ->tag('dashboard.widget', [
            'identifier'     => 'nrMcpAgentAiChat',
            'groupNames'     => 'general',
            'title'          => 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf:widget.title',
            'description'    => 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf:widget.description',
            'iconIdentifier' => 'module-nr-mcp-agent',
            // Full width, for title, context, status and time in one row
            // (ADR-027). Ten rows with a context line are taller than
            // `large`, the tallest height there is; the list scrolls in a
            // region of its own.
            'height' => 'large',
            'width'  => 'large',
        ]);

    // The AI assistant widgets (group and preset "AI Assistant",
    // Configuration/Backend/DashboardWidgetGroups.php and DashboardPresets.php).
    $ll = 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_dashboard.xlf:';
    // Height and width as the design lays them out on the dashboard's four
    // columns: the question across the whole width, "Edit a page" and
    // "Create content" side by side, the recommendations across the whole
    // width, then "Improve a page" and "Check website" side by side.
    $assistantWidgets = [
        'nrMcpAgentPrompt'          => [PromptWidget::class, 'prompt', 'small', 'large'],
        'nrMcpAgentEditPage'        => [EditPageWidget::class, 'editPage', 'small', 'medium'],
        'nrMcpAgentCreateContent'   => [CreateContentWidget::class, 'create', 'small', 'medium'],
        'nrMcpAgentImprovePage'     => [ImprovePageWidget::class, 'improve', 'medium', 'medium'],
        'nrMcpAgentQuickTasks'      => [QuickTasksWidget::class, 'tasks', 'medium', 'small'],
        'nrMcpAgentRecommendations' => [RecommendationsWidget::class, 'recommendations', 'medium', 'large'],
        'nrMcpAgentWebsiteCheck'    => [WebsiteCheckWidget::class, 'websiteCheck', 'medium', 'medium'],
    ];
    foreach ($assistantWidgets as $identifier => [$class, $labelPrefix, $height, $width]) {
        $configurator->services()
            ->set('dashboard.widget.' . $identifier)
            ->class($class)
            ->autowire()
            ->tag('dashboard.widget', [
                'identifier'     => $identifier,
                'groupNames'     => 'nrMcpAgent',
                'title'          => $ll . $labelPrefix . '.title',
                'description'    => $ll . $labelPrefix . '.description',
                'iconIdentifier' => 'module-nr-mcp-agent',
                'height'         => $height,
                'width'          => $width,
            ]);
    }
};
