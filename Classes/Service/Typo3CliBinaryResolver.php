<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrMcpAgent\Exception\Typo3CliBinaryNotFoundException;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Locates the `typo3` console entry point the background worker runs on.
 *
 * TYPO3 core has no public API for this. The scheduler resolves the same file
 * for its setup check in a private method (13.4: SchedulerSetupCheckController::
 * determineExecutablePath(), 14.3: SchedulerModuleController::
 * determineExecutablePath()), and this class follows it: in Composer mode the
 * binary lives in the root package's `config.bin-dir` (default
 * `<vendor-dir>/bin`, `vendor-dir` defaulting to `vendor`), relative to the
 * Composer root that typo3/cms-composer-installers exports as
 * TYPO3_PATH_COMPOSER_ROOT; in classic mode it is EXT:core/bin/typo3.
 *
 * Four deviations from core:
 * - an absolute `bin-dir` is used as it is (core's trim() would turn it into
 *   a relative one);
 * - without TYPO3_PATH_COMPOSER_ROOT the project path stands in for the
 *   Composer root;
 * - a missing, unreadable or invalid composer.json falls back to Composer's
 *   defaults (`vendor/bin`), where core returns null;
 * - `{$vendor-dir}` in `bin-dir` is expanded, as Composer's Config::get()
 *   does; core would look for a directory with that literal name.
 */
readonly class Typo3CliBinaryResolver
{
    /**
     * @throws Typo3CliBinaryNotFoundException
     */
    public function resolve(): string
    {
        if (!Environment::isComposerMode()) {
            return $this->resolveFor(false, '', Environment::getFrameworkBasePath());
        }

        $composerRoot = getenv('TYPO3_PATH_COMPOSER_ROOT');

        return $this->resolveFor(
            true,
            is_string($composerRoot) && $composerRoot !== '' ? $composerRoot : Environment::getProjectPath(),
            '',
        );
    }

    /**
     * @throws Typo3CliBinaryNotFoundException
     */
    public function resolveFor(bool $composerMode, string $composerRoot, string $frameworkBasePath): string
    {
        $binary = $composerMode
            ? $this->composerBinDir($composerRoot) . '/typo3'
            : rtrim($frameworkBasePath, '/') . '/core/bin/typo3';

        if (!is_file($binary) || !is_readable($binary)) {
            throw new Typo3CliBinaryNotFoundException(sprintf(
                'The TYPO3 console binary was not found at "%s" (Composer mode: %s, Composer root: "%s").',
                $binary,
                $composerMode ? 'yes' : 'no',
                $composerRoot,
            ), 1790557533);
        }

        return $binary;
    }

    private function composerBinDir(string $composerRoot): string
    {
        $composerRoot = rtrim($composerRoot, '/');
        $config = $this->readComposerConfig($composerRoot . '/composer.json');

        $vendorDir = is_string($config['vendor-dir'] ?? null) ? $config['vendor-dir'] : 'vendor';
        $binDir = is_string($config['bin-dir'] ?? null) ? $config['bin-dir'] : rtrim($vendorDir, '/') . '/bin';
        $binDir = str_replace('{$vendor-dir}', rtrim($vendorDir, '/'), $binDir);

        if (str_starts_with($binDir, '/')) {
            return rtrim($binDir, '/');
        }

        return $composerRoot . '/' . trim($binDir, '/');
    }

    /**
     * The `config` section of composer.json; empty when the file is missing,
     * unreadable or not valid JSON, so Composer's defaults apply.
     *
     * @return array<mixed>
     */
    private function readComposerConfig(string $composerJsonFile): array
    {
        $content = is_file($composerJsonFile) ? file_get_contents($composerJsonFile) : false;
        $json = is_string($content) ? json_decode($content, true) : null;

        return is_array($json) && is_array($json['config'] ?? null) ? $json['config'] : [];
    }
}
