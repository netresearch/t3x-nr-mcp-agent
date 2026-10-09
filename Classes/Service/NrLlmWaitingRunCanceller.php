<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\GuardedCancelResult;

/**
 * The nr-llm side of {@see WaitingRunCancellerInterface}: nr-llm's
 * `AgentRuntimeInterface::cancelIfWaiting()` (nr-llm ADR-214, merged with
 * nr-llm PR 1024, first released in 0.41). Checked at call time, so an nr-llm
 * without it keeps working through the chat's own fallback.
 */
final readonly class NrLlmWaitingRunCanceller implements WaitingRunCancellerInterface
{
    public function __construct(
        private AgentRuntimeInterface $agentRuntime,
    ) {}

    public function cancelIfWaiting(AiActorContext $actor, string $runUuid): ?WaitingRunCancel
    {
        if (!self::isAvailable($this->agentRuntime)) {
            return null;
        }

        $result = $this->agentRuntime->cancelIfWaiting($actor, $runUuid);

        return $result instanceof GuardedCancelResult
            ? new WaitingRunCancel($result->cancelled, $result->status)
            : null;
    }

    /** Whether the installed nr-llm has the guarded cancel. */
    public static function isAvailable(AgentRuntimeInterface $runtime): bool
    {
        return class_exists(GuardedCancelResult::class) && method_exists($runtime, 'cancelIfWaiting');
    }
}
