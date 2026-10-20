<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Enum;

use Netresearch\NrLlm\Service\Agent\Exception\ProcessRunDecidedInChatException;
use Netresearch\NrLlm\Service\Agent\Exception\ProcessRunNeedsSecondApproverException;
use Netresearch\NrLlm\Service\Agent\Exception\ProcessVerdictUnavailableException;
use Throwable;

/**
 * The refusals nr-llm raises for a guided process when a decision on one of
 * its runs is taken (nr-llm ADR-214 item 6, nr-llm PR 1029).
 *
 * - VerdictUnavailable: whether the run is a guided process could not be
 *   checked. Nothing was decided; the run still waits, and the same decision
 *   can be tried again.
 * - DecidedInChat: only the person who started the process may decide it.
 *   The run still waits.
 * - NeedsSecondApprover: the run's configuration requires a second approver,
 *   under which nobody could apply a proposal of the process; nr-llm stopped
 *   the run before anything was carried out.
 */
enum ProcessRefusal: string
{
    case VerdictUnavailable  = ProcessVerdictUnavailableException::class;
    case DecidedInChat       = ProcessRunDecidedInChatException::class;
    case NeedsSecondApprover = ProcessRunNeedsSecondApproverException::class;

    /** The refusal the exception is, or null for anything else. */
    public static function of(Throwable $e): ?self
    {
        foreach (self::cases() as $refusal) {
            if (is_a($e, $refusal->value)) {
                return $refusal;
            }
        }

        return null;
    }

    /** Whether nr-llm stopped the run; otherwise it still waits for the decision. */
    public function stopsRun(): bool
    {
        return $this === self::NeedsSecondApprover;
    }

    /** The reason a decided card comes back with, for a refusal that leaves the run waiting. */
    public function approvalHandBack(): ?ApprovalHandBackReason
    {
        return match ($this) {
            self::VerdictUnavailable  => ApprovalHandBackReason::VerdictUnavailable,
            self::DecidedInChat       => ApprovalHandBackReason::DecidedInChat,
            self::NeedsSecondApprover => null,
        };
    }

    /** The reason an answered question comes back with, for a refusal that leaves the run waiting. */
    public function inputHandBack(): ?InputHandBackReason
    {
        return match ($this) {
            self::VerdictUnavailable  => InputHandBackReason::VerdictUnavailable,
            self::DecidedInChat       => InputHandBackReason::DecidedInChat,
            self::NeedsSecondApprover => null,
        };
    }
}
