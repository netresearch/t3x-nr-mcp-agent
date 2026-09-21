<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Language;

use DOMDocument;
use DOMElement;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The chat labels exist twice: locallang_chat.xlf is what lll() renders for
 * every language without a translation, de.locallang_chat.xlf is what a
 * German backend renders. A unit present in one file only is invisible in
 * every review of the other, and it renders as English in a German backend
 * (NEXT-159: three units had no German target for months).
 *
 * The third direction is the JavaScript: the core lll() answers an empty
 * string for a key that is not in the file, and the Jest stub answers the
 * key, so a mistyped key passes every Jest case and renders blank in the
 * backend.
 *
 * The fourth is the PHP: ChatApiController::translate() asks the same file
 * for the approval refusals, and the unit tests stub the LanguageService per
 * label reference, so they pin which key is asked for and never open the
 * file. A unit renamed in both XLF files, or a key mistyped in the
 * controller, passes them and reaches the notice as the raw key.
 */
final class LocallangChatParityTest extends TestCase
{
    private const LANGUAGE_DIR = __DIR__ . '/../../../Resources/Private/Language/';

    private const JAVASCRIPT_DIR = __DIR__ . '/../../../Resources/Public/JavaScript/';

    private const PHP_DIR = __DIR__ . '/../../../Classes/';

    #[Test]
    public function everyEnglishUnitHasAGermanUnit(): void
    {
        $missing = array_diff(
            array_keys($this->units('locallang_chat.xlf')),
            array_keys($this->units('de.locallang_chat.xlf')),
        );

        self::assertSame([], array_values($missing), 'units without a German unit');
    }

    #[Test]
    public function everyGermanUnitHasAnEnglishUnit(): void
    {
        $stale = array_diff(
            array_keys($this->units('de.locallang_chat.xlf')),
            array_keys($this->units('locallang_chat.xlf')),
        );

        self::assertSame([], array_values($stale), 'German units without an English unit');
    }

    #[Test]
    public function everyGermanUnitCarriesATarget(): void
    {
        $untranslated = [];
        foreach ($this->units('de.locallang_chat.xlf') as $id => $unit) {
            if (trim($unit['target'] ?? '') === '') {
                $untranslated[] = $id;
            }
        }

        self::assertSame([], $untranslated, 'German units without a target');
    }

    #[Test]
    public function everyKeyTheJavaScriptAsksForExists(): void
    {
        $known = array_keys($this->units('locallang_chat.xlf'));
        $used = $this->keysUsedInJavaScript();
        self::assertNotSame([], $used, 'precondition: the scan must find lll() calls');

        self::assertSame([], array_values(array_diff($used, $known)), 'lll() keys without a unit');
    }

    /**
     * The English unit is the start of the chain the other cases continue:
     * everyEnglishUnitHasAGermanUnit() takes it to the German file and
     * everyGermanUnitCarriesATarget() to a translation.
     */
    #[Test]
    public function everyKeyThePhpAsksForExists(): void
    {
        $known = array_keys($this->units('locallang_chat.xlf'));
        $used = $this->keysUsedInPhp();
        self::assertNotSame([], $used, 'precondition: the scan must find translate() calls');

        self::assertSame([], array_values(array_diff($used, $known)), 'translate() keys without a unit');
    }

    /**
     * @return array<string, array{source: string, target: string|null}>
     */
    private function units(string $file): array
    {
        $document = new DOMDocument();
        self::assertTrue($document->load(self::LANGUAGE_DIR . $file), $file . ' must parse');

        $units = [];
        foreach ($document->getElementsByTagName('trans-unit') as $node) {
            self::assertInstanceOf(DOMElement::class, $node);
            $id = $node->getAttribute('id');
            self::assertNotSame('', $id, $file . ' has a trans-unit without an id');
            self::assertArrayNotHasKey($id, $units, $file . ' declares ' . $id . ' twice');

            $target = $node->getElementsByTagName('target')->item(0);
            $units[$id] = [
                'source' => $node->getElementsByTagName('source')->item(0)?->textContent ?? '',
                'target' => $target?->textContent,
            ];
        }

        return $units;
    }

    /**
     * Every literal key handed to lll() in the shipped modules, vendored
     * libraries excluded.
     *
     * @return list<string>
     */
    private function keysUsedInJavaScript(): array
    {
        $keys = [];
        $modules = glob(self::JAVASCRIPT_DIR . '*.js');
        $toolbar = glob(self::JAVASCRIPT_DIR . 'toolbar/*.js');
        self::assertNotFalse($modules);
        self::assertNotFalse($toolbar);
        foreach ([...$modules, ...$toolbar] as $file) {
            $source = file_get_contents($file);
            self::assertNotFalse($source);
            preg_match_all('/\blll\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches);
            $keys = [...$keys, ...$matches[1]];
        }

        return array_values(array_unique($keys));
    }

    /**
     * Every literal key handed to a translate() method in the PHP source.
     * Only the arrow form is matched: a LanguageService::sL() call carries a
     * full LLL reference, not a key of this file.
     *
     * @return list<string>
     */
    private function keysUsedInPhp(): array
    {
        $keys = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::PHP_DIR, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertNotFalse($source);
            preg_match_all('/->translate\(\s*\'([^\']+)\'\s*\)/', $source, $matches);
            $keys = [...$keys, ...$matches[1]];
        }

        return array_values(array_unique($keys));
    }
}
