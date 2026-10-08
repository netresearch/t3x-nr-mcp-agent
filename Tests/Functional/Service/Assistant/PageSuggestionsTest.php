<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Service\Assistant;

use Netresearch\NrMcpAgent\Domain\Repository\PageChoiceRepository;
use Netresearch\NrMcpAgent\Service\Assistant\DeterministicPageSuggestionProvider;
use Netresearch\NrMcpAgent\Service\Assistant\OpenPoint;
use Netresearch\NrMcpAgent\Service\Assistant\OpenPointReaderInterface;
use Netresearch\NrMcpAgent\Service\Assistant\PageSuggestion;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The page suggestions for guided tours, from stored records, with EXT:seo
 * providing the SEO title and the social-media image.
 *
 * The editor (group "Redaktion", mount 20) may edit every page of the fixture
 * except 47 (view only) and 48 (locked for editing). Defects, newest first:
 * 50, 49 and 41 lack a meta description, 46 is complete (short page title,
 * long SEO title), 45 has a short SEO title, 44 is complete, 43 lacks a
 * social-media image, 42 has a short title; 51 is a folder and 52 a
 * translation, which are never offered. 53 and 54 sit on the title boundary:
 * 30 characters is long enough, 29 is not — counted in characters: both
 * titles hold a two-byte "ß".
 */
final class PageSuggestionsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist', 'seo'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/dashboard_page_suggestions.csv');
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(3);
    }

    private function pages(): PageChoiceRepository
    {
        return new PageChoiceRepository($this->get(ConnectionPool::class), $this->get(SiteFinder::class), $this->get(TcaSchemaFactory::class));
    }

    /**
     * @param list<OpenPoint> $points
     */
    private function provider(array $points = []): DeterministicPageSuggestionProvider
    {
        $reader = new class ($points) implements OpenPointReaderInterface {
            /** @param list<OpenPoint> $points */
            public function __construct(private readonly array $points) {}

            public function findOpen(int $limit): array
            {
                return array_slice($this->points, 0, $limit);
            }
        };

        return new DeterministicPageSuggestionProvider($reader, $this->pages(), $this->get(LanguageServiceFactory::class));
    }

    /**
     * @param list<PageSuggestion> $suggestions
     * @return array<int, string> reason per page uid
     */
    private static function reasons(array $suggestions): array
    {
        $reasons = [];
        foreach ($suggestions as $suggestion) {
            $reasons[$suggestion->pageUid] = $suggestion->reason;
        }

        return $reasons;
    }

    /** Each defect, found where it is and named in words; the SEO title counts over the page title. */
    #[Test]
    public function aPageWithADefectIsFoundWithItsDefects(): void
    {
        $defects = [];
        foreach ($this->pages()->findWithSeoDefects(20) as $page) {
            $defects[$page['uid']] = $page['defects'];
        }

        self::assertSame(
            [
                50 => ['missingDescription'],
                49 => ['missingDescription'],
                45 => ['shortTitle'],
                43 => ['missingSocialImage'],
                42 => ['shortTitle'],
                41 => ['missingDescription'],
                54 => ['shortTitle'],
            ],
            $defects,
        );
    }

    /** The other direction: complete pages, pages the editor may not edit, folders and translations are not found. */
    #[Test]
    public function aCompletePageOrOneTheEditorMayNotEditIsNotFound(): void
    {
        $uids = array_column($this->pages()->findWithSeoDefects(20), 'uid');

        foreach ([20, 44, 46, 47, 48, 51, 52, 53] as $uid) {
            self::assertNotContains($uid, $uids, 'page ' . $uid);
        }
    }

    /** At most five suggestions, newest change first. */
    #[Test]
    public function theSeoTourSuggestsAtMostFivePages(): void
    {
        $suggestions = $this->provider()->suggest('seo-optimieren', 20);

        self::assertSame([50, 49, 45, 43, 42], array_column(array_map(get_object_vars(...), $suggestions), 'pageUid'));
        self::assertSame('Meta description missing', $suggestions[0]->reason);
        self::assertSame('Page title too short', $suggestions[2]->reason);
        self::assertSame('Social media image missing', $suggestions[3]->reason);
        self::assertSame(0, $suggestions[0]->languageUid);
    }

    #[Test]
    public function aLowerLimitIsKept(): void
    {
        self::assertCount(2, $this->provider()->suggest('seo-optimieren', 2));
        self::assertSame([], $this->provider()->suggest('seo-optimieren', 0));
    }

    /**
     * Open points of the tour come first; a page with an open point and a
     * defect appears once; points of other tours, on pages the editor may not
     * edit, or in a language the page has not are left out.
     */
    #[Test]
    public function openPointsComeFirstAndEachPageAppearsOnce(): void
    {
        $suggestions = $this->provider([
            new OpenPoint(44, 0, '3:seo-optimieren', 'Der Seitentitel wiederholt die Überschrift.'),
            new OpenPoint(42, 0, 'inhalt-verbessern', 'Ein Absatz ist sehr lang.'),
            new OpenPoint(47, 0, 'seo-optimieren', 'Nur ansehen.'),
            new OpenPoint(41, 1, 'seo-optimieren', 'Sprache, die es nicht gibt.'),
            new OpenPoint(50, 0, 'seo-optimieren', 'Meta Description fehlt.'),
            new OpenPoint(44, 0, 'seo-optimieren', 'Zweiter Punkt auf derselben Seite.'),
        ])->suggest('3:seo-optimieren');

        self::assertSame(
            [44 => 'Open point from a guided tour', 50 => 'Open point from a guided tour', 49 => 'Meta description missing', 45 => 'Page title too short', 43 => 'Social media image missing'],
            self::reasons($suggestions),
        );
    }

    /** The content tour has open points only: no SEO checks. */
    #[Test]
    public function theContentTourSuggestsItsOpenPointsOnly(): void
    {
        $provider = $this->provider([
            new OpenPoint(42, 0, 'inhalt-verbessern', 'Ein Absatz ist sehr lang.'),
            new OpenPoint(44, 0, 'seo-optimieren', 'SEO.'),
        ]);

        self::assertSame([42 => 'Open point from a guided tour'], self::reasons($provider->suggest('inhalt-verbessern')));
        self::assertSame([], $this->provider()->suggest('inhalt-verbessern'));
    }

    /** A tour the provider has no checks for gets its open points only. */
    #[Test]
    public function anUnknownTourGetsItsOpenPointsOnly(): void
    {
        $provider = $this->provider([new OpenPoint(44, 0, 'neue-seite', 'Unterseite fehlt.')]);

        self::assertSame([44 => 'Open point from a guided tour'], self::reasons($provider->suggest('neue-seite')));
        self::assertSame([], $this->provider()->suggest('neue-seite'));
    }

    /** No five suggestions to an editor who may not edit the pages. */
    #[Test]
    public function withoutABackendUserNothingIsSuggested(): void
    {
        unset($GLOBALS['BE_USER']);

        self::assertSame([], $this->provider([new OpenPoint(44, 0, 'seo-optimieren', 'x')])->suggest('seo-optimieren'));
    }
}
