<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\Fixtures;

/**
 * Stands in for nr-llm's GuardrailHintCollector (ADR-288): each capture
 * reports the next list of hints from the queue.
 */
final class StubHintCollector
{
    /** @var list<list<object>> */
    public static array $queue = [];

    public static int $captures = 0;

    /** Captures on this instance: tells the shared instance from a new one. */
    public int $ownCaptures = 0;

    /**
     * @return array{result: mixed, hints: list<object>}
     */
    public function capture(callable $call): array
    {
        ++self::$captures;
        ++$this->ownCaptures;
        $hints = array_shift(self::$queue) ?? [];

        return ['result' => $call(), 'hints' => $hints];
    }
}
