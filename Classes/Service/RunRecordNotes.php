<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;

/**
 * The conversation's record of what ran, as notes for the model (ADR-030).
 *
 * A turn starts a new run, and nr-llm hands a new run only the transcript's
 * text: the tools an earlier run called, their results and the decisions on
 * its cards are not part of it. Asked later what happened, the model had its
 * own prose and the rule that a change counts only with a write result — and
 * said that nothing had happened, about six saved fields.
 *
 * Each note is built per turn from the run record and the card outcomes,
 * never stored as text, and never taken from the model's words. A note names
 * tools, tables, uids and field names, and what became of them; it carries no
 * stored value. Where the transcript stands is kept: a note goes to the turn
 * it belongs to.
 */
final class RunRecordNotes
{
    /** Notes beyond this are left out, oldest first, and the omission is said. */
    public const MAX_NOTES = 40;

    /** Characters of all notes together; older notes are left out first. */
    public const MAX_CHARS = 6000;

    private const PREFIX = '[Chat record — written by the chat from the run, not by the assistant: ';

    /**
     * @return list<array{at: int, text: string}> `at` is the number of
     *                                            transcript messages there were when it was recorded
     */
    public static function of(Conversation $conversation): array
    {
        $notes = self::inRecordedOrder($conversation);

        // Over the budget, notes of steps in which nothing ran go first, then
        // the oldest: a long talk must not push a tour's saves out.
        $keep = array_fill(0, count($notes), true);
        foreach ([true, false] as $plainOnly) {
            foreach ($notes as $i => $note) {
                if (!self::overBudget($notes, $keep)) {
                    break 2;
                }

                if (!$plainOnly || $note['plain']) {
                    $keep[$i] = false;
                }
            }
        }

        $kept = [];
        foreach ($notes as $i => $note) {
            if ($keep[$i]) {
                $kept[] = ['at' => $note['at'], 'text' => $note['text']];
            }
        }

        $omitted = count($notes) - count($kept);
        if ($omitted > 0 && $kept !== []) {
            $kept[0]['text'] = sprintf(
                '%s%d earlier entries of this conversation exist but are not shown here; what they record cannot be read in this turn.]',
                self::PREFIX,
                $omitted,
            ) . "\n" . $kept[0]['text'];
        }

        return $kept;
    }

