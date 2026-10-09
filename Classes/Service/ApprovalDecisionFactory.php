<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use BackedEnum;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrMcpAgent\Enum\DenyReason;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionType;

/**
 * Builds the decision handed to nr-llm's approve(), with the reason a denial
 * was given when nr-llm can carry one (ADR-018).
 *
 * nr-llm's ApprovalDecision gains a `denialReason` argument typed as its enum
 * `ApprovalDenialReason` (cases `variant`, `skip`; nr-llm ADR-214, nr-llm
 * PR 1024). Until a release carries it, the decision is a plain denial. The
 * argument is read by reflection, so this extension works on every supported
 * nr-llm: a string-typed argument takes the value, a backed-enum-typed one the
 * case of that value, anything else nothing.
 */
class ApprovalDecisionFactory
{
    private const REASON_PARAMETERS = ['denialReason', 'reason'];

    public function create(bool $approved, int $decidedBy, string $turnDigest, ?DenyReason $reason = null): ApprovalDecision
    {
        $argument = $approved || $reason === null ? null : $this->reasonArgument($reason);
        if ($argument === null) {
            return new ApprovalDecision($approved, $decidedBy, $turnDigest);
        }

        return new ApprovalDecision($approved, $decidedBy, $turnDigest, ...$argument);
    }

    /** Whether nr-llm's decision can carry a denial reason. */
    public function carriesReason(): bool
    {
        return $this->reasonArgument(DenyReason::Skip) !== null;
    }

    /**
     * The named argument that carries the reason, or null when nr-llm's
     * decision has none it can take.
     *
     * @return array<string, mixed>|null
     */
    protected function reasonArgument(DenyReason $reason): ?array
    {
        $constructor = (new ReflectionClass(ApprovalDecision::class))->getConstructor();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if (!in_array($parameter->getName(), self::REASON_PARAMETERS, true)) {
                continue;
            }

            $value = self::valueFor($parameter->getType(), $reason);
            if ($value !== null) {
                return [$parameter->getName() => $value];
            }
        }

        return null;
    }

    /**
     * The reason as the parameter type takes it: the string value, or the
     * case of a string-backed enum with that value; null for any other type
     * or an enum without that case.
     */
    public static function valueFor(?ReflectionType $type, DenyReason $reason): string|BackedEnum|null
    {
        if (!$type instanceof ReflectionNamedType) {
            return null;
        }

        if ($type->getName() === 'string') {
            return $reason->value;
        }

        $class = $type->getName();
        if (!$type->isBuiltin() && enum_exists($class) && is_subclass_of($class, BackedEnum::class)) {
            $case = $class::tryFrom($reason->value);

            return $case instanceof BackedEnum ? $case : null;
        }

        return null;
    }
}
