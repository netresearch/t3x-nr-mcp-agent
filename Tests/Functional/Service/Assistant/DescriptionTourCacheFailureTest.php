<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Service\Assistant;

use Doctrine\DBAL\Exception as DatabaseException;
use Netresearch\NrMcpAgent\Domain\Repository\MissingDescriptionRepository;
use Netresearch\NrMcpAgent\Service\Assistant\DescriptionTour;
use Netresearch\NrMcpAgent\Service\Assistant\SkillLabels;
use Netresearch\NrMcpAgent\Service\Assistant\SkillResolverInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Without the cache's tables (a deploy that skipped `extension:setup`) the
 * widgets still show the count: it is read directly, and the failure is
 * logged once per request (missing_descriptions.csv: 9 page versions lack a
 * description for the admin). A class of its own, as it drops tables.
 */
final class DescriptionTourCacheFailureTest extends FunctionalTestCase
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

    #[Test]
    public function withoutTheCacheTablesTheCountIsReadDirectlyAndTheFailureLoggedOnce(): void
    {
        $schema = $this->get(ConnectionPool::class)->getConnectionForTable('cache_nrmcpagent_dashboard')->createSchemaManager();
        $schema->dropTable('cache_nrmcpagent_dashboard');
        $schema->dropTable('cache_nrmcpagent_dashboard_tags');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('extension:setup'));
        $resolver = $this->createMock(SkillResolverInterface::class);
        $resolver->method('resolve')->willReturn('3:seo-optimieren');
        $tour = new DescriptionTour(
            new MissingDescriptionRepository($this->get(ConnectionPool::class), $this->get(SiteFinder::class)),
            new SkillLabels($this->get(LanguageServiceFactory::class)),
            $resolver,
            $this->get(CacheManager::class)->getCache(DescriptionTour::CACHE),
            $logger,
        );

        try {
            $summary = $tour->summary();
        } catch (DatabaseException $exception) {
            self::fail('The count failed without the cache tables: ' . $exception->getMessage());
        }

        self::assertSame(9, $summary['count'] ?? null);
    }
}
