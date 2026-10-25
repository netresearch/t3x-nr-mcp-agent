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
 * The exclusions of the "missing alt texts" check (ADR-026).
 */
final class AltTextSettingsTest extends TestCase
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
    public function withoutSettingsNoTableAndTheNonImageFieldsAreExcluded(): void
    {
        $config = $this->configured([]);

        self::assertSame([], $config->getAltTextExcludedTables());
        self::assertSame(['pages.og_image', 'pages.twitter_image', 'pages.nav_icon', 'be_users.avatar', 'backend_layout.icon'], $config->getAltTextExcludedFields());
    }

    #[Test]
    public function aConfiguredListReplacesTheDefaultAndDropsInvalidEntries(): void
    {
        $config = $this->configured([
            'altTextExcludedTables' => ' tx_news_domain_model_news , tx_news_domain_model_news,,bad name,pages.media,tx_events',
            'altTextExcludedFields' => 'tt_content.assets, pages, tt_content.assets,a.b.c, x.y',
        ]);

        self::assertSame(['tx_news_domain_model_news', 'tx_events'], $config->getAltTextExcludedTables());
        self::assertSame(['tt_content.assets', 'x.y'], $config->getAltTextExcludedFields());
    }

    #[Test]
    public function anEmptyFieldListExcludesNoField(): void
    {
        self::assertSame([], $this->configured(['altTextExcludedFields' => ''])->getAltTextExcludedFields());
    }
}
