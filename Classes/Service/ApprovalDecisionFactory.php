<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Domain\Enum\ApprovalDenialReason;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrMcpAgent\Enum\DenyReason;

/**
 * Builds the decision handed to nr-llm's approve(), with the reason a denial
 * was given (ADR-018): nr-llm's `ApprovalDenialReason`, cases `variant` and
 * `skip` (nr-llm ADR-214, PR 1024).
 */
class ApprovalDecisionFactory
{
    public function create(bool $approved, int $decidedBy, string $turnDigest, ?DenyReason $reason = null): ApprovalDecision
    {
        // nr-llm refuses a reason beside an approval.
        if ($approved || !$reason instanceof DenyReason) {
            return new ApprovalDecision($approved, $decidedBy, $turnDigest);
        }

        return new ApprovalDecision($approved, $decidedBy, $turnDigest, denialReason: self::denialReason($reason));
    }

    /** nr-llm's case for the card's answer. */
    public static function denialReason(DenyReason $reason): ApprovalDenialReason
    {
        return match ($reason) {
            DenyReason::Variant => ApprovalDenialReason::VARIANT,
            DenyReason::Skip    => ApprovalDenialReason::SKIP,
            // nr-llm's closed enum has no "keep": to the run it is a point the
            // editor leaves, so the model moves on to the next one. What it
            // means for the chat's open points is decided in OpenPointTracker.
            DenyReason::Keep => ApprovalDenialReason::SKIP,
        };
    }
}
