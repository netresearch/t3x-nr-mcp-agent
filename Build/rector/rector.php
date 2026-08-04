<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Property\RemoveUnusedPrivatePropertyRector;

$configure = require_once __DIR__ . '/../../.Build/vendor/netresearch/typo3-ci-workflows/config/rector/rector.php';

return static function (RectorConfig $rectorConfig) use ($configure): void {
    // Shared org base config: paths, code-quality sets, rule skips,
    // and the package's ergebnis-free phpstan-rector.neon.
    $configure($rectorConfig, __DIR__ . '/../..');

    $rectorConfig->skip([
        // crdate hydrated from DB, kept for completeness
        RemoveUnusedPrivatePropertyRector::class => [
            __DIR__ . '/../../Classes/Domain/Model/Conversation.php',
        ],
        // Verbose instanceof checks not preferred over null checks
        FlipTypeControlToUseExclusiveTypeRector::class,
    ]);
};
