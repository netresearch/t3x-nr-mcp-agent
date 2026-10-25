<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

/**
 * An image reference a backend user may write, with the record it hangs on
 * and that record's page (ReferenceWriteAccess). The rows are as stored, live.
 */
final readonly class WritableReference
{
    /**
     * @param array<string, mixed> $reference the sys_file_reference row
     * @param array<string, mixed> $record    the row of the record holding the reference
     * @param array<string, mixed> $page      the page it is edited on, empty on the root level
     */
    public function __construct(
        public int $referenceUid,
        public array $reference,
        public string $table,
        public string $field,
        public array $record,
        public array $page,
        public int $language,
    ) {}
}
