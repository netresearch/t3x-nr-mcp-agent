<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\AltText;

use Netresearch\NrMcpAgent\Service\AltText\MissingAltText;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltTextScan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The numbers of the "missing alt texts" list and dashboard (ADR-026): all
 * from the same hits.
 */
final class MissingAltTextScanTest extends TestCase
{
    private function hit(int $reference, string $type, int $group): MissingAltText
    {
        return new MissingAltText($reference, 1, 'team.jpg', '', $type, $type === MissingAltTextScan::PAGES ? 'tt_content' : $type, $group, 'image', 0, $group, '', $group, '');
    }

    private function scan(): MissingAltTextScan
    {
        return new MissingAltTextScan([
            $this->hit(1, MissingAltTextScan::PAGES, 10),
            $this->hit(2, MissingAltTextScan::PAGES, 10),
            $this->hit(3, MissingAltTextScan::PAGES, 11),
            $this->hit(4, 'tx_news_domain_model_news', 5),
            // The English version of news 5 is a record of its own.
            $this->hit(5, 'tx_news_domain_model_news', 6),
            $this->hit(6, 'tx_events', 5),
        ]);
    }

    #[Test]
    public function theCountIsEveryHitOverEveryType(): void
    {
        self::assertSame(6, $this->scan()->count());
        self::assertSame(['pages' => 3, 'tx_news_domain_model_news' => 2, 'tx_events' => 1], $this->scan()->countsByType());
    }

    #[Test]
    public function pagesAndRecordsAreCountedOnceEach(): void
    {
        self::assertSame(2, $this->scan()->pageCount());
        // News 5, news 6 and event 5: the same uid in two tables is two records.
        self::assertSame(3, $this->scan()->recordCount());
    }

    #[Test]
    public function aTypeListsItsHitsInListOrder(): void
    {
        $uids = array_map(static fn (MissingAltText $hit): int => $hit->referenceUid, $this->scan()->ofType('tx_news_domain_model_news'));

        self::assertSame([4, 5], $uids);
    }

    #[Test]
    public function anEmptyScanCountsNothing(): void
    {
        $scan = new MissingAltTextScan([]);

        self::assertSame([0, [], 0, 0], [$scan->count(), $scan->countsByType(), $scan->pageCount(), $scan->recordCount()]);
    }
}
