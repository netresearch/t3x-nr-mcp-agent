<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\Overview;

use DateTimeImmutable;
use Netresearch\NrMcpAgent\Service\Overview\OverviewPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The overview's period: one of the offered whole numbers of days, counted
 * from 00:00 of the first day; any other requested value is the default.
 */
final class OverviewPeriodTest extends TestCase
{
    #[Test]
    #[DataProvider('requestValues')]
    public function onlyAnOfferedWholeNumberPicksThePeriod(mixed $value, int $days): void
    {
        $now = new DateTimeImmutable('2026-11-04 15:30:00');

        $period = OverviewPeriod::fromRequestValue($value, $now);

        self::assertSame($days, $period->days);
        self::assertSame($now->setTime(0, 0)->modify(sprintf('-%d days', $days - 1))->format('c'), $period->from->format('c'));
        self::assertSame($now, $period->to);
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function requestValues(): iterable
    {
        yield 'seven' => ['7', 7];
        yield 'ninety as int' => [90, 90];
        yield 'not offered' => ['14', 30];
        yield 'fraction' => ['7.5', 30];
        yield 'exponent' => ['7e0', 30];
        yield 'missing' => [null, 30];
        yield 'text' => ['week', 30];
    }
}
