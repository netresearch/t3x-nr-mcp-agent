<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

/**
 * An improvement a guided tour found on a page and left open.
 *
 * `summary` is what the editor reads: one sentence. The chat's store builds it
 * from schema labels and the page title, and a page title is editor input, so
 * it is untrusted text and is only ever rendered escaped.
 */
final readonly class OpenPoint
{
    public function __construct(
        public int $pageUid,
        public int $languageUid,
        public string $skill,
        public string $summary,
    ) {}
}
