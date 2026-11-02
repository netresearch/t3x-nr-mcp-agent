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
 * @phpstan-type Entry array{
 *     outcome: string,
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

    /**
     * Tools that only drive the chat's own display (ADR-020) or ask the
     * editor something (ADR-018): they say nothing about the installation.
     */
    private const DISPLAY_TOOLS = ['chat_set_progress', 'chat_highlight_element', 'chat_list_open_points', 'ask_choice'];

    /**
     * @return Entry
     */
    public static function of(AgentRunResult $result, ?string $decision = null): array
    {
        $tools   = [];
        $writes  = [];
        $waiting = [];
        $ran     = [];
        foreach ($result->steps as $step) {
            if ($step->kind === RunStep::KIND_TOOL && is_string($step->toolName) && !in_array($step->toolName, self::DISPLAY_TOOLS, true)) {
                $name = self::token($step->toolName);
                $tools[$name] ??= ['tool' => $name, 'calls' => 0, 'errors' => 0];
                ++$tools[$name]['calls'];
                if ($step->toolIsError !== false) {
                    ++$tools[$name]['errors'];
                }

                $ran[] = $name;

                continue;
            }

            if ($step->kind === RunStep::KIND_WRITE && $step->writeTarget instanceof RecordReference) {
                $writes[] = [
                    'table'    => self::token($step->writeTarget->table),
                    'uid'      => $step->writeTarget->uid,
                    'complete' => $step->hookFailedAfterWrite === true ? null : match ($step->writeCompleteness) {
                        WriteCompleteness::COMPLETE => true,
                        WriteCompleteness::PARTIAL  => false,
                        null                        => null,
                    },
                ];

                continue;
            }

            if ($step->kind === RunStep::KIND_LLM) {
                // The calls the model asked for last: the one still waiting
                // when the run paused is among them. Names only.
                $waiting = [];
                foreach ($step->requestedToolCalls ?? [] as $call) {
                    if (is_array($call) && is_string($call['name'] ?? null) && !in_array($call['name'], self::DISPLAY_TOOLS, true)) {
                        $waiting[] = self::token($call['name']);
                    }
                }

                $ran = [];
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

        return $entry;
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
