<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use DomainException;
use Netresearch\NrMcpAgent\Service\NrLlmPreviewHeadingRecogniser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * nr-llm's ApprovalPreviewHeadings arrives with netresearch/t3x-nr-llm#1016,
 * which not every supported nr-llm has. The recogniser asks it when it is
 * there and answers "no heading" otherwise.
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
                throw new DomainException('broken');
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
        self::assertSame('Netresearch\\NrLlm\\Service\\Tool\\ApprovalPreviewHeadings', NrLlmPreviewHeadingRecogniser::PROVIDER);
    }

    #[Test]
    public function anNrLlmWithoutTheApiKnowsNoHeading(): void
    {
        $recogniser = new NrLlmPreviewHeadingRecogniser('Netresearch\\NrLlm\\DoesNotExist');

        self::assertFalse($recogniser->isHeading('Seite löschen', $this->german()));
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
