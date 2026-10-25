<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

/**
 * A table whose records own records of another table through an inline
 * field (ADR-026): `$table.$field` holds them, and each child names its
 * owner's uid in `$foreignField` and, where the child table serves several
 * owners, the owner's table in `$foreignTableField`. A carousel item belongs
 * to its content element this way, a content element to a news record.
 */
final readonly class InlineOwner
{
    public function __construct(
        public string $table,
        public string $field,
        public string $foreignField,
        public string $foreignTableField = '',
    ) {}
}
