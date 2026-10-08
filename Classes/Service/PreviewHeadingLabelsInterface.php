<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

/**
 * The labels a preview's first line uses when it names the change
 * ("Seite löschen", editorial rule 16).
 *
 * The approval card takes a preview's first line as its heading and button
 * only when that line is positively one of these. A first line that is a
 * summary or a refusal ("Page not found or not permitted.") must never end
 * up on the approve button, and nothing about the line itself says which
 * kind it is.
 */
interface PreviewHeadingLabelsInterface
{
    /**
     * @return list<string> `LLL:` references of the heading labels; empty when the source offers none
     */
    public function labelReferences(): array;
}
