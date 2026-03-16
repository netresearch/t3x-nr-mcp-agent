<?php

declare(strict_types=1);

/*
 * Combined bootstrap for unit, functional, and architecture tests.
 *
 * - Loads the Composer autoloader (needed by all test suites).
 * - Initialises ORIGINAL_ROOT and temp dirs (needed by functional tests).
 */

require __DIR__ . '/../../.Build/vendor/autoload.php';

(static function () {
    $testbase = new \TYPO3\TestingFramework\Core\Testbase();
    $testbase->defineOriginalRootPath();
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/tests');
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/transient');
})();
