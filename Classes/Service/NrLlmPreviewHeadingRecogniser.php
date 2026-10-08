<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Throwable;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * The nr-llm side of {@see PreviewHeadingRecogniserInterface}: nr-llm's
 * `ApprovalPreviewHeadings::isHeading()` (@api, added with
 * netresearch/t3x-nr-llm#1016).
 *
 * Referenced by name and guarded with class_exists/method_exists, because the
 * nr-llm versions this extension supports do not all have it. Without it no
 * line is a heading, and the card names the change by the editor action label
 * or its generic wording. Any failure answers "no heading" as well: a
 * heading is presentation, and the decision does not depend on it.
 */
final readonly class NrLlmPreviewHeadingRecogniser implements PreviewHeadingRecogniserInterface
{
    public const PROVIDER = 'Netresearch\\NrLlm\\Service\\Tool\\ApprovalPreviewHeadings';

    public function __construct(
        private string $provider = self::PROVIDER,
    ) {}

    public function isHeading(string $line, LanguageService $language): bool
    {
        $isHeading = [$this->provider, 'isHeading'];
        if (!class_exists($this->provider) || !is_callable($isHeading)) {
            return false;
        }

        try {
            return $isHeading($line, $language) === true;
        } catch (Throwable) {
            return false;
        }
    }
}
