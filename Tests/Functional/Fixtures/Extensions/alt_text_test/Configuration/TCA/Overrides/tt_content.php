<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addTCAcolumns('tt_content', [
    'tx_alttexttest_children' => [
        'label'  => 'Carousel items',
        'config' => [
            'type'          => 'inline',
            'foreign_table' => 'tx_alttexttest_child',
            'foreign_field' => 'parent_content',
        ],
    ],
    'tx_alttexttest_item' => [
        'label'  => 'Article',
        'config' => ['type' => 'passthrough'],
    ],
]);

// The content element showing the carousel items.
$GLOBALS['TCA']['tt_content']['types']['carousel'] = [
    'showitem' => 'CType, header, tx_alttexttest_children',
];