    /**
     * Card outcomes and run records in the order they were recorded: a run
     * record knows how many card outcomes existed before it. Sorted by
     * position, ties keep that order.
     *
     * @return list<array{at: int, text: string, plain: bool}>
     */
    private static function inRecordedOrder(Conversation $conversation): array
    {
        $cards = $conversation->getCardOutcomes();
        $next  = 0;
        $notes = [];
        foreach ($conversation->getRunRecords() as $record) {
            for (; $next < min($record['cards'], count($cards)); ++$next) {
                $notes[] = ['at' => $cards[$next]['after'], 'text' => self::cardText($cards[$next]), 'plain' => false];
            }

            $notes[] = ['at' => $record['after'], 'text' => self::recordText($record), 'plain' => self::isPlain($record)];
        }

        for (; $next < count($cards); ++$next) {
            $notes[] = ['at' => $cards[$next]['after'], 'text' => self::cardText($cards[$next]), 'plain' => false];
        }

        // usort is stable: at the same position the recorded order stays.
        usort($notes, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return $notes;
    }

    /**
     * @param list<array{at: int, text: string, plain: bool}> $notes
     * @param array<int, bool>                                $keep
     */
    private static function overBudget(array $notes, array $keep): bool
    {
        $count = 0;
        $chars = 0;
        foreach ($notes as $i => $note) {
            if ($keep[$i]) {
                ++$count;
                $chars += mb_strlen($note['text']);
            }
        }

        return $count > self::MAX_NOTES || $chars > self::MAX_CHARS;
    }

    /**
     * A completed step in which nothing ran, nothing was written and nothing
     * was decided.
     *
     * @param array{after: int, cards: int, outcome: string, decision?: string, tools: list<array{tool: string, calls: int, errors: int}>, writes: list<array{table: string, uid: int, complete: bool|null}>, waiting: list<string>} $record
     */
    private static function isPlain(array $record): bool
    {
        return $record['outcome'] === 'completed' && $record['tools'] === [] && $record['writes'] === []
            && $record['waiting'] === [] && !isset($record['decision']);
    }

    /**
     * @param array{outcome: string, after: int, tool: string, table: string, uid: int, fields: list<string>} $outcome
     */
    private static function cardText(array $outcome): string
    {
        $target = $outcome['table'] !== '' && $outcome['uid'] > 0
            ? sprintf(' on %s:%d', RunRecord::token($outcome['table']), $outcome['uid'])
            : '';
        $fields = array_values(array_filter(array_map(RunRecord::token(...), $outcome['fields']), static fn (string $f): bool => $f !== ''));
        $result = match ($outcome['outcome']) {
            'applied'     => 'approved by the editor and saved',
            'check'       => 'approved by the editor; the write ran, but whether it is complete could not be confirmed',
            'not_applied' => 'approved by the editor, but NOT saved',
            'skipped'     => 'skipped by the editor, NOT saved',
            'kept'        => 'the editor kept the current value, NOT saved',
            'variant'     => 'the editor asked for another variant, NOT saved',
            default       => 'outcome ' . RunRecord::token($outcome['outcome']),
        };

        return sprintf(
            '%sproposal %s%s%s — %s.]',
            self::PREFIX,
            RunRecord::token($outcome['tool']),
            $target,
            $fields !== [] ? ' (fields: ' . implode(', ', $fields) . ')' : '',
            $result,
        );
    }

    /**
     * @param array{after: int, cards: int, outcome: string, decision?: string, tools: list<array{tool: string, calls: int, errors: int}>, writes: list<array{table: string, uid: int, complete: bool|null}>, waiting: list<string>} $record
     */
    private static function recordText(array $record): string
    {
        if (($record['decision'] ?? null) === RunRecord::DECISION_WITHDRAWN) {
            return self::PREFIX . 'the editor moved on without deciding; the waiting proposal or question was withdrawn and nothing from it was saved.]';
        }

        $parts   = [];
        $parts[] = match ($record['decision'] ?? null) {
            RunRecord::DECISION_APPROVED => 'the editor approved the waiting proposal',
            RunRecord::DECISION_DENIED   => 'the editor declined the waiting proposal, so it was NOT saved',
            RunRecord::DECISION_SKIPPED  => 'the editor skipped the waiting proposal, so it was NOT saved',
            RunRecord::DECISION_KEPT     => 'the editor kept the current value, so the proposal was NOT saved',
            RunRecord::DECISION_VARIANT  => 'the editor asked for another variant, so the proposal was NOT saved',
            default                      => '',
        };

        $tools = [];
        foreach ($record['tools'] as $tool) {
            $tools[] = sprintf('%s ×%d', RunRecord::token($tool['tool']), $tool['calls'])
                . ($tool['errors'] > 0 ? sprintf(' (%d failed)', $tool['errors']) : '');
        }

        $parts[] = $tools !== [] ? 'tools run: ' . implode(', ', $tools) : '';

        $saved   = [];
        $partial = [];
        $unclear = [];
        foreach ($record['writes'] as $write) {
            $target = sprintf('%s:%d', RunRecord::token($write['table']), $write['uid']);
            match ($write['complete']) {
                true  => $saved[]   = $target,
                false => $partial[] = $target,
                null  => $unclear[] = $target,
            };
        }

        if ($saved !== []) {
            $parts[] = 'saved: ' . implode(', ', array_unique($saved));
        }

        if ($partial !== []) {
            $parts[] = 'only partly saved: ' . implode(', ', array_unique($partial));
        }

        if ($unclear !== []) {
            $parts[] = 'written, completeness not confirmed: ' . implode(', ', array_unique($unclear));
        }

        if ($record['writes'] === [] && $record['outcome'] !== RunRecord::OUTCOME_UNKNOWN) {
            $parts[] = $tools === [] ? 'no tool ran and nothing was written in this step' : 'nothing was written in this step';
        }

        if ($record['waiting'] !== []) {
            $parts[] = 'proposed ' . implode(', ', array_map(RunRecord::token(...), $record['waiting'])) . ", waiting for the editor's decision — not saved yet";
        }

        $parts[] = match ($record['outcome']) {
            'completed', 'awaiting_approval', 'awaiting_input' => '',
            RunRecord::OUTCOME_UNKNOWN                         => 'the step failed before the chat received its steps; what it did, if anything, is not known',
            default                                            => sprintf('the run ended as "%s" before it finished; only what is listed here happened', RunRecord::token($record['outcome'])),
        };

        return self::PREFIX . implode('; ', array_values(array_filter($parts, static fn (string $p): bool => $p !== ''))) . '.]';
    }
}
