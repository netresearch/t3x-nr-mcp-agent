<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\OpenPoint;

use Netresearch\NrLlm\Domain\Enum\WriteCompleteness;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;

/**
 * Whether the call an approved card released wrote everything it planned
 * (nr-llm ADR-214, item 9, "Applied"; ADR-022).
 *
 * Read from the result of that approval alone: its first tool step is the
 * approved call, and the write step that follows it states the completeness
 * (`RunStep::$writeCompleteness` and `$hookFailedAfterWrite`, merged with
 * nr-llm PR 1023, first released in 0.41). Applied means the tool step is no
 * error and the write step says COMPLETE without the hook-failure flag. A
 * completeness that is not stated — every write on an nr-llm before 0.41 —
 * is not applied.
 */
final class AppliedWrite
{
    /**
     * @param array<mixed> $steps the result's trace
     */
    public static function inSteps(array $steps): bool
    {
        $toolStep = null;
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
                return self::isApplied($toolStep->toolIsError, self::statesComplete($step), self::hookFailedAfterWrite($step));
            }
        }

        return false;
    }

    /**
     * @param bool|null $toolIsError the approved call's tool step
     * @param bool      $complete    its write step states COMPLETE
     * @param bool|null $hookFailed  its write step's hook-failure flag
     */
    public static function isApplied(?bool $toolIsError, bool $complete, ?bool $hookFailed): bool
    {
        return $toolIsError === false && $complete && $hookFailed !== true;
    }

    private static function statesComplete(RunStep $step): bool
    {
        return enum_exists(WriteCompleteness::class)
            && property_exists($step, 'writeCompleteness')
            && $step->writeCompleteness === WriteCompleteness::COMPLETE;
    }

    private static function hookFailedAfterWrite(RunStep $step): ?bool
    {
        if (!property_exists($step, 'hookFailedAfterWrite')) {
            return null;
        }

        $flag = $step->hookFailedAfterWrite;

        return is_bool($flag) ? $flag : null;
    }
}
