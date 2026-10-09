<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\OpenPoint;

/**
 * One open point as the current backend user may see it, site-wide
 * ({@see OpenPointVisibility::visibleForCurrentUser()}, ADR-022): for the
 * dashboard's recommendations and the page suggestions' ranking.
 *
 * Identities and schema labels only. `$summary` is one German sentence built
 * from the TCA labels and the page title, never model text.
 */
final readonly class VisibleOpenPoint
{
    public function __construct(
        /** The subject page the process ran on. */
        public int $pageUid,
        /** The target record's language uid; 0 for a table without a language field. */
        public int $languageUid,
        public int $skillUid,
        /** nr-llm's stored identifier of the skill (`<source uid>:<path>` for a synced one); '' when the skill is gone. */
        public string $skillIdentifier,
        public string $targetTable,
        public int $targetUid,
        /** The field; '' for a write that names the record as a whole. */
        public string $field,
        public int $crdate,
        public string $summary,
    ) {}
}
