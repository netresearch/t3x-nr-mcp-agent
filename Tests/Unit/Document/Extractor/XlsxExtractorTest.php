<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Document\Extractor;

use Netresearch\NrMcpAgent\Document\Extractor\XlsxExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class XlsxExtractorTest extends TestCase
{
    private XlsxExtractor $subject;
    private string $fixtures;

    protected function setUp(): void
    {
        $this->subject = new XlsxExtractor();
        $this->fixtures = __DIR__ . '/../../../Fixtures/Documents';
    }

    #[Test]
    public function supportsXlsxMimeType(): void
    {
        self::assertContains(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $this->subject->getSupportedMimeTypes(),
        );
    }

    #[Test]
    public function isAvailableReflectsWhetherLibraryIsInstalled(): void
    {
        $expected = class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class);
        self::assertSame($expected, $this->subject->isAvailable());
    }

    #[Test]
    public function validateThrowsForCorruptFile(): void
    {
        if (!$this->subject->isAvailable()) {
            self::markTestSkipped('phpoffice/phpspreadsheet not installed');
        }
        $this->expectException(RuntimeException::class);
        $this->subject->validate($this->fixtures . '/corrupt.xlsx');
    }

    #[Test]
    public function extractReturnsTextFromXlsx(): void
    {
        if (!$this->subject->isAvailable()) {
            self::markTestSkipped('phpoffice/phpspreadsheet not installed');
        }
        if (!file_exists($this->fixtures . '/sample.xlsx')) {
            self::markTestSkipped('sample.xlsx fixture not generated (run generate.php with phpspreadsheet installed)');
        }
        $text = $this->subject->extract($this->fixtures . '/sample.xlsx');
        self::assertStringContainsString('Hello XLSX', $text);
    }
}
