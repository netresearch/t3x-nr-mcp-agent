<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

/**
 * A page suggested for a guided tour, and why, in words for the editor.
 *
 * `pageUid` is the page in the default language, `languageUid` the version
 * the tour would work on. `title` is the page title as stored and `reason`
 * one short phrase from the extension's labels ("Meta Description fehlt");
 * both are text, never markup.
 */
final readonly class PageSuggestion
{
    public function __construct(
        public int $pageUid,
        public int $languageUid,
        public string $title,
        public string $reason,
    ) {}
}
