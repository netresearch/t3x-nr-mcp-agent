<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

/**
 * A file field of a table, found in the TCA, whose image references the
 * "missing alt texts" check reads (ADR-026).
 */
final readonly class ImageField
{
    public function __construct(
        public string $table,
        public string $field,
    ) {}
}
