<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

/**
 * No open points: the reader in place while there is no store to read them
 * from. The widget then says how points come about instead of listing any.
 */
final readonly class NullOpenPointReader implements OpenPointReaderInterface
{
    public function findOpen(int $limit): array
    {
        return [];
    }
}
