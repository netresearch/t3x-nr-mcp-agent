<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrMcpAgent\Enum\DenyReason;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Builds the decision handed to nr-llm's approve(), with the reason a denial
 * was given when nr-llm can carry one (ADR-018).
 *
 * The seam exists because nr-llm's ApprovalDecision does not take a reason
 * yet: the denial result the model reads is fixed text. The reason travels
 * as a constructor argument named `denialReason` (or `reason`) the moment
 * nr-llm adds one that accepts a string; until then the decision is a plain
 * denial. Read by reflection so this extension keeps working on every
 * supported nr-llm, with and without the argument.
 */
class ApprovalDecisionFactory
{
    private const REASON_PARAMETERS = ['denialReason', 'reason'];

    public function create(bool $approved, int $decidedBy, string $turnDigest, ?DenyReason $reason = null): ApprovalDecision
    {
        $parameter = $approved || $reason === null ? null : $this->reasonParameter();
        if ($parameter === null) {
            return new ApprovalDecision($approved, $decidedBy, $turnDigest);
        }

        $named = [$parameter => $reason->value];

        return new ApprovalDecision($approved, $decidedBy, $turnDigest, ...$named);
    }

    /** Whether nr-llm's decision can carry a denial reason. */
    public function carriesReason(): bool
    {
        return $this->reasonParameter() !== null;
    }

    protected function reasonParameter(): ?string
    {
        $constructor = (new ReflectionClass(ApprovalDecision::class))->getConstructor();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if (in_array($parameter->getName(), self::REASON_PARAMETERS, true)
                && $type instanceof ReflectionNamedType
                && $type->getName() === 'string'
            ) {
                return $parameter->getName();
            }
        }

        return null;
    }
}
