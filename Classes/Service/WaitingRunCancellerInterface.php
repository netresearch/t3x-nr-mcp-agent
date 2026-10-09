<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;

/**
 * Cancel a run only while it still waits for a decision or an answer
 * (nr-llm ADR-214): one guarded transition, so a decision taken at the same
 * moment wins instead of being cancelled while it runs.
 *
 * `NrLlmWaitingRunCanceller` calls nr-llm's
 * `AgentRuntimeInterface::cancelIfWaiting()` (nr-llm PR 1024) where the
 * installed nr-llm has it. Where it does not, it answers null, and the chat
 * reads the run's status and calls `cancel()`, which settles a run in any
 * state that has not finished.
 */
interface WaitingRunCancellerInterface
{
    /**
     * True when the run was waiting and is cancelled now; false when it was
     * not waiting (any more), or the actor may not cancel it; null when the
     * installed nr-llm has no guarded cancel.
     */
    public function cancelIfWaiting(AiActorContext $actor, string $runUuid): ?bool;
}
