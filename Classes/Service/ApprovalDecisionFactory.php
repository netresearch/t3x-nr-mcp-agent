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
 * was given (ADR-018): nr-llm's `ApprovalDenialReason`, cases `variant`, `skip`
 * and `keep` (nr-llm ADR-214; `keep` since nr-llm 0.41.4).
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
            // "Keep" is its own case in nr-llm (0.41.4): the point is settled,
            // so the model must not say the editor skipped it. What it means
            // for the chat's open points is decided in OpenPointTracker.
            DenyReason::Keep => ApprovalDenialReason::KEEP,
        };
    }
}
