<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

return [
    'dependencies' => ['backend'],
    'tags' => [
        'backend.module',
    ],
    'imports' => [
        '@netresearch/nr-mcp-agent/' => 'EXT:nr_mcp_agent/Resources/Public/JavaScript/',
        'marked' => 'EXT:nr_mcp_agent/Resources/Public/JavaScript/Vendor/marked.esm.js',
        'dompurify' => 'EXT:nr_mcp_agent/Resources/Public/JavaScript/Vendor/dompurify.esm.js',
    ],
];
