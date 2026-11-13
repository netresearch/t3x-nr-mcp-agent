<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Domain\Enum\WriteCompleteness;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;

/**
 * What one run segment did, as the chat keeps it for later turns (ADR-030).
 *
 * Built from the run's structured steps, never from the model's text, and
 * only from what nr-llm's privacy filter keeps at every level: the tool's
 * name, whether it failed, the record a write targeted and whether the
 * write was complete. Tool arguments, tool results and answers are not
 * read, so no stored value can reach a later prompt through the record.
 *
 * The run's uuid is kept beside it (`run`, ADR-034) so the change view can read
 * the run's events later. It is an identifier of the run, never shown to the
 * model: RunRecordNotes reads the fields it names and no other.
 *
 * @phpstan-type Entry array{
 *     outcome: string,
 *     run?: string,
 *     decision?: string,
 *     tools: list<array{tool: string, calls: int, errors: int}>,
 *     writes: list<array{table: string, uid: int, complete: bool|null}>,
 *     waiting: list<string>
 * }
 */
final class RunRecord
{
    /** The turn failed before the chat received the run's steps. */
    public const OUTCOME_UNKNOWN = 'unknown';

    public const DECISION_APPROVED = 'approved';

    public const DECISION_DENIED = 'denied';

    public const DECISION_SKIPPED = 'skipped';

    public const DECISION_KEPT = 'kept';

    public const DECISION_VARIANT = 'variant';

    /** The editor moved on while a card or a question waited; the run was released undecided. */
    public const DECISION_WITHDRAWN = 'withdrawn';

    /**
     * Tools that only drive the chat's own display (ADR-020) or ask the
     * editor something (ADR-018): they say nothing about the installation.
     */
    private const DISPLAY_TOOLS = ['chat_set_progress', 'chat_highlight_element', 'chat_list_open_points', 'ask_choice'];

    /**
     * @param RefusedCalls::AFTER_* $segment what the segment continues
     *
     * @return Entry
     */
    public static function of(AgentRunResult $result, ?string $decision = null, string $segment = RefusedCalls::AFTER_TURN): array
    {
        $tools   = [];
        $writes  = [];
        $waiting = [];
        $ran     = [];
        foreach (self::stepsThatRan($result->steps, $segment) as $step) {
            if ($step->kind === RunStep::KIND_TOOL && is_string($step->toolName) && !in_array($step->toolName, self::DISPLAY_TOOLS, true)) {
                $name = self::token($step->toolName);
                $tools[$name] ??= ['tool' => $name, 'calls' => 0, 'errors' => 0];
                ++$tools[$name]['calls'];
                $tools[$name]['errors'] += $step->toolIsError !== false ? 1 : 0;
                $ran[] = $name;
            } elseif ($step->kind === RunStep::KIND_WRITE && $step->writeTarget instanceof RecordReference) {
                $writes[] = self::writeOf($step->writeTarget, $step);
            } elseif ($step->kind === RunStep::KIND_LLM) {
                // The calls the model asked for last: the one still waiting
                // when the run paused is among them. Names only.
                $waiting = self::requestedNames($step);
                $ran     = [];
            }
        }

        $entry = [
            'outcome' => $result->outcome->value,
            'tools'   => array_values($tools),
            'writes'  => $writes,
            'waiting' => $result->outcome->value === 'awaiting_approval' ? array_values(array_diff($waiting, $ran)) : [],
        ];
        if ($decision !== null) {
            $entry['decision'] = $decision;
        }

        if ($result->runUuid !== '') {
            $entry['run'] = $result->runUuid;
        }

        return $entry;
    }

    /**
     * The steps of the segment, without the calls nr-llm refused and nobody
     * has to act on: a declined card's calls and calls it held back
     * (RefusedCalls). Listed as tools that failed, they read as failed
     * attempts to save. An approved call nr-llm no longer permits stays: the
     * change was not made.
     *
     * @param list<RunStep>         $steps
     * @param RefusedCalls::AFTER_* $segment
     *
     * @return list<RunStep>
     */
    private static function stepsThatRan(array $steps, string $segment): array
    {
        $refusals = new RefusedCalls($segment);
        $kept     = [];
        foreach ($steps as $step) {
            if (!in_array($refusals->read($step), [RefusedCalls::DECLINED, RefusedCalls::HELD], true)) {
                $kept[] = $step;
            }
        }

        return $kept;
    }

    /**
     * @return array{table: string, uid: int, complete: bool|null}
     */
    private static function writeOf(RecordReference $target, RunStep $step): array
    {
        $complete = match ($step->writeCompleteness) {
            WriteCompleteness::COMPLETE => true,
            WriteCompleteness::PARTIAL  => false,
            null                        => null,
        };

        return [
            'table'    => self::token($target->table),
            'uid'      => $target->uid,
            'complete' => $step->hookFailedAfterWrite === true ? null : $complete,
        ];
    }

    /**
     * @return list<string>
     */
    private static function requestedNames(RunStep $step): array
    {
        $names = [];
        foreach ($step->requestedToolCalls ?? [] as $call) {
            if (is_array($call) && is_string($call['name'] ?? null) && !in_array($call['name'], self::DISPLAY_TOOLS, true)) {
                $names[] = self::token($call['name']);
            }
        }

        return $names;
    }

    /**
     * A waiting card or question the editor left undecided by writing
     * something else or ending the tour (ADR-030).
     *
     * @return Entry
     */
    public static function withdrawn(): array
    {
        return ['outcome' => 'cancelled', 'tools' => [], 'writes' => [], 'waiting' => [], 'decision' => self::DECISION_WITHDRAWN];
    }

    /**
     * A turn that failed before nr-llm returned a result.
     *
     * @return Entry
     */
    public static function unknown(?string $decision = null): array
    {
        $entry = ['outcome' => self::OUTCOME_UNKNOWN, 'tools' => [], 'writes' => [], 'waiting' => []];
        if ($decision !== null) {
            $entry['decision'] = $decision;
        }

        return $entry;
    }

    /**
     * Identifiers only: table, field and tool names never carry more than
     * these characters, and nothing else reaches the prompt through them.
     */
    public static function token(string $value): string
    {
        return mb_substr((string) preg_replace('/[^A-Za-z0-9_.:-]/', '', $value), 0, 80);
    }
}
