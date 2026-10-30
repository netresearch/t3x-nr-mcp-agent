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
        'title'          => 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_dashboard.xlf:preset.title',
        'description'    => 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_dashboard.xlf:preset.description',
        'iconIdentifier' => 'module-nr-mcp-agent',
        // In the order of the design: the free question on top, the ways
        // into a page ("Edit a page") and into new content ("Create content"),
        // the recent chats, the recommendations, then the guided tours for one
        // page ("Improve a page") and the site-wide checks ("Website prüfen",
        // ADR-026). "Frequent tasks" stays available to place, but is not
        // part of the design; "Website bearbeiten" of the design has no flow
        // behind it yet and does not exist.
        'defaultWidgets' => [
            'nrMcpAgentPrompt',
            'nrMcpAgentEditPage',
            'nrMcpAgentCreateContent',
            'nrMcpAgentAiChat',
            'nrMcpAgentRecommendations',
            'nrMcpAgentImprovePage',
            'nrMcpAgentWebsiteCheck',
        ],
        'showInWizard' => true,
    ],
];
