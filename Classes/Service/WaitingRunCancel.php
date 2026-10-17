<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;

/**
 * What a guarded cancel did, as nr-llm's `GuardedCancelResult` reports it
 * (nr-llm ADR-214, item 10): whether this call cancelled the waiting run, and
 * otherwise the run's status read after the attempt, null when the run is
 * unknown or the actor may not withdraw it.
 */
final readonly class WaitingRunCancel
{
    public function __construct(
        public bool $cancelled,
        public ?AgentRunStatus $status,
    ) {}

    /**
     * Whether the conversation may take the new turn. A run that is queued or
     * running carries a decision out, and one that still waits was handed
     * back in between; both keep the conversation (409). A run that ended, or
     * one the chat may not know of, is nothing to wait for.
     */
    public function releasesConversation(): bool
    {
        return $this->cancelled || $this->status === null || $this->status->isTerminal();
    }
}
