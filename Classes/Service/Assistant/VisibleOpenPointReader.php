<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

/**
 * The open points the current backend user may see, from the chat's store:
 * `Netresearch\NrMcpAgent\Service\OpenPoint\OpenPointVisibility::visibleForCurrentUser()`.
 *
 * - A point whose skill is gone (an empty `skillIdentifier`) is not shown.
 * - `skill` is the slug of the stored identifier (the part after
 *   `<source uid>:`). The widgets resolve it like a configured bare slug, so
 *   the most trusted source that carries it starts the tour.
 * - Without the store (`$visibility` null) there are no points, the same
 *   answer as NullOpenPointReader.
 *
 * The store is injected through an optional service reference (`@?` in
 * Services.yaml) and called by method and property name, so this class works
 * whether or not the store exists yet. Once it is part of the extension, the
 * parameter becomes `?OpenPointVisibility` and the checks go away.
 */
final readonly class VisibleOpenPointReader implements OpenPointReaderInterface
{
    public function __construct(
        private ?object $visibility = null,
    ) {}

    public function findOpen(int $limit): array
    {
        $visibility = $this->visibility;
        if ($limit <= 0 || $visibility === null || !method_exists($visibility, 'visibleForCurrentUser')) {
            return [];
        }

        $found = $visibility->visibleForCurrentUser($limit);
        $points = [];
        foreach (is_array($found) ? $found : [] as $point) {
            $fields = is_object($point) ? get_object_vars($point) : [];
            $pageUid = $fields['pageUid'] ?? null;
            $languageUid = $fields['languageUid'] ?? null;
            $identifier = $fields['skillIdentifier'] ?? null;
            $summary = $fields['summary'] ?? null;
            if (!is_int($pageUid) || !is_int($languageUid) || !is_string($identifier) || $identifier === '' || !is_string($summary)) {
                continue;
            }

            $points[] = new OpenPoint($pageUid, $languageUid, $this->slug($identifier), $summary);
        }

        return $points;
    }

    /** The part of a skill identifier after `<source uid>:`, or the identifier itself. */
    private function slug(string $identifier): string
    {
        return preg_match('/^\d+:(.+)$/', $identifier, $match) === 1 ? $match[1] : $identifier;
    }
}
