<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

use Netresearch\NrMcpAgent\Service\OpenPoint\OpenPointVisibility;

/**
 * The open points the current backend user may see, from the chat's store:
 * {@see OpenPointVisibility::visibleForCurrentUser()} (ADR-022).
 *
 * - A point whose skill is gone (an empty `skillIdentifier`) is not shown.
 * - `skill` is the slug of the stored identifier (the part after
 *   `<source uid>:`). The widgets resolve it like a configured bare slug, so
 *   the most trusted source that carries it starts the tour.
 */
final readonly class VisibleOpenPointReader implements OpenPointReaderInterface
{
    public function __construct(
        private OpenPointVisibility $visibility,
    ) {}

    public function findOpen(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $points = [];
        foreach ($this->visibility->visibleForCurrentUser($limit) as $point) {
            if ($point->skillIdentifier === '') {
                continue;
            }

            $points[] = new OpenPoint($point->pageUid, $point->languageUid, $this->slug($point->skillIdentifier), $point->summary);
        }

        return $points;
    }

    /** The part of a skill identifier after `<source uid>:`, or the identifier itself. */
    private function slug(string $identifier): string
    {
        return preg_match('/^\d+:(.+)$/', $identifier, $match) === 1 ? $match[1] : $identifier;
    }
}
