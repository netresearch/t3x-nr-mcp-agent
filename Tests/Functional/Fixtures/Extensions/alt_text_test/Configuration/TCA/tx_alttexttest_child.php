<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

// A content element's inline child with an image, like a carousel item.
return [
    'ctrl' => [
        'title'         => 'Test carousel items',
        'label'         => 'title',
        'tstamp'        => 'tstamp',
        'crdate'        => 'crdate',
        'delete'        => 'deleted',
        'hideTable'     => true,
        'languageField' => 'sys_language_uid',
        'versioningWS'  => true,
        'enablecolumns' => ['disabled' => 'hidden'],
    ],
    'columns' => [
        'title' => [
            'label'  => 'Title',
            'config' => ['type' => 'input'],
        ],
        'image' => [
            'label'  => 'Image',
            'config' => ['type' => 'file', 'allowed' => 'common-image-types'],
        ],
    ],
    'types' => [
        '0' => ['showitem' => 'title, image, hidden, sys_language_uid'],
    ],
];
