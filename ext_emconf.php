<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

$EM_CONF[$_EXTKEY] = [
    'title' => 'AI Chat',
    'description' => 'AI chat assistant for the TYPO3 backend, using nr-llm and an MCP server for tool calling.',
    'category' => 'module',
    'version' => '0.16.1',
    'state' => 'alpha',
    'author' => 'Netresearch DTT GmbH',
    'author_email' => 'typo3@netresearch.de',
    'author_company' => 'Netresearch DTT GmbH',
    'constraints' => [
        'depends' => [
            'php' => '8.2.0-8.99.99',
            'typo3' => '13.4.0-14.3.99',
            'filelist' => '13.4.0-14.3.99',
            'nr_llm' => '0.37.0-0.40.99',
        ],
        'suggests' => [
            'dashboard' => '13.4.0-14.3.99',
        ],
    ],
];
