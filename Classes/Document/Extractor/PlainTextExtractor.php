<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Document\Extractor;

use Netresearch\NrMcpAgent\Document\DocumentExtractorInterface;
use Netresearch\NrMcpAgent\Exception\DocumentExtractionException;

final class PlainTextExtractor implements DocumentExtractorInterface
{
    public function getSupportedMimeTypes(): array
    {
        return ['text/plain'];
    }

    public function getSupportedFileExtensions(): array
    {
        return ['txt'];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function validate(string $path): void
    {
        if (!is_readable($path)) {
            throw new DocumentExtractionException('File is not readable: ' . $path, 1743000010);
        }
    }

    public function extract(string $path): string
    {
        // Checked first so that file_get_contents() raises no warning for a
        // missing, unreadable or non-regular path.
        if (!is_file($path) || !is_readable($path)) {
            throw new DocumentExtractionException('Failed to read file: ' . $path, 1743000011);
        }

        // The path can still vanish between the check and the read. The
        // handler keeps that warning from reaching a warning-to-exception
        // handler, so the caller gets the exception below.
        set_error_handler(static fn (): bool => true);
        try {
            $content = file_get_contents($path);
        } finally {
            restore_error_handler();
        }

        if ($content === false) {
            throw new DocumentExtractionException('Failed to read file: ' . $path, 1743000011);
        }

        return $content;
    }
}
