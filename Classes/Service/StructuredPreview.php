<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Service\Agent\Inbox\PendingCallView;

/**
 * The structured preview of a pending write, when nr-llm's view carries one
 * (ADR-023; nr-llm ADR-214, item 9): per field its label, the value stored
 * now, the proposed value and, where nr-llm measured it, its length against
 * the configured range.
 *
 * Read from `PendingCallView::structuredPreviewArray()` where nr-llm has it
 * (nr-llm PR 1036). Without it, without entries, or with an entry that is not
 * shaped as nr-llm documents it, the card shows the preview lines as nr-llm
 * wrote them. Nothing is ever parsed from those lines.
 *
 * The values are raw: a rich-text field carries its stored HTML, and the
 * proposed value is model-chosen text. They pass through here unchanged and
 * the chat renders them as text only.
 *
 * @phpstan-type Entry array{field: string, label: string, current: string|null, proposed: string, measure: array{count: int, min: int|null, max: int|null}|null}
 */
final class StructuredPreview
{
    /**
     * @return list<Entry>|null
     */
    public static function of(PendingCallView $call): ?array
    {
        $read = [$call, 'structuredPreviewArray'];
        if (!is_callable($read)) {
            return null;
        }

        return self::fromEntries($read());
    }

    /**
     * The entries in the card's shape, or null when there are none or one of
     * them is not shaped as nr-llm documents it.
     *
     * @return list<Entry>|null
     */
    public static function fromEntries(mixed $entries): ?array
    {
        if (!is_array($entries) || $entries === []) {
            return null;
        }

        $fields = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)
                || !is_string($entry['field'] ?? null)
                || $entry['field'] === ''
                || !is_string($entry['proposed'] ?? null)
                || !is_string($entry['current'] ?? null) && ($entry['current'] ?? null) !== null
            ) {
                // One entry nr-llm did not shape as documented: show the lines.
                return null;
            }

            $fields[] = [
                'field'    => $entry['field'],
                'label'    => is_string($entry['label'] ?? null) ? $entry['label'] : '',
                'current'  => $entry['current'] ?? null,
                'proposed' => $entry['proposed'],
                'measure'  => self::measure($entry['measure'] ?? null),
            ];
        }

        return $fields;
    }

    /**
     * @return array{count: int, min: int|null, max: int|null}|null
     */
    private static function measure(mixed $measure): ?array
    {
        if (!is_array($measure) || !is_int($measure['count'] ?? null)) {
            return null;
        }

        $min = $measure['min'] ?? null;
        $max = $measure['max'] ?? null;

        return [
            'count' => $measure['count'],
            'min'   => is_int($min) ? $min : null,
            'max'   => is_int($max) ? $max : null,
        ];
    }
}
