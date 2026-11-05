<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\Overview;

use Netresearch\NrLlm\Service\Agent\AgentRoundLimits;
use Netresearch\NrMcpAgent\Service\Overview\RoundsOverview;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(RoundsOverview::class)]
final class RoundsOverviewTest extends TestCase
{
    /**
     * @param array{chat: int, chatSource: string, process: int, processSource: string, ceiling: int} $expected
     */
    #[Test]
    #[DataProvider('cases')]
    public function theRoundsAreThoseNrLlmApplies(?int $chat, int $process, array $expected): void
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn(['agent' => ['defaultMaxIterations' => '6', 'maxIterations' => '10']]);

        self::assertSame($expected, (new RoundsOverview(new AgentRoundLimits($configuration)))->rounds($chat, $process));
    }

    /**
     * @return iterable<string, array{int|null, int, array{chat: int, chatSource: string, process: int, processSource: string, ceiling: int}}>
     */
    public static function cases(): iterable
    {
        yield 'no chat value, process within the maximum' => [null, 9, ['chat' => 6, 'chatSource' => 'nrLlmDefault', 'process' => 9, 'processSource' => 'process', 'ceiling' => 10]];
        yield 'chat value within the maximum, process above it' => [8, 12, ['chat' => 8, 'chatSource' => 'chat', 'process' => 10, 'processSource' => 'nrLlmCeiling', 'ceiling' => 10]];
        yield 'both at the maximum' => [10, 10, ['chat' => 10, 'chatSource' => 'chat', 'process' => 10, 'processSource' => 'process', 'ceiling' => 10]];
        yield 'chat value above the maximum' => [11, 3, ['chat' => 10, 'chatSource' => 'nrLlmCeiling', 'process' => 3, 'processSource' => 'process', 'ceiling' => 10]];
    }
}
