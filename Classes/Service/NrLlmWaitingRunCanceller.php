<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;

/**
 * The nr-llm side of {@see WaitingRunCancellerInterface}: nr-llm's guarded
 * `cancelIfWaiting()` (nr-llm ADR-214, PR 1024), which answers with a result
 * object carrying `cancelled`. Checked at call time, so nr-llm releases
 * without it keep working through the chat's own fallback.
 */
final readonly class NrLlmWaitingRunCanceller implements WaitingRunCancellerInterface
{
    public function __construct(
        private AgentRuntimeInterface $agentRuntime,
    ) {}

    public function cancelIfWaiting(AiActorContext $actor, string $runUuid): ?bool
    {
        if (!method_exists($this->agentRuntime, 'cancelIfWaiting')) {
            return null;
        }

        $result = $this->agentRuntime->cancelIfWaiting($actor, $runUuid);
        $cancelled = is_object($result) && property_exists($result, 'cancelled') ? $result->cancelled : $result;

        return is_bool($cancelled) ? $cancelled : null;
    }
}
