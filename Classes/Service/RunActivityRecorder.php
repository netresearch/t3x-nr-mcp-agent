<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Closure;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;

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
 */
readonly class RunActivityRecorder
{
    /** Older entries are dropped beyond this; a turn rarely comes near it. */
    public const MAX_ENTRIES = 100;

    /** Step kinds worth showing: a model round, and a tool call. */
    private const SHOWN_KINDS = [RunStep::KIND_LLM, RunStep::KIND_TOOL];

    public function __construct(
        private ConversationRepository $repository,
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
     * @return Closure(RunStep): void
     */
    public function onStep(Conversation $conversation): Closure
    {
        return function (RunStep $step) use ($conversation): void {
            if (!in_array($step->kind, self::SHOWN_KINDS, true)) {
                return;
            }

            $entry = [
                'kind' => $step->kind,
                'round' => $step->round,
                'ms' => (int) round($step->durationMs),
            ];
            if ($step->kind === RunStep::KIND_TOOL) {
                $entry['tool'] = $step->toolName ?? '';
                $entry['error'] = $step->toolIsError === true;
            }

            $this->append($conversation, $entry);
        };
    }

    /** The user's decision on a pending tool call, in the list where it happened. */
    public function recordDecision(Conversation $conversation, bool $approved): void
    {
        $this->append($conversation, ['kind' => 'approval', 'approved' => $approved]);
    }

    /**
     * @param array<string, bool|int|string> $entry
     */
    private function append(Conversation $conversation, array $entry): void
    {
        $entries = $conversation->getActivity();
        $entries[] = $entry;
        $conversation->setActivity(array_slice($entries, -self::MAX_ENTRIES));
        $this->write($conversation);
    }

    private function write(Conversation $conversation): void
    {
        if ($conversation->getUid() > 0) {
            $this->repository->updateActivity($conversation->getUid(), $conversation->getActivityJson());
        }
    }
}
