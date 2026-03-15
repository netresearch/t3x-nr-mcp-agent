<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'AI Chat Conversation',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'readOnly' => true,
        'adminOnly' => true,
        'rootLevel' => 1,
        'iconfile' => 'EXT:nr_mcp_agent/Resources/Public/Icons/Extension.svg',
        'searchFields' => 'title',
    ],
    'types' => [
        '0' => ['showitem' => 'title, be_user, status, message_count, error_message, archived, pinned'],
    ],
    'columns' => [
        'title' => [
            'label' => 'Title',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'be_user' => [
            'label' => 'Backend User',
            'config' => ['type' => 'group', 'allowed' => 'be_users', 'maxitems' => 1, 'readOnly' => true],
        ],
        'status' => [
            'label' => 'Status',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'message_count' => [
            'label' => 'Messages',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'error_message' => [
            'label' => 'Error',
            'config' => ['type' => 'text', 'readOnly' => true],
        ],
        'archived' => [
            'label' => 'Archived',
            'config' => ['type' => 'check', 'readOnly' => true],
        ],
        'pinned' => [
            'label' => 'Pinned',
            'config' => ['type' => 'check', 'readOnly' => true],
        ],
    ],
];
