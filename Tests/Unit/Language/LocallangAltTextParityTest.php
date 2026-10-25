<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Language;

use DOMDocument;
use DOMElement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The labels of the "missing alt texts" check (ADR-026): the list
 * (locallang_alttext.xlf), the decorative field (locallang_db.xlf) and the
 * dashboard entries (locallang_dashboard.xlf), each in English and German.
 * A key the list asks for but no file has renders as the key itself.
 */
final class LocallangAltTextParityTest extends TestCase
{
    private const LANGUAGE_DIR = __DIR__ . '/../../../Resources/Private/Language/';

    private const ROOT = __DIR__ . '/../../../';

    /**
     * @return iterable<string, array{string}>
     */
    public static function files(): iterable
    {
        yield 'list' => ['locallang_alttext.xlf'];
        yield 'decorative field' => ['locallang_db.xlf'];
        yield 'dashboard' => ['locallang_dashboard.xlf'];
    }

    #[Test]
    #[DataProvider('files')]
    public function everyUnitExistsInEnglishAndGermanWithATarget(string $file): void
    {
        $english = $this->units($file);
        $german  = $this->units('de.' . $file);

        self::assertSame([], array_values(array_diff(array_keys($english), array_keys($german))), 'units without a German unit');
        self::assertSame([], array_values(array_diff(array_keys($german), array_keys($english))), 'German units without an English unit');
        self::assertSame([], array_keys(array_filter($german, static fn (array $unit): bool => trim($unit['target'] ?? '') === '')), 'German units without a target');
    }

    /** MissingAltTextList::text() and the list template ask locallang_alttext.xlf. */
    #[Test]
    public function everyKeyTheListAsksForExists(): void
    {
        $php = (string) file_get_contents(self::ROOT . 'Classes/Service/AltText/MissingAltTextList.php');
        preg_match_all('/->text\(\s*\'([^\']+)\'/', $php, $calls);
        preg_match_all('/->text\(\s*\$isPages \? \'([^\']+)\' : \'([^\']+)\'/', $php, $choices);
        $template = (string) file_get_contents(self::ROOT . 'Resources/Private/Templates/AltText/List.html');
        preg_match_all('/\{ll\}([A-Za-z.]+)/', $template, $keys);
        $controller = (string) file_get_contents(self::ROOT . 'Classes/Controller/MissingAltTextsController.php');
        preg_match_all('/locallang_alttext\.xlf:([A-Za-z.]+)/', $controller, $direct);

        // A key built from a prefix ('hidden.' . $reason) is checked below.
        $used = array_filter(
            array_unique([...$calls[1], ...$choices[1], ...$choices[2], ...$keys[1], ...$direct[1]]),
            static fn (string $key): bool => !str_ends_with($key, '.'),
        );
        self::assertGreaterThan(20, count($used), 'precondition: the scan must find the keys');
        // The reason keys are built from the enum's values.
        foreach (['page', 'parentPage', 'element', 'record', 'scheduled', 'expired', 'image'] as $reason) {
            $used[] = 'hidden.' . $reason;
        }

        self::assertSame([], array_values(array_diff($used, array_keys($this->units('locallang_alttext.xlf')))));
    }

    /** The widgets ask locallang_dashboard.xlf. */
    #[Test]
    public function everyKeyTheWidgetsAskForExists(): void
    {
        $used = [];
        foreach (['Classes/Dashboard/RecommendationsWidget.php', 'Classes/Dashboard/WebsiteCheckWidget.php', 'Resources/Private/Templates/Widget/RecommendationsWidget.html', 'Resources/Private/Templates/Widget/WebsiteCheckWidget.html'] as $file) {
            // A quoted key in the PHP, a {ll}key in the templates.
            preg_match_all('/(?:\'|\{ll\})((?:altTexts|websiteCheck)\.[A-Za-z]+)/', (string) file_get_contents(self::ROOT . $file), $keys);
            $used = [...$used, ...$keys[1]];
        }

        self::assertNotSame([], $used);
        self::assertSame([], array_values(array_diff(array_unique($used), array_keys($this->units('locallang_dashboard.xlf')))));
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
            self::assertArrayNotHasKey($id, $units, $file . ' declares ' . $id . ' twice');
            $target     = $node->getElementsByTagName('target')->item(0);
            $units[$id] = [
                'source' => $node->getElementsByTagName('source')->item(0)?->textContent ?? '',
                'target' => $target?->textContent,
            ];
        }

        return $units;
    }
}
