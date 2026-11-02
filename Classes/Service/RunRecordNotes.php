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
        $notes = [];
        foreach ($conversation->getCardOutcomes() as $outcome) {
            $notes[] = ['at' => $outcome['after'], 'order' => count($notes), 'text' => self::cardText($outcome)];
        }

        foreach ($conversation->getRunRecords() as $record) {
            $notes[] = ['at' => $record['after'], 'order' => count($notes), 'text' => self::recordText($record)];
        }

        // Card outcomes are recorded before the record of the same segment,
        // so at the same position the card comes first.
        usort($notes, static fn (array $a, array $b): int => [$a['at'], $a['order']] <=> [$b['at'], $b['order']]);

        $kept  = [];
        $chars = 0;
        for ($i = count($notes) - 1; $i >= 0; --$i) {
            $length = mb_strlen($notes[$i]['text']);
            if (count($kept) >= self::MAX_NOTES || $chars + $length > self::MAX_CHARS) {
                break;
            }

            $chars += $length;
            array_unshift($kept, ['at' => $notes[$i]['at'], 'text' => $notes[$i]['text']]);
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
     * @param array{after: int, outcome: string, decision?: string, tools: list<array{tool: string, calls: int, errors: int}>, writes: list<array{table: string, uid: int, complete: bool|null}>, waiting: list<string>} $record
     */
    private static function recordText(array $record): string
    {
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
