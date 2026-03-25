<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Document;

use Netresearch\NrMcpAgent\Document\DocumentExtractorInterface;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DocumentExtractorRegistryTest extends TestCase
{
    private function makeExtractor(array $mimes, bool $available, string $text = 'extracted'): DocumentExtractorInterface
    {
        $mock = $this->createMock(DocumentExtractorInterface::class);
        $mock->method('getSupportedMimeTypes')->willReturn($mimes);
        $mock->method('isAvailable')->willReturn($available);
        $mock->method('extract')->willReturn($text);
        return $mock;
    }

    #[Test]
    public function getAvailableMimeTypesExcludesUnavailableExtractors(): void
    {
        $registry = new DocumentExtractorRegistry([
            $this->makeExtractor(['application/pdf'], true),
            $this->makeExtractor(['application/vnd.ms-excel'], false),
        ]);

        self::assertContains('application/pdf', $registry->getAvailableMimeTypes());
        self::assertNotContains('application/vnd.ms-excel', $registry->getAvailableMimeTypes());
    }

    #[Test]
    public function canExtractReturnsTrueForAvailableMime(): void
    {
        $registry = new DocumentExtractorRegistry([
            $this->makeExtractor(['text/plain'], true),
        ]);

        self::assertTrue($registry->canExtract('text/plain'));
    }

    #[Test]
    public function canExtractReturnsFalseForUnavailableMime(): void
    {
        $registry = new DocumentExtractorRegistry([
            $this->makeExtractor(['application/pdf'], false),
        ]);

        self::assertFalse($registry->canExtract('application/pdf'));
    }

    #[Test]
    public function extractDelegatesToMatchingExtractor(): void
    {
        $registry = new DocumentExtractorRegistry([
            $this->makeExtractor(['text/plain'], true, 'hello'),
        ]);

        self::assertSame('hello', $registry->extract('/some/path.txt', 'text/plain'));
    }

    #[Test]
    public function extractThrowsForUnknownMime(): void
    {
        $registry = new DocumentExtractorRegistry([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1743000020);
        $this->expectExceptionMessageMatches('/application\/unknown/');
        $registry->extract('/path', 'application/unknown');
    }

    #[Test]
    public function validateDelegatesToMatchingExtractor(): void
    {
        $extractor = $this->createMock(DocumentExtractorInterface::class);
        $extractor->method('getSupportedMimeTypes')->willReturn(['text/plain']);
        $extractor->method('isAvailable')->willReturn(true);
        $extractor->expects(self::once())->method('validate')->with('/path.txt');

        $registry = new DocumentExtractorRegistry([$extractor]);
        $registry->validate('/path.txt', 'text/plain');
    }

    #[Test]
    public function validateThrowsForUnknownMime(): void
    {
        $registry = new DocumentExtractorRegistry([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1743000020);
        $this->expectExceptionMessageMatches('/application\/unknown/');
        $registry->validate('/path', 'application/unknown');
    }

    #[Test]
    public function getAvailableMimeTypesDeduplicatesAndReturnsSequentialList(): void
    {
        // Two extractors both claim 'text/plain' — result must be deduplicated
        // and sequentially indexed (array_values after array_unique).
        $registry = new DocumentExtractorRegistry([
            $this->makeExtractor(['text/plain', 'application/pdf'], true),
            $this->makeExtractor(['text/plain'], true),
        ]);

        $mimes = $registry->getAvailableMimeTypes();

        self::assertSame(1, count(array_filter($mimes, fn($m) => $m === 'text/plain')));
        self::assertSame(range(0, count($mimes) - 1), array_keys($mimes));
    }
}
