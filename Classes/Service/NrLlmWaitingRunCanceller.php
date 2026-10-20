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
 * The nr-llm side of {@see WaitingRunCancellerInterface}: nr-llm's
 * `AgentRuntimeInterface::cancelIfWaiting()` (nr-llm ADR-214, PR 1024).
 */
final readonly class NrLlmWaitingRunCanceller implements WaitingRunCancellerInterface
{
    public function __construct(
        private AgentRuntimeInterface $agentRuntime,
    ) {}

    public function cancelIfWaiting(AiActorContext $actor, string $runUuid): WaitingRunCancel
    {
        $result = $this->agentRuntime->cancelIfWaiting($actor, $runUuid);

        return new WaitingRunCancel($result->cancelled, $result->status);
    }
}
