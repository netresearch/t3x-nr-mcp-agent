<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\ChangeList;

/**
 * What the runs of one conversation changed (ADR-034).
 *
 * It never says "complete": `notCaptured` is set when nr-llm stated that a
 * run's capture failed or missed a write its tool named, `legacy` when a run
 * has no `tool_changes` events and only the records its tools named are
 * known, and `omitted` counts entries nr-llm or this list left out. Where
 * none of the three is set the list is what DataHandler runs reported, and
 * a write that bypassed the DataHandler is invisible all the same.
 */
final readonly class ChangeCollection
{
    /**
     * @param list<RecordChange> $changes    oldest first, each record once
     * @param int                $runs       how many runs were read
     * @param int                $unreadable how many runs of the conversation the viewer's actor may not read or that are gone
     */
    public function __construct(
        public array $changes = [],
        public bool $notCaptured = false,
        public int $omitted = 0,
        public bool $legacy = false,
        public int $runs = 0,
        public int $unreadable = 0,
    ) {}
}
