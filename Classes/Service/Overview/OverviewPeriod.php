<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Overview;

use DateTimeImmutable;

/**
 * The period the overview counts: the last 7, 30 or 90 days, today included,
 * from 00:00 of the first day up to now. Anything else asked for is 30.
 */
final readonly class OverviewPeriod
{
    public const CHOICES = [7, 30, 90];

    public const DEFAULT_DAYS = 30;

    private function __construct(
        public int $days,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {}

    public static function fromRequestValue(mixed $value, DateTimeImmutable $now): self
    {
        $days = filter_var($value, FILTER_VALIDATE_INT);
        if (!in_array($days, self::CHOICES, true)) {
            $days = self::DEFAULT_DAYS;
        }

        return new self($days, $now->setTime(0, 0)->modify(sprintf('-%d days', $days - 1)), $now);
    }
}
