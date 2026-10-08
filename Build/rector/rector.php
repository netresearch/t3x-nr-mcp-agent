<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Property\RemoveUnusedPrivatePropertyRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;

$configure = require_once __DIR__ . '/../../.Build/vendor/netresearch/typo3-ci-workflows/config/rector/rector.php';

return static function (RectorConfig $rectorConfig) use ($configure): void {
    // Shared org base config: paths, code-quality sets, rule skips,
    // and the package's ergebnis-free phpstan-rector.neon.
    $configure($rectorConfig, __DIR__ . '/../..');

    // UP_TO_TYPO3_13 matches the lowest supported core major (typo3/cms-core
    // ^13.4 || ^14.3); fleet convention: repos still supporting v13 use the
    // v13 level set (see t3x-nr-vault, t3x-nr-image-optimize).
    $rectorConfig->sets([
        Typo3LevelSetList::UP_TO_TYPO3_13,
    ]);

    $rectorConfig->skip([
        // crdate hydrated from DB, kept for completeness
        RemoveUnusedPrivatePropertyRector::class => [
            __DIR__ . '/../../Classes/Domain/Model/Conversation.php',
        ],
        // Verbose instanceof checks not preferred over null checks
        FlipTypeControlToUseExclusiveTypeRector::class,
        // nr-llm's ApprovalPreviewHeadings exists from 0.40 on; the recogniser
        // names it as a string behind class_exists() so that nr-llm 0.37 to
        // 0.39 stay supported. Rector proposes ::class whenever 0.40 or later
        // is installed.
        StringClassNameToClassConstantRector::class => [
            __DIR__ . '/../../Classes/Service/NrLlmPreviewHeadingRecogniser.php',
        ],
    ]);
};
