<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'MCP Server',
        'label' => 'name',
        'label_alt' => 'server_key',
        'label_alt_force' => true,
        'sortby' => 'sorting',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'adminOnly' => true,
        'rootLevel' => 1,
        'type' => 'transport',
        'iconfile' => 'EXT:nr_mcp_agent/Resources/Public/Icons/Extension.svg',
        'searchFields' => 'name,server_key',
    ],
    'types' => [
        'stdio' => [
            'showitem' => implode(',', [
                'hidden, name, server_key, transport',
                '--div--;stdio',
                'command, arguments',
                '--div--;Connection Status',
                'connection_status, connection_checked, connection_error',
            ]),
        ],
        'sse' => [
            'showitem' => implode(',', [
                'hidden, name, server_key, transport',
                '--div--;SSE',
                'url, auth_token',
                '--div--;Connection Status',
                'connection_status, connection_checked, connection_error',
            ]),
        ],
    ],
    'columns' => [
        'hidden' => [
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.enabled',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'items' => [
                    [
                        'label' => '',
                        'invertStateDisplay' => true,
                    ],
                ],
            ],
        ],
        'name' => [
            'label' => 'Name',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'max' => 255,
                'required' => true,
                'eval' => 'trim',
            ],
        ],
        'server_key' => [
            'label' => 'Server Key',
            'description' => 'Unique identifier used as tool name prefix (e.g. "typo3"). Lowercase letters, digits, and underscores only.',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 64,
                'required' => true,
                'eval' => 'trim,unique,lower,nospace',
            ],
        ],
        'transport' => [
            'label' => 'Transport',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'stdio (subprocess)', 'value' => 'stdio'],
                    ['label' => 'SSE (HTTP)', 'value' => 'sse'],
                ],
                'default' => 'stdio',
            ],
        ],
        'command' => [
            'label' => 'Command',
            'description' => 'Path to the binary. Leave empty to use vendor/bin/typo3.',
            'config' => [
                'type' => 'input',
                'size' => 60,
                'max' => 1000,
                'eval' => 'trim',
            ],
        ],
        'arguments' => [
            'label' => 'Arguments',
            'description' => 'One argument per line.',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 4,
            ],
        ],
        'url' => [
            'label' => 'URL',
            'description' => 'SSE endpoint URL.',
            'config' => [
                'type' => 'input',
                'size' => 60,
                'max' => 2000,
                'eval' => 'trim',
            ],
        ],
        'auth_token' => [
            'label' => 'Auth Token',
            'description' => 'Bearer token for SSE authentication.',
            'config' => [
                'type' => 'password',
            ],
        ],
        'connection_status' => [
            'label' => 'Connection Status',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'connection_checked' => [
            'label' => 'Last Checked',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'connection_error' => [
            'label' => 'Connection Error',
            'config' => [
                'type' => 'text',
                'readOnly' => true,
                'cols' => 60,
                'rows' => 3,
            ],
        ],
    ],
];
