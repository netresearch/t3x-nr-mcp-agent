<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Document\Extractor;

use Netresearch\NrMcpAgent\Document\DocumentExtractorInterface;
use RuntimeException;

final class PlainTextExtractor implements DocumentExtractorInterface
{
    public function getSupportedMimeTypes(): array
    {
        return ['text/plain'];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function validate(string $path): void
    {
        if (!is_readable($path)) {
            throw new RuntimeException('File is not readable: ' . $path, 1743000010);
        }
    }

    public function extract(string $path): string
    {
        $content = file_get_contents($path);
        return $content !== false ? $content : '';
    }
}
