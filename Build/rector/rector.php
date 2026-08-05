<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Property\RemoveUnusedPrivatePropertyRector;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;

$configure = require_once __DIR__ . '/../../.Build/vendor/netresearch/typo3-ci-workflows/config/rector/rector.php';

return static function (RectorConfig $rectorConfig) use ($configure): void {
    // Shared org base config: paths, code-quality sets, rule skips,
    // and the package's ergebnis-free phpstan-rector.neon.
    $configure($rectorConfig, __DIR__ . '/../..');

    // UP_TO_TYPO3_13 matches the lowest supported core major (typo3/cms-core
    // ^13.4 || ^14.0); fleet convention: repos still supporting v13 use the
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
    ]);
};
