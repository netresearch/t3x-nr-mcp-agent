<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrMcpAgent\Service\WaitingRunCancel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which outcomes of nr-llm's guarded cancel let a tour's conversation take a
 * new message, as GuardedCancelResult's docblock lists them (nr-llm ADR-214,
 * item 10).
 */
#[CoversClass(WaitingRunCancel::class)]
final class WaitingRunCancelTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, AgentRunStatus|null, bool}>
     */
    public static function outcomes(): iterable
    {
        yield 'cancelled' => [true, AgentRunStatus::CANCELLED, true];
        yield 'queued: a decision is carried out' => [false, AgentRunStatus::QUEUED, false];
        yield 'running: a decision is carried out' => [false, AgentRunStatus::RUNNING, false];
        yield 'handed back, waits on a card' => [false, AgentRunStatus::WAITING_FOR_APPROVAL, false];
        yield 'handed back, waits on a question' => [false, AgentRunStatus::WAITING_FOR_INPUT, false];
        yield 'completed' => [false, AgentRunStatus::COMPLETED, true];
        yield 'cancelled by an operator' => [false, AgentRunStatus::CANCELLED, true];
        yield 'failed' => [false, AgentRunStatus::FAILED, true];
        yield 'unknown or not the initiator' => [false, null, true];
    }

    #[Test]
    #[DataProvider('outcomes')]
    public function onlyAnEndedOrUnknownRunReleasesTheConversation(bool $cancelled, ?AgentRunStatus $status, bool $releases): void
    {
        self::assertSame($releases, (new WaitingRunCancel($cancelled, $status))->releasesConversation());
    }
}
