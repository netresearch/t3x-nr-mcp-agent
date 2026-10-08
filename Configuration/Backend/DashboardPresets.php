<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

/**
 * "AI Assistant": a dashboard preset offered when a dashboard is created,
 * holding the AI assistant widgets and the recent chats.
 *
 * The preset is offered to every user who may create a dashboard. A widget
 * their groups may not place is not rendered by the dashboard (core's
 * Dashboard filters through WidgetRegistry::getAvailableWidgets() in 13.4
 * and 14.3), and a
 * user the chat is not available for is told so in each widget.
 */
return [
    'nrMcpAgent' => [
        'title' => 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_dashboard.xlf:preset.title',
        'description' => 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_dashboard.xlf:preset.description',
        'iconIdentifier' => 'module-nr-mcp-agent',
        'defaultWidgets' => [
            'nrMcpAgentImprovePage',
            'nrMcpAgentQuickTasks',
            'nrMcpAgentRecommendations',
            'nrMcpAgentAiChat',
        ],
        'showInWizard' => true,
    ],
];
