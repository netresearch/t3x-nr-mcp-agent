<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use DomainException;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewHeadings;
use Netresearch\NrMcpAgent\Service\NrLlmPreviewHeadingRecogniser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * The recogniser asks nr-llm's ApprovalPreviewHeadings
 * (netresearch/t3x-nr-llm#1016) and answers "no heading" when it fails.
 */
#[CoversClass(NrLlmPreviewHeadingRecogniser::class)]
final class NrLlmPreviewHeadingRecogniserTest extends TestCase
{
    /** The shape of nr-llm's API: a static isHeading(string, LanguageService). */
    private function provider(): string
    {
        return (new class {
            public static function isHeading(string $line, LanguageService $languageService): bool
            {
                return $line === 'Seite löschen' && $languageService->sL('probe') === 'de';
            }
        })::class;
    }

    private function failingProvider(): string
    {
        return (new class {
            public static function isHeading(string $line, LanguageService $languageService): bool
            {
                throw new DomainException(sprintf('No answer for "%s" in %s.', $line, $languageService::class));
            }
        })::class;
    }

    private function german(): LanguageService
    {
        $language = $this->createMock(LanguageService::class);
        $language->method('sL')->willReturn('de');

        return $language;
    }

    #[Test]
    public function itTargetsNrLlmsApi(): void
    {
        $provider = (new ReflectionProperty(NrLlmPreviewHeadingRecogniser::class, 'provider'))->getValue(new NrLlmPreviewHeadingRecogniser());

        self::assertSame(ApprovalPreviewHeadings::class, $provider);
    }

    #[Test]
    public function itAsksNrLlmWithTheLineAndTheLanguage(): void
    {
        $recogniser = new NrLlmPreviewHeadingRecogniser($this->provider());

        self::assertTrue($recogniser->isHeading('Seite löschen', $this->german()));
        self::assertFalse($recogniser->isHeading('Page not found or not permitted.', $this->german()));
    }

    #[Test]
    public function aFailingApiKnowsNoHeading(): void
    {
        $recogniser = new NrLlmPreviewHeadingRecogniser($this->failingProvider());

        self::assertFalse($recogniser->isHeading('Seite löschen', $this->german()));
    }
}
