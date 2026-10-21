<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

/**
 * Pages to suggest when a guided tour starts without one: for the dashboard's
 * "Improve a page" widget and for the chat's own page choice.
 *
 * Deterministic — read from stored records, never from a model — so the same
 * data gives the same suggestions. Bound to
 * DeterministicPageSuggestionProvider in Configuration/Services.yaml.
 *
 * Contract:
 * - `$skill` is the tour's skill, as a slug (`seo-optimieren`) or as a
 *   catalogue identifier (`3:seo-optimieren`); the provider looks only at the
 *   slug, the part after `<source uid>:`.
 * - At most MAX_SUGGESTIONS (5) suggestions, fewer when `$limit` is lower,
 *   best first, each page and language at most once.
 * - Every suggestion is a page the current backend user may improve in the
 *   suggested language: content-edit permission, not locked for editing,
 *   a language they may edit and the page's site has.
 * - A tour the provider has no checks for gets its open points only.
 */
interface PageSuggestionProviderInterface
{
    public const MAX_SUGGESTIONS = 5;

    /**
     * @return list<PageSuggestion>
     */
    public function suggest(string $skill, int $limit = self::MAX_SUGGESTIONS): array;
}
