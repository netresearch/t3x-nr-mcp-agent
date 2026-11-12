<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Exception;

use Netresearch\NrMcpAgent\Enum\SiteConfigurationRefusal;
use Netresearch\NrMcpAgent\Exception\SiteConfigurationRefusedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SiteConfigurationRefusedException::class)]
final class SiteConfigurationRefusedExceptionTest extends TestCase
{
    #[Test]
    public function itCarriesTheReasonAndTheNamesTheLabelQuotes(): void
    {
        $previous  = new RuntimeException('not found');
        $exception = new SiteConfigurationRefusedException(SiteConfigurationRefusal::NoToolCalling, 'shop', 'Shop AI', 'Small Model', $previous);

        self::assertSame(SiteConfigurationRefusal::NoToolCalling, $exception->reason);
        self::assertSame('shop', $exception->site);
        self::assertSame('Shop AI', $exception->configuration);
        self::assertSame('Small Model', $exception->model);
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(1794393601, $exception->getCode());
        self::assertSame('The AI configuration of site "shop" ("Shop AI") cannot be used by the chat (siteConfig.noTools).', $exception->getMessage());
    }

    #[Test]
    public function theLogMessageLeavesOutAConfigurationItDoesNotKnow(): void
    {
        $exception = new SiteConfigurationRefusedException(SiteConfigurationRefusal::Unusable, 'shop');

        self::assertSame('The AI configuration of site "shop" cannot be used by the chat (siteConfig.unusable).', $exception->getMessage());
    }

    #[Test]
    public function theStoredMessageGivesTheNamesBack(): void
    {
        $exception = new SiteConfigurationRefusedException(SiteConfigurationRefusal::NoFixedModel, 'shop', 'Shop "AI"', 'Mödel');

        self::assertSame(
            ['site' => 'shop', 'configuration' => 'Shop "AI"', 'model' => 'Mödel'],
            SiteConfigurationRefusal::decode($exception->storedMessage()),
        );
    }
}
