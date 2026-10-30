<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Service\Assistant;

use Netresearch\NrMcpAgent\Domain\Repository\MissingDescriptionRepository;
use Netresearch\NrMcpAgent\Service\Assistant\DescriptionTour;
use Netresearch\NrMcpAgent\Service\Assistant\SkillLabels;
use Netresearch\NrMcpAgent\Service\Assistant\SkillResolverInterface;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The dashboard's page count is kept in the shared cache, so a second widget
 * request does not count again, and a page DataHandler writes drops it, so
 * the editor's change shows on the next dashboard (missing_descriptions.csv:
 * 9 page versions lack a description for the admin, 1 is "Start").
 */
final class DescriptionTourCacheTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist', 'workspaces'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/missing_descriptions.csv');
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base'       => 'https://example.com/',
            'languages'  => [
                ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/', 'flag' => 'de'],
                ['languageId' => 1, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/en/', 'flag' => 'us'],
            ],
        ]);
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $GLOBALS['LANG']    = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    /** A tour as one widget request builds it, on the shared cache. */
    private function countedPages(): ?int
    {
        $resolver = $this->createMock(SkillResolverInterface::class);
        $resolver->method('resolve')->willReturn('3:seo-optimieren');
        $tour = new DescriptionTour(
            new MissingDescriptionRepository($this->get(ConnectionPool::class), $this->get(SiteFinder::class)),
            new SkillLabels($this->get(LanguageServiceFactory::class)),
            $resolver,
            $this->get(CacheManager::class)->getCache(DescriptionTour::CACHE),
        );

        return $tour->summary()['count'] ?? null;
    }

    private function writeDescription(int $page, string $description): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [$page => ['description' => $description]]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
    }

    /** A second request reads the count from the cache: a write past DataHandler does not show. */
    #[Test]
    public function aSecondRequestReadsTheKeptCount(): void
    {
        self::assertSame(9, $this->countedPages());

        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['description' => 'Direkt geschrieben.'], ['uid' => 1]);

        self::assertSame(9, $this->countedPages());
    }

    /** A description an editor adds through DataHandler shows on the next request. */
    #[Test]
    public function aPageWrittenThroughDataHandlerShowsOnTheNextRequest(): void
    {
        self::assertSame(9, $this->countedPages());

        $this->writeDescription(1, 'Jetzt mit Beschreibung.');

        self::assertSame(8, $this->countedPages());
    }

    /**
     * Where DataHandler clears no page cache itself (here TCEMAIN.clearCache_disable
     * on page 1; the same holds for a workspace version), only the extension's
     * own hook drops the count: a write to page 3 still shows.
     */
    #[Test]
    public function aPageWrittenWithoutTheCoreCacheClearingShowsOnTheNextRequest(): void
    {
        $this->disableCoreCacheClearing();
        self::assertSame(9, $this->countedPages());

        $this->writeDescription(3, 'Jetzt mit Beschreibung.');

        self::assertSame(8, $this->countedPages());
    }

    /**
     * The same for a page command: restoring the deleted page 9 brings back a
     * page without a description. A delete is no test of the hook, as the
     * core clears the cache for it in spite of clearCache_disable; a copy
     * neither, as it is hidden and the count stays.
     */
    #[Test]
    public function aPageCommandWithoutTheCoreCacheClearingShowsOnTheNextRequest(): void
    {
        $this->disableCoreCacheClearing();
        self::assertSame(9, $this->countedPages());

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['pages' => [9 => ['undelete' => 1]]]);
        $dataHandler->process_cmdmap();
        self::assertSame([], $dataHandler->errorLog);

        self::assertSame(10, $this->countedPages());
    }

    private function disableCoreCacheClearing(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['TSconfig' => 'TCEMAIN.clearCache_disable = 1'], ['uid' => 1]);
    }

    /** A page command (here: delete) drops the count as well. */
    #[Test]
    public function aPageCommandShowsOnTheNextRequest(): void
    {
        self::assertSame(9, $this->countedPages());

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['pages' => [17 => ['delete' => 1]]]);
        $dataHandler->process_cmdmap();
        self::assertSame([], $dataHandler->errorLog);

        self::assertSame(8, $this->countedPages());
    }
}
