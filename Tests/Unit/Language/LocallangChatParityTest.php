<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Language;

use DOMDocument;
use DOMElement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
 */
final class LocallangChatParityTest extends TestCase
{
    private const LANGUAGE_DIR = __DIR__ . '/../../../Resources/Private/Language/';

    private const JAVASCRIPT_DIR = __DIR__ . '/../../../Resources/Public/JavaScript/';

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
        $files = glob(self::JAVASCRIPT_DIR . '{*.js,toolbar/*.js}', GLOB_BRACE);
        self::assertNotFalse($files);
        foreach ($files as $file) {
            $source = file_get_contents($file);
            self::assertNotFalse($source);
            preg_match_all('/\blll\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches);
            $keys = [...$keys, ...$matches[1]];
        }

        return array_values(array_unique($keys));
    }
}
