<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Service\Tool\Builtin\AskChoiceTool;

/**
 * Which tool steps of one run segment nr-llm refused rather than ran, read
 * the same way by the activity list and by the run record (ADR-030), so the
 * two cannot disagree.
 *
 * nr-llm records a refused call as a tool step with an error result, like a
 * tool that ran and failed. Two facts tell them apart, read from the step's
 * structure and never from its result text:
 *
 * - where the step stands: a continuation opens with the calls of the turn
 *   that waited, before its first model round;
 * - its duration: nr-llm records the refusals of a waiting turn's calls with
 *   exactly 0.0 ms (ToolLoopService::recordResumedRefusal() and
 *   settleBesideProposal(), nr-llm 0.41.7 to 0.43.4); a call that ran is
 *   timed, and so is a call its invocation policy refused, which therefore
 *   reads as failed;
 * - whether the tool asks the editor something: after an approval nr-llm
 *   refuses such a call because it has no answer, after an answer only
 *   because the tool is no longer offered (it runs every call of the
 *   question's tool with the answer).
 *
 * The readings:
 *
 * - DECLINED: a call of a card the editor did not approve. The editor's
 *   decision is the entry; the call is not a tool that ran.
 * - NOT_PERMITTED: an approved call, or the answered question, nr-llm no
 *   longer lets run, because its tool was switched off or made admin-only
 *   while the run waited. The change or the answer was not taken: a failure
 *   the editor is told about.
 * - HELD: a call nr-llm did not run now and the model asks for again: a
 *   second write beside a proposal, a call that needs an answer or an
 *   approval the run does not have yet. Not a failure.
 * - null: the step ran, or is not a tool call.
 *
 * One instance reads one segment, step by step, in order.
 */
final class RefusedCalls
{
    /** A turn over the transcript. */
    public const AFTER_TURN = 'turn';

    /** The continuation after a card that was not approved. */
    public const AFTER_DENIAL = 'denial';

    /** The continuation after an approved card. */
    public const AFTER_APPROVAL = 'approval';

    /** The continuation after an answer to a question. */
    public const AFTER_INPUT = 'input';

    public const DECLINED = 'declined';

    public const NOT_PERMITTED = 'not_permitted';

    public const HELD = 'held';

    /**
     * The tools that ask the editor something (nr-llm's RequiresInputInterface;
     * nr-llm ships no other). The run record leaves the same tool out as one
     * that says nothing about the installation.
     */
    private const QUESTION_TOOLS = [AskChoiceTool::NAME];

    private bool $beforeFirstRound = true;

    /**
     * @param self::AFTER_* $segment
     */
    public function __construct(private readonly string $segment = self::AFTER_TURN) {}

    /**
     * @return self::DECLINED|self::NOT_PERMITTED|self::HELD|null
     */
    public function read(RunStep $step): ?string
    {
        if ($step->kind === RunStep::KIND_LLM) {
            $this->beforeFirstRound = false;
        }

        $opening  = $this->beforeFirstRound;
        $question = in_array($step->toolName, self::QUESTION_TOOLS, true);

        return match (true) {
            $step->kind !== RunStep::KIND_TOOL                            => null,
            $opening && $this->segment === self::AFTER_DENIAL             => self::DECLINED,
            $step->toolIsError !== true || $step->durationMs !== 0.0      => null,
            $opening && $this->segment === self::AFTER_APPROVAL           => $question ? self::HELD : self::NOT_PERMITTED,
            $opening && $this->segment === self::AFTER_INPUT && $question => self::NOT_PERMITTED,
            default                                                       => self::HELD,
        };
    }
}
