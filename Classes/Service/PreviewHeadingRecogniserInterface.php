<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Whether a preview line is a heading that names the change ("Seite
 * löschen", editorial rule 16).
 *
 * The approval card takes a preview's first line as its heading and button
 * only when this says so. A first line that is a summary or a refusal ("Page
 * not found or not permitted.") must never end up on the approve button, and
 * nothing about the line itself says which kind it is.
 */
interface PreviewHeadingRecogniserInterface
{
    /**
     * @param LanguageService $language the language the preview lines are written in: the run's acting user's
     */
    public function isHeading(string $line, LanguageService $language): bool;
}
