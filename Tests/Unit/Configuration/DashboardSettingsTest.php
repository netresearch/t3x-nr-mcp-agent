<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Configuration;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The settings behind the AI assistant dashboard widgets.
 */
final class DashboardSettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    /**
     * @param array<string, string> $settings
     */
    private function configured(array $settings): ExtensionConfiguration
    {
        $mock = $this->createMock(Typo3ExtensionConfiguration::class);
        $mock->method('get')->with('nr_mcp_agent')->willReturn($settings);
        GeneralUtility::addInstance(Typo3ExtensionConfiguration::class, $mock);

        return new ExtensionConfiguration();
    }

    #[Test]
    public function withoutSettingsTheTwoGuidedToursAreOfferedAndNoFrequentTask(): void
    {
        $config = $this->configured([]);

        self::assertSame(['seo-optimieren', 'inhalt-verbessern'], $config->getDashboardGuidedSkills());
        // Decision 2: the tours are not repeated as frequent tasks.
        self::assertSame([], $config->getDashboardQuickTasks());
    }

    #[Test]
    public function guidedToursKeepTheirOrderAndDropDuplicatesAndInvalidIdentifiers(): void
    {
        $config = $this->configured(['dashboardGuidedSkills' => ' inhalt-verbessern , seo-optimieren,inhalt-verbessern,,Bad Name,"x",a_b-1,trailing!,-lead,' . str_repeat('a', 101)]);

        self::assertSame(['inhalt-verbessern', 'seo-optimieren', 'a_b-1'], $config->getDashboardGuidedSkills());
    }

    /** The chat's catalogue names a skill `<source uid>:<path>`; such an identifier is kept as it is. */
    #[Test]
    public function catalogueIdentifiersAreAccepted(): void
    {
        $config = $this->configured(['dashboardGuidedSkills' => '3:seo-optimieren,3:skills/Inhalt.v2,' . str_repeat('a', 100), 'dashboardQuickTasks' => '3:seo-optimieren|page']);

        self::assertSame(['3:seo-optimieren', '3:skills/Inhalt.v2', str_repeat('a', 100)], $config->getDashboardGuidedSkills());
        self::assertSame([['skill' => '3:seo-optimieren', 'needsPage' => true]], $config->getDashboardQuickTasks());
    }

    #[Test]
    public function anEmptyListOffersNoGuidedTours(): void
    {
        self::assertSame([], $this->configured(['dashboardGuidedSkills' => ''])->getDashboardGuidedSkills());
    }

    #[Test]
    public function aTaskNeedsAPageOnlyWhenItSaysSo(): void
    {
        $config = $this->configured(['dashboardQuickTasks' => 'seo-optimieren|page, neue-seite ,inhalt-verbessern | page']);

        self::assertSame(
            [
                ['skill' => 'seo-optimieren', 'needsPage' => true],
                ['skill' => 'neue-seite', 'needsPage' => false],
                ['skill' => 'inhalt-verbessern', 'needsPage' => true],
            ],
            $config->getDashboardQuickTasks(),
        );
    }

    #[Test]
    public function aTaskWithAnUnknownFlagOrAnInvalidIdentifierIsDropped(): void
    {
        $config = $this->configured(['dashboardQuickTasks' => 'a|pages,b|page|x,_c|page,<x>,ok']);

        self::assertSame([['skill' => 'ok', 'needsPage' => false]], $config->getDashboardQuickTasks());
    }
}
