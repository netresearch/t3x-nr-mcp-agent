<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrMcpAgent\Service\NrLlmWaitingRunCanceller;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The adapter to nr-llm's guarded cancel (nr-llm ADR-214, PR 1024): used
 * where the installed nr-llm has it, null where it does not.
 */
#[CoversClass(NrLlmWaitingRunCanceller::class)]
final class NrLlmWaitingRunCancellerTest extends TestCase
{
    #[Test]
    public function withoutTheGuardedCancelItAnswersNull(): void
    {
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->expects(self::never())->method('cancel');

        self::assertNull((new NrLlmWaitingRunCanceller($runtime))->cancelIfWaiting(AiActorContext::backendUser(1), 'run'));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function outcomes(): iterable
    {
        yield 'cancelled' => [true];
        yield 'not waiting' => [false];
    }

    #[Test]
    #[DataProvider('outcomes')]
    public function itPassesTheGuardedOutcomeOn(bool $cancelled): void
    {
        $runtime = $this->createMock(RuntimeWithGuardedCancel::class);
        $runtime->expects(self::once())->method('cancelIfWaiting')->with(self::anything(), 'run')
            ->willReturn(new GuardedCancelResultFixture($cancelled));
        $runtime->expects(self::never())->method('cancel');

        self::assertSame($cancelled, (new NrLlmWaitingRunCanceller($runtime))->cancelIfWaiting(AiActorContext::backendUser(1), 'run'));
    }
}

/** The shape nr-llm PR 1024 adds to AgentRuntimeInterface. */
abstract class RuntimeWithGuardedCancel implements AgentRuntimeInterface
{
    abstract public function cancelIfWaiting(AiActorContext $actor, string $runUuid): GuardedCancelResultFixture;
}

final readonly class GuardedCancelResultFixture
{
    public function __construct(public bool $cancelled) {}
}
