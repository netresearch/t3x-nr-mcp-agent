<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Information\Typo3Version;

// TYPO3 v14 ships a redesigned backend with light/dark mode: use the flat,
// three-color module icon that adapts via currentColor. v13 uses the
// colored (teal tile) variant that matches the classic module menu.
$moduleIcon = (new Typo3Version())->getMajorVersion() >= 14
    ? 'EXT:nr_mcp_agent/Resources/Public/Icons/ModuleIcon.svg'
    : 'EXT:nr_mcp_agent/Resources/Public/Icons/ModuleIcon.legacy.svg';

return [
    'module-nr-mcp-agent' => [
        'provider' => SvgIconProvider::class,
        'source' => $moduleIcon,
    ],
    'toolbar-nr-mcp-agent' => [
        'provider' => SvgIconProvider::class,
        'source' => $moduleIcon,
    ],
];
