<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\OpenPoint;

/**
 * The record and fields a pending write names, as the approval card showed
 * them (nr-llm's `PendingWriteTarget`, ADR-022). An empty field list is a
 * write that names the record but no field, such as a move or a delete.
 */
final readonly class OpenPointTarget
{
    /** @var list<string> sorted, without duplicates */
    public array $fields;

    /**
     * @param list<string> $fields
     */
    public function __construct(
        public string $table,
        public int $uid,
        array $fields = [],
    ) {
        $fields = array_values(array_unique($fields));
        sort($fields);
        $this->fields = $fields;
    }

    /**
     * One key per field, `''` for a write that names no field: an open point
     * is closed field by field (ADR-022).
     *
     * @return list<string>
     */
    public function fieldKeys(): array
    {
        return $this->fields === [] ? [''] : $this->fields;
    }
}
