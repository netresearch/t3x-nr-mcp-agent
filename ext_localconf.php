<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

defined('TYPO3') || die();

// Lets an editor decide approvals in their own chat conversations (ADR-021).
// Assigned per backend group under "Custom module options"; checked as
// `check('custom_options', 'tx_nrmcpagent:approve_own_changes')`. The item key
// has no ':', '|' or ',', which TYPO3 strips when it renders the options.
$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['tx_nrmcpagent'] = [
    'header' => 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf:permission.header',
    'items' => [
        'approve_own_changes' => [
            'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf:permission.approveOwn',
            'actions-check',
            'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf:permission.approveOwn.description',
        ],
    ],
];

