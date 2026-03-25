<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Document;

use RuntimeException;

final class DocumentExtractorRegistry
{
    /** @var list<DocumentExtractorInterface> */
    private readonly array $extractors;

    /** @param iterable<DocumentExtractorInterface> $extractors */
    public function __construct(iterable $extractors)
    {
        $this->extractors = array_values([...$extractors]);
    }

    /** @return list<string> */
    public function getAvailableMimeTypes(): array
    {
        $types = [];
        foreach ($this->extractors as $extractor) {
            if ($extractor->isAvailable()) {
                foreach ($extractor->getSupportedMimeTypes() as $mime) {
                    $types[] = $mime;
                }
            }
        }
        return array_values(array_unique($types));
    }

    /** @return list<string> File extensions without dot, for UI accept filter */
    public function getAvailableExtensions(): array
    {
        $extensions = [];
        foreach ($this->extractors as $extractor) {
            if ($extractor->isAvailable()) {
                foreach ($extractor->getSupportedFileExtensions() as $ext) {
                    $extensions[] = $ext;
                }
            }
        }
        return array_values(array_unique($extensions));
    }

    public function canExtract(string $mimeType): bool
    {
        return $this->findExtractor($mimeType) !== null;
    }

    public function validate(string $path, string $mimeType): void
    {
        $this->requireExtractor($mimeType)->validate($path);
    }

    public function extract(string $path, string $mimeType): string
    {
        return $this->requireExtractor($mimeType)->extract($path);
    }

    private function findExtractor(string $mimeType): ?DocumentExtractorInterface
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->isAvailable() && in_array($mimeType, $extractor->getSupportedMimeTypes(), true)) {
                return $extractor;
            }
        }
        return null;
    }

    private function requireExtractor(string $mimeType): DocumentExtractorInterface
    {
        $extractor = $this->findExtractor($mimeType);
        if ($extractor === null) {
            throw new RuntimeException('No extractor available for MIME type: ' . $mimeType, 1743000020);
        }
        return $extractor;
    }
}
