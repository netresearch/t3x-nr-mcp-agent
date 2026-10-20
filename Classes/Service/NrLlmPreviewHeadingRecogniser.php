<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Service\Tool\ApprovalPreviewHeadings;
use Throwable;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * The nr-llm side of {@see PreviewHeadingRecogniserInterface}: nr-llm's
 * `ApprovalPreviewHeadings::isHeading()` (@api, added with
 * netresearch/t3x-nr-llm#1016).
 *
 * The provider is a constructor argument so a test can stand in for nr-llm.
 * Any failure answers "no heading": a heading is presentation, and the
 * decision does not depend on it.
 */
final readonly class NrLlmPreviewHeadingRecogniser implements PreviewHeadingRecogniserInterface
{
    /**
     * @param class-string $provider a class with nr-llm's static isHeading(string, LanguageService): bool
     */
    public function __construct(
        private string $provider = ApprovalPreviewHeadings::class,
    ) {}

    public function isHeading(string $line, LanguageService $language): bool
    {
        $isHeading = [$this->provider, 'isHeading'];
        if (!is_callable($isHeading)) {
            return false;
        }

        try {
            return $isHeading($line, $language) === true;
        } catch (Throwable) {
            return false;
        }
    }
}
