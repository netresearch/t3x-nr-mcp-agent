<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Service\Assistant;

use Netresearch\NrMcpAgent\Service\Assistant\OpenPoint;
use Netresearch\NrMcpAgent\Service\Assistant\VisibleOpenPointReader;
use Netresearch\NrMcpAgent\Service\OpenPoint\OpenPointVisibility;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The dashboard's reader over the chat's store, through the real
 * OpenPointVisibility: it reads the database through BackendUtility's static
 * page and record access, so its own visibility rule runs here against
 * fixture rows rather than against stubbed collaborators.
 */
final class VisibleOpenPointReaderTest extends FunctionalTestCase
{
    use OpenPointStoreFixture;

    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/dashboard_page_choice.csv');
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $this->storeSkill(7, '3:seo-optimieren');
        $this->storeSkill(8, 'inhalt-verbessern');
    }

    private function reader(): VisibleOpenPointReader
    {
        return new VisibleOpenPointReader($this->get(OpenPointVisibility::class));
    }

    /**
     * Page, language and summary as the store has them; the skill as its slug,
     * whether the stored identifier names its source or not; newest first.
     */
    #[Test]
    public function theStoresPointsAreReadWithTheSkillsSlug(): void
    {
        $this->storePoint(21, 7, 'pages', 21, 'description', 200);
        $this->storePoint(20, 8, 'pages', 20, 'description', 100);

        $points = $this->reader()->findOpen(5);

        self::assertSame(
            [[21, 0, 'seo-optimieren'], [20, 0, 'inhalt-verbessern']],
            array_map(static fn (OpenPoint $point): array => [$point->pageUid, $point->languageUid, $point->skill], $points),
        );
        self::assertStringContainsString('Inhalt A', $points[0]->summary, "the store's sentence names the page");
    }

    /** A point whose skill is gone is not shown. */
    #[Test]
    public function aPointWhoseSkillIsGoneIsNotShown(): void
    {
        $this->storePoint(20, 9, 'pages', 20, 'description', 200);
        $this->storePoint(21, 7, 'pages', 21, 'description', 100);

        self::assertSame(['seo-optimieren'], array_map(static fn (OpenPoint $point): string => $point->skill, $this->reader()->findOpen(5)));
    }

    /** The limit reaches the store: one point of two. */
    #[Test]
    public function theLimitReachesTheStore(): void
    {
        $this->storePoint(21, 7, 'pages', 21, 'description', 200);
        $this->storePoint(20, 8, 'pages', 20, 'description', 100);

        self::assertSame([21], array_map(static fn (OpenPoint $point): int => $point->pageUid, $this->reader()->findOpen(1)));
        self::assertSame([], $this->reader()->findOpen(0));
    }

    #[Test]
    public function anEmptyStoreHasNoPoints(): void
    {
        self::assertSame([], $this->reader()->findOpen(5));
    }
}
