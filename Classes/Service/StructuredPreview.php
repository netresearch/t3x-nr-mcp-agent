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
 * (ADR-023): per field the current and the proposed value, and for a value
 * with a length rule its count and range.
 *
 * nr-llm has no such member yet; it is being added as an optional interface
 * on writers whose result the view carries. This reads
 * `PendingCallView::$structuredPreview` where it exists, as a list of entries
 * shaped `{field, current, proposed, measure?: {count, min, max}}`, objects or
 * arrays. **ASSUMPTION:** that member name and shape are the ones agreed for
 * the nr-llm change; if they differ, this class is the one place to adapt.
 * Without it, or with an entry that does not have that shape, the card shows
 * the preview lines as nr-llm wrote them. Nothing is ever parsed from those
 * lines.
 */
final class StructuredPreview
{
    /**
     * @return list<array{field: string, current: string, proposed: string, measure: array{count: int, min: int, max: int}|null}>|null
     */
    public static function of(PendingCallView $call): ?array
    {
        if (!property_exists($call, 'structuredPreview')) {
            return null;
        }

        return self::fromEntries($call->structuredPreview);
    }

    /**
     * The entries in the card's shape, or null when there are none or one of
     * them is not shaped as agreed.
     *
     * @return list<array{field: string, current: string, proposed: string, measure: array{count: int, min: int, max: int}|null}>|null
     */
    public static function fromEntries(mixed $entries): ?array
    {
        if (!is_array($entries) || $entries === []) {
            return null;
        }

        $fields = [];
        foreach ($entries as $entry) {
            $entry = is_object($entry) ? get_object_vars($entry) : $entry;
            if (!is_array($entry)
                || !is_string($entry['field'] ?? null)
                || !is_string($entry['current'] ?? null)
                || !is_string($entry['proposed'] ?? null)
            ) {
                // One entry nr-llm did not shape as agreed: show the lines.
                return null;
            }

            $fields[] = [
                'field'    => $entry['field'],
                'current'  => $entry['current'],
                'proposed' => $entry['proposed'],
                'measure'  => self::measure($entry['measure'] ?? null),
            ];
        }

        return $fields;
    }

    /**
     * @return array{count: int, min: int, max: int}|null
     */
    private static function measure(mixed $measure): ?array
    {
        $measure = is_object($measure) ? get_object_vars($measure) : $measure;
        if (!is_array($measure) || !is_int($measure['count'] ?? null) || !is_int($measure['min'] ?? null) || !is_int($measure['max'] ?? null)) {
            return null;
        }

        return ['count' => $measure['count'], 'min' => $measure['min'], 'max' => $measure['max']];
    }
}
