<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Configuration;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Configuration\ImageAnalysisTurnSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The privacy setting "Bildanalyse" a turn was dispatched with (ADR-026,
 * slice 3): overlaid on the process's configuration for the turn only.
 */
#[CoversClass(ImageAnalysisTurnSettings::class)]
final class ImageAnalysisTurnSettingsTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $saved = null;

    protected function setUp(): void
    {
        parent::setUp();
        $saved                                                    = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_mcp_agent'] ?? null;
        $this->saved                                              = is_array($saved) ? $saved : null;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_mcp_agent'] = ['llmTaskUid' => 3, 'imageAnalysis' => 'analyse'];
    }

    protected function tearDown(): void
    {
        if ($this->saved === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_mcp_agent']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_mcp_agent'] = $this->saved;
        }

        parent::tearDown();
    }

    private function settings(string $analysis = 'analyse', string $folders = ''): ImageAnalysisTurnSettings
    {
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('getImageAnalysisValues')->willReturn(['imageAnalysis' => $analysis, 'imageAnalysisExcludedFolders' => $folders]);

        return new ImageAnalysisTurnSettings($configuration);
    }

    /** @return array<string, mixed> */
    private function extension(): array
    {
        $extension = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_mcp_agent'] ?? [];

        return is_array($extension) ? $extension : [];
    }

    #[Test]
    public function theSnapshotHoldsTheTwoValuesAsConfiguredNow(): void
    {
        self::assertSame(['imageAnalysis' => 'contextOnly', 'imageAnalysisExcludedFolders' => 'fileadmin/team/'], json_decode($this->settings('contextOnly', 'fileadmin/team/')->snapshot(), true));
    }

    #[Test]
    public function theTurnSeesTheDispatchedValuesAndTheProcessGetsItsOwnBack(): void
    {
        $snapshot = $this->settings('contextOnly', 'fileadmin/team/')->snapshot();

        $seen = $this->settings()->during($snapshot, fn (): array => $this->extension());

        self::assertSame(['llmTaskUid' => 3, 'imageAnalysis' => 'contextOnly', 'imageAnalysisExcludedFolders' => 'fileadmin/team/'], $seen);
        // A key the process did not have is gone again, one it had is back.
        self::assertSame(['llmTaskUid' => 3, 'imageAnalysis' => 'analyse'], $this->extension());
    }

    #[Test]
    public function aFailingTurnGivesTheProcessItsValuesBackToo(): void
    {
        $snapshot = $this->settings('contextOnly')->snapshot();

        try {
            $this->settings()->during($snapshot, static function (): never {
                throw new RuntimeException('turn failed');
            });
            self::fail("The turn's exception is not passed on.");
        } catch (RuntimeException $e) {
            self::assertSame('turn failed', $e->getMessage());
        }

        self::assertSame(['llmTaskUid' => 3, 'imageAnalysis' => 'analyse'], $this->extension());
    }

    /**
     * A turn queued before the stamp existed, or a stamp that cannot be read,
     * runs with the process's values.
     *
     * @return iterable<string, array{string}>
     */
    public static function unusableSnapshots(): iterable
    {
        yield 'none' => [''];
        yield 'not JSON' => ['{'];
        yield 'a key missing' => ['{"imageAnalysis":"contextOnly"}'];
        yield 'not a string' => ['{"imageAnalysis":0,"imageAnalysisExcludedFolders":""}'];
    }

    #[Test]
    #[DataProvider('unusableSnapshots')]
    public function anUnusableSnapshotLeavesTheProcessValues(string $snapshot): void
    {
        self::assertSame(['llmTaskUid' => 3, 'imageAnalysis' => 'analyse'], $this->settings()->during($snapshot, fn (): array => $this->extension()));
    }
}
