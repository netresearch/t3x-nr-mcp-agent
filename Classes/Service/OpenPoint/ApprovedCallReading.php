<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\OpenPoint;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\WriteCompleteness;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrMcpAgent\Enum\ProposalOutcome;

/**
 * Which of nr-llm ADR-214's three states an approved call of a guided
 * process came back in, read from the result of that approval alone (item 9,
 * "Which reading applies"; ADR-023). The first tool step is the approved
 * call; the write step that follows it states the completeness and the
 * hook-failure flag (nr-llm PR 1023, first released in 0.41).
 *
 * - Applied: the tool step is no error, and its write step says COMPLETE
 *   without the hook-failure flag.
 * - Check: the tool step is no error, and the write step carries the
 *   hook-failure flag, or states no completeness, or there is no write step
 *   while the run was cancelled, lost its lease or failed — the write may
 *   have happened without a step to show it.
 * - Not applied: everything else — an error on the tool step, a PARTIAL
 *   write without the flag, or no write step on a run that did not abort.
 *
 * Null when the result holds no tool step: the call did not run.
 *
 * On an nr-llm before 0.41 no write states its completeness, so an approved
 * write reads as "check".
 */
final class ApprovedCallReading
{
    public static function of(AgentRunResult $result): ?ProposalOutcome
    {
        return self::ofSteps($result->steps, $result->outcome);
    }

    /**
     * @param array<mixed> $steps the result's trace
     * @param AgentRunOutcome|null $outcome how the run ended; only "check" against "not applied" depends on it
     */
    public static function ofSteps(array $steps, ?AgentRunOutcome $outcome): ?ProposalOutcome
    {
        $toolStep = null;
        $writeStep = null;
        foreach ($steps as $step) {
            if (!$step instanceof RunStep) {
                continue;
            }

            if (!$toolStep instanceof RunStep) {
                if ($step->kind === RunStep::KIND_TOOL) {
                    $toolStep = $step;
                }

                continue;
            }

            if ($step->kind === RunStep::KIND_TOOL) {
                break;
            }

            if ($step->kind === RunStep::KIND_WRITE) {
                $writeStep = $step;
                break;
            }
        }

        if (!$toolStep instanceof RunStep) {
            return null;
        }

        if ($toolStep->toolIsError !== false) {
            return ProposalOutcome::NotApplied;
        }

        if (!$writeStep instanceof RunStep) {
            return in_array($outcome, [AgentRunOutcome::CANCELLED, AgentRunOutcome::LEASE_LOST, AgentRunOutcome::FAILED], true)
                ? ProposalOutcome::Check
                : ProposalOutcome::NotApplied;
        }

        if (self::hookFailedAfterWrite($writeStep)) {
            return ProposalOutcome::Check;
        }

        return match (self::completeness($writeStep)) {
            'complete' => ProposalOutcome::Applied,
            'partial'  => ProposalOutcome::NotApplied,
            default    => ProposalOutcome::Check,
        };
    }

    /**
     * The write step's completeness, null when it states none.
     */
    private static function completeness(RunStep $step): ?string
    {
        if (!enum_exists(WriteCompleteness::class) || !property_exists($step, 'writeCompleteness')) {
            return null;
        }

        return match ($step->writeCompleteness) {
            WriteCompleteness::COMPLETE => 'complete',
            WriteCompleteness::PARTIAL  => 'partial',
            default                     => null,
        };
    }

    private static function hookFailedAfterWrite(RunStep $step): bool
    {
        return property_exists($step, 'hookFailedAfterWrite') && $step->hookFailedAfterWrite === true;
    }
}
