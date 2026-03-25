<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Document;

use RuntimeException;

interface DocumentExtractorInterface
{
    /** @return list<string> */
    public function getSupportedMimeTypes(): array;

    /**
     * Returns false when a required optional library is not installed.
     * PlainTextExtractor always returns true.
     */
    public function isAvailable(): bool;

    /**
     * Lightweight open/header check. Does NOT extract full text.
     *
     * @throws RuntimeException if the file is corrupt, unreadable, or encrypted.
     */
    public function validate(string $path): void;

    /**
     * Full text extraction. Returns empty string when file contains no text.
     *
     * @throws RuntimeException on extraction failure (e.g., I/O error).
     */
    public function extract(string $path): string;
}
