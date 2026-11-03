<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

use Netresearch\NrMcpAgent\Controller\ChatModuleController;
use Netresearch\NrMcpAgent\Controller\MissingAltTextsController;
use Netresearch\NrMcpAgent\Controller\OverviewController;

return [
    'nr_mcp_agent_chat' => [
        // The AI section nr_llm registers (its ADR-183), beside nr_llm's own
        // modules (ADR-031). The identifier and path are unchanged.
        'parent'         => 'netresearch_ai',
        'position'       => ['after' => '*'],
        'access'         => 'user',
        'iconIdentifier' => 'module-nr-mcp-agent',
        'labels'         => 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_mod.xlf',
        'routes'         => [
            // The overview (ADR-031). It forwards a link that carries chat
            // parameters to the chat below.
            '_default' => [
                'target' => OverviewController::class . '::indexAction',
            ],
            // The full-page chat: route nr_mcp_agent_chat.conversation. The
            // same chat opens as a panel from the top bar (ADR-011, ADR-024).
            'conversation' => [
                'path'   => '/conversation',
                'target' => ChatModuleController::class . '::indexAction',
            ],
            // "Fehlende Alt-Texte" (ADR-026): route nr_mcp_agent_chat.missingAltTexts.
            'missingAltTexts' => [
                'path'   => '/missing-alt-texts',
                'target' => MissingAltTextsController::class . '::listAction',
            ],
        ],
    ],
];
