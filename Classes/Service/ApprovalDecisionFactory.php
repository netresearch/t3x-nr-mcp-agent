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
 * was given when nr-llm can carry one (ADR-018).
 *
 * nr-llm's ApprovalDecision takes an optional `ApprovalDenialReason`
 * (cases `variant`, `skip`; nr-llm ADR-214, merged with nr-llm PR 1024 and
 * first released in 0.41). On an nr-llm without that enum the decision is a
 * plain denial, and the model learns the reason from the transcript.
 */
class ApprovalDecisionFactory
{
    public function create(bool $approved, int $decidedBy, string $turnDigest, ?DenyReason $reason = null): ApprovalDecision
    {
        // nr-llm refuses a reason beside an approval.
        if ($approved || !$reason instanceof DenyReason || !$this->carriesReason()) {
            return new ApprovalDecision($approved, $decidedBy, $turnDigest);
        }

        return new ApprovalDecision($approved, $decidedBy, $turnDigest, denialReason: self::denialReason($reason));
    }

    /** Whether the installed nr-llm's decision can carry a denial reason. */
    public function carriesReason(): bool
    {
        return enum_exists(ApprovalDenialReason::class);
    }

    /** nr-llm's case for the card's answer; only call where {@see self::carriesReason()} holds. */
    public static function denialReason(DenyReason $reason): ApprovalDenialReason
    {
        return match ($reason) {
            DenyReason::Variant => ApprovalDenialReason::VARIANT,
            DenyReason::Skip => ApprovalDenialReason::SKIP,
        };
    }
}
