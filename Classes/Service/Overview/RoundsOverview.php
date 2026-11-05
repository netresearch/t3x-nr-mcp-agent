<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Overview;

use Netresearch\NrLlm\Service\Agent\AgentRoundLimitsInterface;

/**
 * The model rounds a chat run gets (ADR-023, round-cap amendments of
 * 2026-10-22 and 2026-11-04): what the chat asks nr-llm for, clamped the way
 * nr-llm's runtime clamps it. `chatSource`/`processSource` say where the
 * number comes from: `chat` and `process` for this extension's settings,
 * `nrLlmDefault` for a chat run that asks for none, `nrLlmCeiling` for a value
 * above nr-llm's maximum.
 */
final readonly class RoundsOverview
{
    public function __construct(
        private AgentRoundLimitsInterface $roundLimits,
    ) {}

    /**
     * @param int|null $chat    the chat setting, null when it asks for nothing
     * @param int      $process the process setting
     *
     * @return array{chat: int, chatSource: string, process: int, processSource: string, ceiling: int}
     */
    public function rounds(?int $chat, int $process): array
    {
        $ceiling = $this->roundLimits->ceiling();

        return [
            'chat'          => $this->roundLimits->roundsFor($chat),
            'chatSource'    => $chat === null ? 'nrLlmDefault' : ($chat > $ceiling ? 'nrLlmCeiling' : 'chat'),
            'process'       => $this->roundLimits->roundsFor($process),
            'processSource' => $process > $ceiling ? 'nrLlmCeiling' : 'process',
            'ceiling'       => $ceiling,
        ];
    }
}
