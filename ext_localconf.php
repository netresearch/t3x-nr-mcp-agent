<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

use Netresearch\NrMcpAgent\Hooks\DashboardCountInvalidation;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
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

// The dashboard's page counts (DescriptionTour): per user, workspace and
// languages, five minutes at most, and dropped whenever DataHandler writes a
// page (DashboardCountInvalidation). Each dashboard widget is rendered in a
// request of its own, so a count two widgets show is read once. In the "pages"
// group, so clearing the page caches clears them too.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrmcpagent_dashboard'] ??= [
    'frontend' => VariableFrontend::class,
    'backend'  => Typo3DatabaseBackend::class,
    'options'  => ['defaultLifetime' => 300],
    'groups'   => ['pages'],
];
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['nr_mcp_agent_dashboard'] = DashboardCountInvalidation::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']['nr_mcp_agent_dashboard']  = DashboardCountInvalidation::class;

// A decorative image (ADR-026) is output with alt="" by fluid_styled_content.
// On a site with sys_template records the partial path is added right after
// fluid_styled_content's static template, and only there: a content rendering
// of its own (bootstrap_package and the like) keeps its image partial. A site
// with site sets opts in with the set netresearch/nr-mcp-agent-decorative-images
// (Configuration/Sets/DecorativeImages), which carries the same line.
ExtensionManagementUtility::addTypoScript(
    'nr_mcp_agent',
    'setup',
    'lib.contentElement.partialRootPaths.5 = EXT:nr_mcp_agent/Resources/Private/FluidStyledContent/Partials/',
    'fluidstyledcontent/Configuration/TypoScript/',
);
