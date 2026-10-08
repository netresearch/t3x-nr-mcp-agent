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
 * nr-llm's `cancelIfWaiting()` does not exist yet, so nothing implements
 * this interface. Without an implementation the chat reads the run's status
 * and calls `AgentRuntimeInterface::cancel()`, which settles a run in any
 * state that has not finished; a decision landing between the read and the
 * cancel is the gap this interface closes.
 */
interface WaitingRunCancellerInterface
{
    /**
     * True when the run was waiting and is cancelled now; false when it was
     * not waiting (any more), or the actor may not cancel it.
     */
    public function cancelIfWaiting(AiActorContext $actor, string $runUuid): bool;
}
