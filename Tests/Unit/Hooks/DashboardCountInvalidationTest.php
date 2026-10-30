<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Hooks;

use Netresearch\NrMcpAgent\Hooks\DashboardCountInvalidation;
use Netresearch\NrMcpAgent\Service\Assistant\DescriptionTour;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * The dashboard's page counts are dropped when DataHandler writes a page or
 * runs a page command, and only then; a missing cache is no error.
 */
final class DashboardCountInvalidationTest extends TestCase
{
    /**
     * @param array<string, array<int|string, mixed>> $datamap
     * @param array<string, array<int|string, mixed>> $cmdmap
     */
    private function dataHandler(array $datamap = [], array $cmdmap = []): DataHandler
    {
        $dataHandler          = $this->createMock(DataHandler::class);
        $dataHandler->datamap = $datamap;
        $dataHandler->cmdmap  = $cmdmap;

        return $dataHandler;
    }

    /** A hook whose dashboard cache expects to be flushed as often as $flushes says. */
    private function hook(InvocationOrder $flushes): DashboardCountInvalidation
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->expects($flushes)->method('flush');
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->with(DescriptionTour::CACHE)->willReturn($cache);

        return new DashboardCountInvalidation($cacheManager);
    }

    #[Test]
    public function aPageWriteFlushesTheCounts(): void
    {
        $this->hook(self::once())->processDatamap_afterAllOperations($this->dataHandler(['pages' => [3 => ['description' => 'Neu.']]]));
    }

    #[Test]
    public function aPageCommandFlushesTheCounts(): void
    {
        $this->hook(self::once())->processCmdmap_afterFinish($this->dataHandler([], ['pages' => [17 => ['delete' => 1]]]));
    }

    #[Test]
    public function aWriteWithoutPagesKeepsTheCounts(): void
    {
        $hook = $this->hook(self::never());

        $hook->processDatamap_afterAllOperations($this->dataHandler(['tt_content' => [5 => ['header' => 'Neu']], 'pages' => []]));
        $hook->processCmdmap_afterFinish($this->dataHandler([], ['tt_content' => [5 => ['delete' => 1]], 'pages' => []]));
    }

    /** Where an override removed the cache, there is nothing to flush and no error. */
    #[Test]
    public function aMissingCacheIsNoError(): void
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->expects(self::exactly(2))->method('getCache')->willThrowException(new NoSuchCacheException('No cache', 1730000000));
        $hook = new DashboardCountInvalidation($cacheManager);

        try {
            $hook->processDatamap_afterAllOperations($this->dataHandler(['pages' => [3 => ['description' => 'Neu.']]]));
            $hook->processCmdmap_afterFinish($this->dataHandler([], ['pages' => [17 => ['delete' => 1]]]));
        } catch (NoSuchCacheException $exception) {
            self::fail('A missing dashboard cache stopped the page write: ' . $exception->getMessage());
        }
    }
}
