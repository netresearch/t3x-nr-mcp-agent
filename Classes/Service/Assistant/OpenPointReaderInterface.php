<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

/**
 * Where the "Recommended for your website" dashboard widget reads the open
 * improvement points from.
 *
 * Bound to NullOpenPointReader in Configuration/Services.yaml until the store
 * of open points exists; the store's reader replaces that alias.
 *
 * Contract for an implementation:
 * - return open (not yet resolved) points only, newest first, at most $limit;
 * - `pageUid` is the uid of the page in the default language, `languageUid`
 *   the language the point is about, `skill` the identifier of the skill that
 *   resolves it, `summary` one sentence for the editor;
 * - restricting to pages the current backend user may access is welcome but
 *   not relied on: the widget checks every point's page itself and drops
 *   points whose skill has no label, so it may show fewer than $limit.
 */
interface OpenPointReaderInterface
{
    /**
     * @return list<OpenPoint>
     */
    public function findOpen(int $limit): array;
}
