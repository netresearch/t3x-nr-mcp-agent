<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Document\Extractor;

use Netresearch\NrMcpAgent\Document\Extractor\PlainTextExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PlainTextExtractorTest extends TestCase
{
    private PlainTextExtractor $subject;

    protected function setUp(): void
    {
        $this->subject = new PlainTextExtractor();
    }

    #[Test]
    public function supportsTxtMimeType(): void
    {
        self::assertContains('text/plain', $this->subject->getSupportedMimeTypes());
    }

    #[Test]
    public function isAlwaysAvailable(): void
    {
        self::assertTrue($this->subject->isAvailable());
    }

    #[Test]
    public function validateThrowsForUnreadablePath(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1743000010);
        $this->expectExceptionMessageMatches('/^File is not readable:.*nonexistent/');
        $this->subject->validate('/nonexistent/path/file.txt');
    }

    #[Test]
    public function validatePassesForReadableFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nr_test_');
        file_put_contents($path, 'Hello TXT');
        try {
            $this->subject->validate($path); // must not throw
            $this->addToAssertionCount(1);
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function extractReturnsFileContents(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nr_test_');
        file_put_contents($path, 'Hello TXT');
        try {
            self::assertSame('Hello TXT', $this->subject->extract($path));
        } finally {
            unlink($path);
        }
    }
}
