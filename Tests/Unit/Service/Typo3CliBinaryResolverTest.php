<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use FilesystemIterator;
use Netresearch\NrMcpAgent\Exception\Typo3CliBinaryNotFoundException;
use Netresearch\NrMcpAgent\Service\Typo3CliBinaryResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionProperty;
use SplFileInfo;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Where the `typo3` console binary is, for each way Composer can be set up.
 *
 * Every case builds a real Composer root in a temporary directory, so the
 * resolver reads a real composer.json and checks a real file.
 */
final class Typo3CliBinaryResolverTest extends TestCase
{
    /**
     * Environment's static state, restored after each test: the suite has no
     * TYPO3 unit-test bootstrap, so UnitTestCase::$backupEnvironment cannot be used.
     *
     * @var array<string, mixed>
     */
    private array $environmentBackup = [];

    private string $root;

    private string|false $composerRootEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->environmentBackup = (new ReflectionClass(Environment::class))->getStaticProperties();
        $this->root = sys_get_temp_dir() . '/nr-mcp-agent-binary-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        // The test autoloader's autoload-include.php exports the extension's
        // own Composer root; every case sets what it needs.
        $this->composerRootEnv = getenv('TYPO3_PATH_COMPOSER_ROOT');
    }

    protected function tearDown(): void
    {
        if ($this->composerRootEnv === false) {
            putenv('TYPO3_PATH_COMPOSER_ROOT');
        } else {
            putenv('TYPO3_PATH_COMPOSER_ROOT=' . $this->composerRootEnv);
        }
        $this->removeDirectory($this->root);
        $this->restoreEnvironment();
        parent::tearDown();
    }

    #[Test]
    public function defaultComposerInstallUsesVendorBin(): void
    {
        $this->writeComposerJson(['name' => 'acme/site']);
        $binary = $this->createBinary('vendor/bin');

        self::assertSame($binary, (new Typo3CliBinaryResolver())->resolveFor(true, $this->root, '/unused'));
    }

    #[Test]
    public function customBinDirIsUsed(): void
    {
        $this->writeComposerJson(['config' => ['bin-dir' => 'bin']]);
        $this->createBinary('vendor/bin');
        $binary = $this->createBinary('bin');

        self::assertSame($binary, (new Typo3CliBinaryResolver())->resolveFor(true, $this->root, '/unused'));
    }

    #[Test]
    public function dotBuildBinDirIsUsed(): void
    {
        // The layout of an extension's own test installation, and of the E2E job.
        $this->writeComposerJson(['config' => ['vendor-dir' => '.Build/vendor', 'bin-dir' => '.Build/bin']]);
        $binary = $this->createBinary('.Build/bin');

        self::assertSame($binary, (new Typo3CliBinaryResolver())->resolveFor(true, $this->root, '/unused'));
    }

    #[Test]
    public function customVendorDirWithoutBinDirUsesVendorDirBin(): void
    {
        $this->writeComposerJson(['config' => ['vendor-dir' => 'libs/']]);
        $binary = $this->createBinary('libs/bin');

        self::assertSame($binary, (new Typo3CliBinaryResolver())->resolveFor(true, $this->root, '/unused'));
    }

    #[Test]
    public function absoluteBinDirIsUsedAsItIs(): void
    {
        $absolute = $this->root . '/elsewhere/bin';
        $this->writeComposerJson(['config' => ['bin-dir' => $absolute . '/']]);
        $binary = $this->createBinary('elsewhere/bin');

        self::assertSame($binary, (new Typo3CliBinaryResolver())->resolveFor(true, $this->root . '/', '/unused'));
    }

    #[Test]
    public function relativeBinDirWithSlashesUnderARootWithTrailingSlash(): void
    {
        $this->writeComposerJson(['config' => ['bin-dir' => 'bin/']]);
        $binary = $this->createBinary('bin');

        self::assertSame($binary, (new Typo3CliBinaryResolver())->resolveFor(true, $this->root . '/', '/unused'));
    }

    #[Test]
    public function vendorDirPlaceholderInBinDirIsExpanded(): void
    {
        $this->writeComposerJson(['config' => ['vendor-dir' => 'lib/', 'bin-dir' => '{$vendor-dir}/tools']]);
        $binary = $this->createBinary('lib/tools');

        self::assertSame($binary, (new Typo3CliBinaryResolver())->resolveFor(true, $this->root, '/unused'));
    }

    #[Test]
    public function absoluteVendorDirPlaceholderInBinDirStaysAbsolute(): void
    {
        $this->writeComposerJson(['config' => ['vendor-dir' => $this->root . '/shared/vendor', 'bin-dir' => '{$vendor-dir}/bin']]);
        $binary = $this->createBinary('shared/vendor/bin');

        self::assertSame($binary, (new Typo3CliBinaryResolver())->resolveFor(true, $this->root, '/unused'));
    }

    #[Test]
    public function missingComposerJsonFallsBackToVendorBin(): void
    {
        $binary = $this->createBinary('vendor/bin');

        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            $resolved = (new Typo3CliBinaryResolver())->resolveFor(true, $this->root, '/unused');
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
        self::assertSame($binary, $resolved);
    }

    #[Test]
    public function invalidComposerJsonFallsBackToVendorBin(): void
    {
        file_put_contents($this->root . '/composer.json', '{not json');
        $binary = $this->createBinary('vendor/bin');

        self::assertSame($binary, (new Typo3CliBinaryResolver())->resolveFor(true, $this->root, '/unused'));
    }

    #[Test]
    public function classicModeUsesTheCoreExtensionBinary(): void
    {
        $binary = $this->createBinary('typo3/sysext/core/bin');

        self::assertSame(
            $binary,
            (new Typo3CliBinaryResolver())->resolveFor(false, '/unused', $this->root . '/typo3/sysext/'),
        );
    }

    #[Test]
    public function missingBinaryThrowsWithThePathItChecked(): void
    {
        $this->writeComposerJson(['config' => ['bin-dir' => '.Build/bin']]);
        $this->createBinary('vendor/bin');

        $this->expectException(Typo3CliBinaryNotFoundException::class);
        $this->expectExceptionCode(1790557533);
        $this->expectExceptionMessage('"' . $this->root . '/.Build/bin/typo3"');

        (new Typo3CliBinaryResolver())->resolveFor(true, $this->root, '/unused');
    }

    #[Test]
    public function unreadableBinaryThrows(): void
    {
        $binary = $this->createBinary('vendor/bin');
        chmod($binary, 0o000);
        clearstatcache();
        if (is_readable($binary)) {
            chmod($binary, 0o644);
            self::markTestSkipped('The test process can read a file with mode 0000 (running as root).');
        }

        try {
            $this->expectException(Typo3CliBinaryNotFoundException::class);
            (new Typo3CliBinaryResolver())->resolveFor(true, $this->root, '/unused');
        } finally {
            chmod($binary, 0o644);
        }
    }

    #[Test]
    public function directoryInPlaceOfTheBinaryThrows(): void
    {
        mkdir($this->root . '/vendor/bin/typo3', 0777, true);

        $this->expectException(Typo3CliBinaryNotFoundException::class);

        (new Typo3CliBinaryResolver())->resolveFor(true, $this->root, '/unused');
    }

    #[Test]
    public function resolveReadsTheComposerRootTypo3Exports(): void
    {
        $this->initializeEnvironment(composerMode: true, projectPath: '/nonexistent-project');
        $this->writeComposerJson(['config' => ['bin-dir' => '.Build/bin']]);
        $binary = $this->createBinary('.Build/bin');
        putenv('TYPO3_PATH_COMPOSER_ROOT=' . $this->root);

        self::assertSame($binary, (new Typo3CliBinaryResolver())->resolve());
    }

    #[Test]
    public function resolveTreatsAnEmptyComposerRootAsUnset(): void
    {
        $this->initializeEnvironment(composerMode: true, projectPath: $this->root);
        $binary = $this->createBinary('vendor/bin');
        putenv('TYPO3_PATH_COMPOSER_ROOT=');

        self::assertSame($binary, (new Typo3CliBinaryResolver())->resolve());
    }

    #[Test]
    public function resolveInClassicModeUsesTheFrameworkBasePath(): void
    {
        // The legacy layout (project path = public path), for which 13.4 and
        // 14.3 both put the system extensions in typo3/sysext.
        $this->initializeEnvironment(composerMode: false, projectPath: $this->root, publicPath: $this->root);
        $binary = $this->createBinary('typo3/sysext/core/bin');
        $this->createBinary('vendor/bin');
        putenv('TYPO3_PATH_COMPOSER_ROOT=' . $this->root);

        self::assertSame($binary, (new Typo3CliBinaryResolver())->resolve());
    }

    #[Test]
    public function resolveFallsBackToTheProjectPathWithoutComposerRoot(): void
    {
        $this->initializeEnvironment(composerMode: true, projectPath: $this->root);
        $binary = $this->createBinary('vendor/bin');
        putenv('TYPO3_PATH_COMPOSER_ROOT');

        self::assertSame($binary, (new Typo3CliBinaryResolver())->resolve());
    }

    /**
     * @param array<string, mixed> $json
     */
    private function writeComposerJson(array $json): void
    {
        file_put_contents($this->root . '/composer.json', json_encode($json, JSON_THROW_ON_ERROR));
    }

    private function createBinary(string $relativeDir): string
    {
        $dir = $this->root . '/' . $relativeDir;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $binary = $dir . '/typo3';
        file_put_contents($binary, "#!/usr/bin/env php\n<?php\n");

        return $binary;
    }

    private function initializeEnvironment(bool $composerMode, string $projectPath, ?string $publicPath = null): void
    {
        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            $composerMode,
            $projectPath,
            $publicPath ?? $projectPath . '/public',
            $projectPath . '/var',
            $projectPath . '/config',
            $projectPath . '/vendor/bin/typo3',
            'UNIX',
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            assert($item instanceof SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private function restoreEnvironment(): void
    {
        foreach ($this->environmentBackup as $name => $value) {
            (new ReflectionProperty(Environment::class, $name))->setValue(null, $value);
        }
    }
}
