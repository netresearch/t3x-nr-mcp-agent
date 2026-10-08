<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\Assistant;

use Netresearch\NrMcpAgent\Service\Assistant\NullOpenPointReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NullOpenPointReaderTest extends TestCase
{
    #[Test]
    public function untilTheStoreExistsThereAreNoOpenPoints(): void
    {
        self::assertSame([], (new NullOpenPointReader())->findOpen(5));
    }
}
