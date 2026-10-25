<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

// A record type like a news article: an image field, content elements inside
// it, hidden, start and end time, editlock, languages and workspaces.
return [
    'ctrl' => [
        'title'                    => 'Test articles',
        'label'                    => 'title',
        'tstamp'                   => 'tstamp',
        'crdate'                   => 'crdate',
        'delete'                   => 'deleted',
        'editlock'                 => 'editlock',
        'languageField'            => 'sys_language_uid',
        'transOrigPointerField'    => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'versioningWS'             => true,
        'enablecolumns'            => [
            'disabled'  => 'hidden',
            'starttime' => 'starttime',
            'endtime'   => 'endtime',
        ],
    ],
    'columns' => [
        'title' => [
            'label'  => 'Title',
            'config' => ['type' => 'input'],
        ],
        'image' => [
            'exclude' => true,
            'label'   => 'Images',
            'config'  => ['type' => 'file', 'allowed' => 'common-image-types'],
        ],
        'teaser_image' => [
            'label'  => 'Teaser image',
            'config' => ['type' => 'file', 'allowed' => 'common-image-types'],
        ],
        'content_elements' => [
            'label'  => 'Content elements',
            'config' => [
                'type'          => 'inline',
                'foreign_table' => 'tt_content',
                'foreign_field' => 'tx_alttexttest_item',
            ],
        ],
    ],
    'types' => [
        '0' => ['showitem' => 'title, image, teaser_image, content_elements, hidden, starttime, endtime, editlock, sys_language_uid, l10n_parent'],
    ],
];
