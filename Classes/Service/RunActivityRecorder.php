<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Closure;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\DenyReason;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Throwable;

/**
 * Writes what the agent is doing in the current turn onto the conversation,
 * step by step, so the chat's poll can show it while the turn runs (NEXT-172).
 *
 * nr-llm already persists every step of a run as agent-run events, but the
 * chat learns the run's uuid only when run() returns — too late for a view of
 * the running turn. run() and approve() take an `onStep` callback that fires
 * the moment a step settles; this recorder is that callback.
 *
 * Only a summary is stored: the kind of step, the round, the duration, the
 * tool's name and whether it failed. Tool arguments and results stay in
 * nr-llm's run record, where the AI Tasks timeline shows them under its own
 * access rules — a column the chat polls every two seconds is no place for a
 * record's content.
 *
 * Every write is a single-column update, never the whole row: the turn's own
 * persist() at the end writes the transcript, and a full-row write from here
 * would race it.
 *
 * A failed write is logged and dropped. nr-llm calls onStep before it
 * persists the step, and an exception from here would abort the run after a
 * tool — possibly a write — has already run, and cost that step its record
 * in the run. A list that misses an entry is the lesser harm.
 */
class RunActivityRecorder implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** Older entries are dropped beyond this; a turn rarely comes near it. */
    public const MAX_ENTRIES = 100;

    /**
     * The steps nr-llm sends while the model writes, by value: an nr-llm without
     * them (below 0.43.1) sends none, and the chat then shows the text at the end.
     */
    private const KIND_TEXT_DELTA = 'text_delta';

    private const KIND_TEXT_RESTART = 'text_restart';

    /** Step kinds worth showing: a model round, and a tool call. */
    private const SHOWN_KINDS = [RunStep::KIND_LLM, RunStep::KIND_TOOL];

    public function __construct(
        private readonly ConversationRepository $repository,
        private readonly ?TurnTimingRecorder $turnTiming = null,
        private readonly ?LiveTextRecorder $liveText = null,
    ) {}

    /** A new turn: the previous turn's activity no longer describes anything. */
    public function start(Conversation $conversation): void
    {
        $conversation->setActivity([]);
        $this->write($conversation);
    }

    /**
     * The callback for run() / approve(). Appends to what the conversation
     * already holds, so a continuation after an approval extends the list of
     * the run it belongs to.
     *
     * A call nr-llm refused is read as RefusedCalls reads it for the run
     * record: a declined card's call gets no entry, the decision is the
     * entry; a call held back is not a failure; an approved call nr-llm no
     * longer permits is a failure with its reason.
     *
     * @param RefusedCalls::AFTER_* $segment what the steps continue
     *
     * @return Closure(RunStep): void
     */
    public function onStep(Conversation $conversation, string $segment = RefusedCalls::AFTER_TURN): Closure
    {
        $refusals = new RefusedCalls($segment);

        return function (RunStep $step) use ($conversation, $refusals): void {
            if ($step->kind === self::KIND_TEXT_DELTA) {
                $this->turnTiming?->mark('firstText');
                $this->liveText?->append($conversation->getUid(), $step->round, $step->content ?? '');

                return;
            }

            if ($step->kind === self::KIND_TEXT_RESTART) {
                $this->liveText?->restart($conversation->getUid());

                return;
            }

            if (!in_array($step->kind, self::SHOWN_KINDS, true)) {
                return;
            }

            $refusal = $refusals->read($step);
            if ($refusal === RefusedCalls::DECLINED) {
                return;
            }

            if ($step->kind === RunStep::KIND_LLM) {
                $this->turnTiming?->mark('firstLlm');
                $this->liveText?->flush($conversation->getUid());
            }

            $this->append($conversation, $this->entryOf($step, $refusal));
        };
    }

    /**
     * @param RefusedCalls::DECLINED|RefusedCalls::NOT_PERMITTED|RefusedCalls::HELD|null $refusal
     *
     * @return array<string, bool|int|string>
     */
    private function entryOf(RunStep $step, ?string $refusal): array
    {
        $entry = [
            'kind'  => $step->kind,
            'round' => $step->round,
            'ms'    => (int) round($step->durationMs),
        ];
        if ($step->kind === RunStep::KIND_TOOL) {
            $entry['tool']  = $step->toolName ?? '';
            $entry['error'] = $step->toolIsError === true && $refusal !== RefusedCalls::HELD;
            if ($refusal !== null) {
                $entry['refusal'] = $refusal;
            }
        }

        return $entry;
    }

    /**
     * The user's decision on a pending tool call, in the list where it
     * happened, with the answer a declined card was given (ADR-018).
     */
    public function recordDecision(Conversation $conversation, bool $approved, ?DenyReason $reason = null): void
    {
        $entry = ['kind' => 'approval', 'approved' => $approved];
        if (!$approved && $reason instanceof DenyReason) {
            $entry['reason'] = $reason->value;
        }

        $this->append($conversation, $entry);
    }

    /**
     * @param array<string, bool|int|string> $entry
     */
    private function append(Conversation $conversation, array $entry): void
    {
        $entries   = $conversation->getActivity();
        $entries[] = $entry;
        $conversation->setActivity(array_slice($entries, -self::MAX_ENTRIES));
        $this->write($conversation);
    }

    private function write(Conversation $conversation): void
    {
        if ($conversation->getUid() <= 0) {
            return;
        }

        try {
            $this->repository->updateActivity($conversation->getUid(), $conversation->getActivityJson());
        } catch (Throwable $e) {
            $this->logger?->warning('Could not record the chat activity of conversation {uid}: {message}', [
                'uid'     => $conversation->getUid(),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
