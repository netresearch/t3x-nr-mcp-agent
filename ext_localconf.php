<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || exit;

// Lets an editor decide approvals in their own chat conversations (ADR-021).
// Assigned per backend group under "Custom module options"; checked as
// `check('custom_options', 'tx_nrmcpagent:approve_own_changes')`. The item key
// has no ':', '|' or ',', which TYPO3 strips when it renders the options.
$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['tx_nrmcpagent'] = [
    'header' => 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf:permission.header',
    'items'  => [
        'approve_own_changes' => [
            'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf:permission.approveOwn',
            'actions-check',
            'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf:permission.approveOwn.description',
        ],
    ],
];

// A decorative image (ADR-026) is output with alt="" by fluid_styled_content:
// a partial path for its image partial, added right after the content
// rendering TypoScript (fluid_styled_content's static template or site set).
// Key 5 lies above the core's own path (0) and below the site package's
// (10, `styles.templates.partialRootPath`), so a site package that brings its
// own image partial keeps it and has to add the condition itself
// (Documentation/Installation).
ExtensionManagementUtility::addTypoScript(
    'nr_mcp_agent',
    'setup',
    'lib.contentElement.partialRootPaths.5 = EXT:nr_mcp_agent/Resources/Private/FluidStyledContent/Partials/',
    'defaultContentRendering',
);
