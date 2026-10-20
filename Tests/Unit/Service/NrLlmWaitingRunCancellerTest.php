<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\GuardedCancelResult;
use Netresearch\NrMcpAgent\Service\NrLlmWaitingRunCanceller;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The adapter to nr-llm's guarded cancel (nr-llm ADR-214, nr-llm PR 1024):
 * nr-llm's result passed on as the chat's, never the unguarded cancel.
 */
#[CoversClass(NrLlmWaitingRunCanceller::class)]
final class NrLlmWaitingRunCancellerTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, AgentRunStatus|null}>
     */
    public static function outcomes(): iterable
    {
        yield 'cancelled' => [true, AgentRunStatus::CANCELLED];
        yield 'carried on' => [false, AgentRunStatus::RUNNING];
        yield 'handed back, still waiting' => [false, AgentRunStatus::WAITING_FOR_APPROVAL];
        yield 'finished' => [false, AgentRunStatus::COMPLETED];
        yield 'unknown or not the initiator' => [false, null];
    }

    #[Test]
    #[DataProvider('outcomes')]
    public function itPassesNrLlmsResultOn(bool $cancelled, ?AgentRunStatus $status): void
    {
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->expects(self::once())->method('cancelIfWaiting')->with(self::anything(), 'run')
            ->willReturn(new GuardedCancelResult($cancelled, $status));
        $runtime->expects(self::never())->method('cancel');

        $result = (new NrLlmWaitingRunCanceller($runtime))->cancelIfWaiting(AiActorContext::backendUser(1), 'run');

        self::assertSame($cancelled, $result->cancelled);
        self::assertSame($status, $result->status);
    }
}
