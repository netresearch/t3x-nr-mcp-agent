<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/*
 * "Decorative image" on an image reference (ADR-026): set per use of an
 * image, never on the file, so it lives on sys_file_reference and not on
 * sys_file_metadata. A decorative image is output with alt="" and the
 * "missing alt texts" check counts it as done.
 *
 * Like the core's "Alternative text", the field is an exclude field: an
 * editor sees it once their group is granted it. Each language version of a
 * reference carries its own value (no l10n_mode).
 */
ExtensionManagementUtility::addTCAcolumns('sys_file_reference', [
    'tx_nrmcpagent_decorative' => [
        'exclude'     => true,
        'label'       => 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_db.xlf:sys_file_reference.tx_nrmcpagent_decorative',
        'description' => 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_db.xlf:sys_file_reference.tx_nrmcpagent_decorative.description',
        'config'      => [
            'type'       => 'check',
            'renderType' => 'checkboxToggle',
            'default'    => 0,
        ],
    ],
]);

// In the palette that holds "Alternative text", directly after it.
ExtensionManagementUtility::addFieldsToPalette('sys_file_reference', 'imageoverlayPalette', 'tx_nrmcpagent_decorative', 'after:alternative');
