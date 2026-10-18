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
use Rector\TypeDeclaration\Rector\Class_\TypedPropertyFromCreateMockAssignRector;

$configure = require_once __DIR__ . '/../../.Build/vendor/netresearch/typo3-ci-workflows/config/rector/rector.php';

return static function (RectorConfig $rectorConfig) use ($configure): void {
    // Shared org base config: paths (with Tests/), code-quality sets, rule
    // skips, the package's ergebnis-free phpstan-rector.neon, and the PHP and
    // TYPO3 level sets for the lowest supported versions (composer.json:
    // php ^8.2, typo3/cms-core ^13.4 || ^14.3).
    $configure($rectorConfig, __DIR__ . '/../..', [
        'tests' => true,
        'php' => '8.2',
        'typo3' => 13,
    ]);

    $rectorConfig->skip([
        // crdate hydrated from DB, kept for completeness
        RemoveUnusedPrivatePropertyRector::class => [
            __DIR__ . '/../../Classes/Domain/Model/Conversation.php',
        ],
        // Verbose instanceof checks not preferred over null checks
        FlipTypeControlToUseExclusiveTypeRector::class,
        // Replaces an existing property type such as ConversationRepository
        // with plain MockObject when the property is assigned createMock(),
        // dropping the mocked type the test passes on to constructors.
        TypedPropertyFromCreateMockAssignRector::class,
        // nr-llm's ApprovalPreviewHeadings exists from 0.40 on; the recogniser
        // names it as a string behind class_exists() so that nr-llm 0.37 to
        // 0.39 stay supported. Rector proposes ::class whenever 0.40 or later
        // is installed. The recogniser's test pins that string, so it keeps
        // the literal too.
        StringClassNameToClassConstantRector::class => [
            __DIR__ . '/../../Classes/Service/NrLlmPreviewHeadingRecogniser.php',
            __DIR__ . '/../../Tests/Unit/Service/NrLlmPreviewHeadingRecogniserTest.php',
        ],
    ]);
};
