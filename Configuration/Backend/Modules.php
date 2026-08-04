<?php

declare(strict_types=1);

use Netresearch\NrMcpAgent\Controller\ChatModuleController;

return [
    'nr_mcp_agent_chat' => [
        'parent' => 'tools',
        'position' => ['after' => '*'],
        'access' => 'user',
        'iconIdentifier' => 'module-nr-mcp-agent',
        'labels' => 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => ChatModuleController::class . '::indexAction',
            ],
        ],
    ],
];
