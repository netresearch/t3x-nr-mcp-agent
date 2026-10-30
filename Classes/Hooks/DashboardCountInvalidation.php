<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Hooks;

use Netresearch\NrMcpAgent\Service\Assistant\DescriptionTour;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Drops the dashboard's cached page counts (DescriptionTour::CACHE) when
 * DataHandler writes a page: a description added, a page hidden, moved,
 * deleted, translated or published, in any workspace. The count is per
 * user, so every user's entry goes; the next dashboard reads it anew.
 */
final readonly class DashboardCountInvalidation
{
    public function __construct(
        private CacheManager $cacheManager,
    ) {}

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        if (($dataHandler->datamap['pages'] ?? []) !== []) {
            $this->flush();
        }
    }

    public function processCmdmap_afterFinish(DataHandler $dataHandler): void
    {
        if (($dataHandler->cmdmap['pages'] ?? []) !== []) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        try {
            $this->cacheManager->getCache(DescriptionTour::CACHE)->flushByTag(DescriptionTour::CACHE_TAG);
        } catch (NoSuchCacheException) {
            // Not configured (an override removed it): nothing is cached.
        }
    }
}
