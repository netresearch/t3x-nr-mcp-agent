<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Hooks;

use Netresearch\NrMcpAgent\Domain\Repository\MissingDescriptionRepository;
use Netresearch\NrMcpAgent\Service\Assistant\DescriptionTour;
use Netresearch\NrMcpAgent\Service\Assistant\SkillLabels;
use Netresearch\NrMcpAgent\Service\Assistant\SkillResolverInterface;
use PHPUnit\Framework\Attributes\Test;
use Throwable;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * DashboardCountInvalidation runs inside whatever transaction the caller of
 * DataHandler opened. It must not end that transaction: on MySQL and MariaDB a
 * TRUNCATE commits it implicitly, and the caller can then neither roll back
 * nor release its savepoint. The count drops when the caller commits and
 * stays when the caller rolls back (missing_descriptions.csv: 9 page versions
 * lack a description for the admin, 1 is "Start").
 *
 * The core's own cache clearing is switched off on page 1
 * (TCEMAIN.clearCache_disable), so only the hook drops the count.
 *
 * Meaningful on MySQL and MariaDB; on SQLite and PostgreSQL a TRUNCATE is
 * transactional, and the tests pass with or without the fix.
 */
final class DashboardCountInvalidationTransactionTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/missing_descriptions.csv');
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
        $this->connection()->update('pages', ['TSconfig' => 'TCEMAIN.clearCache_disable = 1'], ['uid' => 1]);
    }

    /** A failed test leaves no open transaction behind for the next one: the connection is shared. */
    protected function tearDown(): void
    {
        if ($this->connection()->isTransactionActive()) {
            // Closing rolls back what is open and resets DBAL's nesting level,
            // also where the database already ended the transaction.
            $this->connection()->close();
        }

        parent::tearDown();
    }

    private function connection(): Connection
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable('pages');
    }

    /** The count a widget request shows, from the shared cache where it holds one. */
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

    private function storedDescription(int $page): string
    {
        return (string) $this->connection()->select(['description'], 'pages', ['uid' => $page])->fetchOne();
    }

    /** The caller rolls back: the page write is gone and the count stays, as the hook left the transaction open. */
    #[Test]
    public function aPageWriteInsideATransactionCanStillBeRolledBack(): void
    {
        self::assertSame(9, $this->countedPages());
        $before = $this->storedDescription(3);

        $this->connection()->beginTransaction();
        $this->writeDescription(3, 'In der Transaktion geschrieben.');

        try {
            $this->connection()->rollBack();
        } catch (Throwable $exception) {
            self::fail('The transaction was ended during the page write: ' . $exception->getMessage());
        }

        self::assertSame($before, $this->storedDescription(3), 'The page write survived the rollback: the transaction had been committed.');
        // A direct write the cache cannot know of: a count still kept does not show it.
        $this->connection()->update('pages', ['description' => 'Direkt geschrieben.'], ['uid' => 1]);
        self::assertSame(9, $this->countedPages(), 'The count was dropped although the page write was rolled back.');
    }

    /** The same inside a savepoint, as a caller with a transaction of its own opens one. */
    #[Test]
    public function aPageWriteInsideASavepointCanStillBeReleased(): void
    {
        self::assertSame(9, $this->countedPages());

        $this->connection()->beginTransaction();
        $this->connection()->beginTransaction();
        $this->writeDescription(3, 'Im Savepoint geschrieben.');

        try {
            $this->connection()->commit();
            $this->connection()->commit();
        } catch (Throwable $exception) {
            self::fail('The transaction was ended during the page write: ' . $exception->getMessage());
        }

        self::assertSame('Im Savepoint geschrieben.', $this->storedDescription(3));
        self::assertSame(8, $this->countedPages());
    }

    /** The caller commits: the page write is stored and the next request counts anew. */
    #[Test]
    public function theCountDropsWhenTheTransactionCommits(): void
    {
        self::assertSame(9, $this->countedPages());

        $this->connection()->beginTransaction();
        $this->writeDescription(3, 'Nach dem Commit sichtbar.');
        $this->connection()->commit();

        self::assertSame('Nach dem Commit sichtbar.', $this->storedDescription(3));
        self::assertSame(8, $this->countedPages());
    }
}
