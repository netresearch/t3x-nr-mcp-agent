# Document Text Extraction Fallback — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When an LLM provider does not natively support PDF/DOCX/XLSX/TXT, extract text server-side and inject it as a plain-text block into the prompt.

**Architecture:** A `DocumentExtractorRegistry` collects tagged `DocumentExtractorInterface` implementations via Symfony DI. `ChatService::getProviderCapabilities()` merges extraction formats unconditionally. `ChatService::buildFileContentBlock()` delegates to the registry when the provider cannot handle the format natively. The upload controller validates extraction-backed files at upload time and uses a dynamic allowlist.

**Tech Stack:** PHP 8.2+, TYPO3 13.4/14.x, smalot/pdfparser ^2.0, phpoffice/phpword ^1.0, phpoffice/phpspreadsheet ^3.0 (optional), PHPUnit 10/11, Infection mutation testing

**Spec:** `docs/superpowers/specs/2026-03-25-document-extraction-design.md`

---

## File Map

| Action | File |
|--------|------|
| Create | `Classes/Document/DocumentExtractorInterface.php` |
| Create | `Classes/Document/DocumentExtractorRegistry.php` |
| Create | `Classes/Document/Extractor/PlainTextExtractor.php` |
| Create | `Classes/Document/Extractor/PdfExtractor.php` |
| Create | `Classes/Document/Extractor/DocxExtractor.php` |
| Create | `Classes/Document/Extractor/XlsxExtractor.php` |
| Create | `Tests/Unit/Document/DocumentExtractorRegistryTest.php` |
| Create | `Tests/Unit/Document/Extractor/PlainTextExtractorTest.php` |
| Create | `Tests/Unit/Document/Extractor/PdfExtractorTest.php` |
| Create | `Tests/Unit/Document/Extractor/DocxExtractorTest.php` |
| Create | `Tests/Unit/Document/Extractor/XlsxExtractorTest.php` |
| Create | `Tests/Fixtures/Documents/generate.php` |
| Create | `Tests/Fixtures/Documents/sample.txt` |
| Create (binary) | `Tests/Fixtures/Documents/sample.pdf` |
| Create (binary) | `Tests/Fixtures/Documents/sample.docx` |
| Create (binary) | `Tests/Fixtures/Documents/sample.xlsx` |
| Create (binary) | `Tests/Fixtures/Documents/corrupt.pdf` |
| Create (binary) | `Tests/Fixtures/Documents/corrupt.docx` |
| Create (binary) | `Tests/Fixtures/Documents/corrupt.xlsx` |
| Create (binary) | `Tests/Fixtures/Documents/encrypted.pdf` |
| Modify | `composer.json` (add smalot/pdfparser, phpoffice/phpword, suggest phpspreadsheet) |
| Modify | `Configuration/Services.yaml` (register extractors with tag) |
| Modify | `Classes/Service/ChatService.php` (inject registry, update 2 methods) |
| Modify | `Classes/Controller/ChatApiController.php` (inject registry, dynamic allowlist, validate) |
| Modify | `Tests/Unit/Service/ChatServiceCapabilitiesTest.php` (new constructor param + 3 new tests) |
| Modify | `Tests/Unit/Service/ChatWorkerCommandExecuteTest.php` (new constructor param in helper) |
| Modify | `Tests/Unit/Controller/ChatApiControllerTest.php` (new constructor param + update 400→422 + 3 new tests) |
| Create | `Tests/Unit/Architecture/DocumentExtractorArchitectureTest.php` |

---

## Task 1: DocumentExtractorInterface

**Files:**
- Create: `Classes/Document/DocumentExtractorInterface.php`

- [ ] **Step 1: Create the interface**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Document;

interface DocumentExtractorInterface
{
    /** @return list<string> */
    public function getSupportedMimeTypes(): array;

    public function isAvailable(): bool;

    /**
     * Lightweight open/header check. Does NOT extract full text.
     *
     * @throws \RuntimeException if the file is corrupt, unreadable, or encrypted.
     */
    public function validate(string $path): void;

    /**
     * Full text extraction. Returns empty string when file contains no text.
     */
    public function extract(string $path): string;
}
```

- [ ] **Step 2: Commit**

```bash
git add Classes/Document/DocumentExtractorInterface.php
git commit -m "feat: add DocumentExtractorInterface"
```

---

## Task 2: PlainTextExtractor (TDD)

**Files:**
- Create: `Classes/Document/Extractor/PlainTextExtractor.php`
- Create: `Tests/Unit/Document/Extractor/PlainTextExtractorTest.php`

- [ ] **Step 1: Write failing tests**

```php
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
```

- [ ] **Step 2: Run tests — verify they FAIL**

```bash
.Build/bin/phpunit -c Build/phpunit.xml Tests/Unit/Document/Extractor/PlainTextExtractorTest.php
```

Expected: Class not found / FAIL

- [ ] **Step 3: Implement PlainTextExtractor**

```php
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
```

- [ ] **Step 4: Run tests — verify they PASS**

```bash
.Build/bin/phpunit -c Build/phpunit.xml Tests/Unit/Document/Extractor/PlainTextExtractorTest.php
```

Expected: 5 tests, 5 assertions, OK

- [ ] **Step 5: Commit**

```bash
git add Classes/Document/Extractor/PlainTextExtractor.php Tests/Unit/Document/Extractor/PlainTextExtractorTest.php
git commit -m "feat: add PlainTextExtractor with unit tests"
```

---

## Task 3: DocumentExtractorRegistry (TDD)

The registry can be tested immediately using mocked extractors — no libraries needed.

**Files:**
- Create: `Classes/Document/DocumentExtractorRegistry.php`
- Create: `Tests/Unit/Document/DocumentExtractorRegistryTest.php`

- [ ] **Step 1: Write failing tests**

```php
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
        $registry->validate('/path', 'application/unknown');
    }
}
```

- [ ] **Step 2: Run tests — verify they FAIL**

```bash
.Build/bin/phpunit -c Build/phpunit.xml Tests/Unit/Document/DocumentExtractorRegistryTest.php
```

- [ ] **Step 3: Implement DocumentExtractorRegistry**

```php
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
        $this->extractors = [...$extractors];
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
```

- [ ] **Step 4: Run tests — verify they PASS**

```bash
.Build/bin/phpunit -c Build/phpunit.xml Tests/Unit/Document/DocumentExtractorRegistryTest.php
```

- [ ] **Step 5: Commit**

```bash
git add Classes/Document/DocumentExtractorRegistry.php Tests/Unit/Document/DocumentExtractorRegistryTest.php
git commit -m "feat: add DocumentExtractorRegistry with unit tests"
```

---

## Task 4: Add Dependencies and Generate Fixtures

**Files:**
- Modify: `composer.json`
- Modify: `Configuration/Services.yaml`
- Create: `Tests/Fixtures/Documents/generate.php` (run once, then commit generated files)

- [ ] **Step 1: Add dependencies to composer.json**

In the `"require"` block, add after the existing entries:

```json
"phpoffice/phpword": "^1.0",
"smalot/pdfparser": "^2.0"
```

In the `"suggest"` block, add:

```json
"phpoffice/phpspreadsheet": "^3.0 — Required for XLSX upload support"
```

- [ ] **Step 2: Register extractors in Configuration/Services.yaml**

Add to the end of `Configuration/Services.yaml`:

```yaml
  # Document extractors — tagged for DocumentExtractorRegistry
  Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry:
    arguments:
      - !tagged_iterator document.extractor

  Netresearch\NrMcpAgent\Document\Extractor\PdfExtractor:
    tags: ['document.extractor']

  Netresearch\NrMcpAgent\Document\Extractor\DocxExtractor:
    tags: ['document.extractor']

  Netresearch\NrMcpAgent\Document\Extractor\XlsxExtractor:
    tags: ['document.extractor']

  Netresearch\NrMcpAgent\Document\Extractor\PlainTextExtractor:
    tags: ['document.extractor']
```

- [ ] **Step 3: Run composer install**

```bash
composer require phpoffice/phpword:^1.0 smalot/pdfparser:^2.0 --working-dir=/srv/projects/nr-mcp-agent
```

- [ ] **Step 4: Create fixture generation script**

Create `Tests/Fixtures/Documents/generate.php`:

```php
<?php

/**
 * Run once to generate binary test fixtures.
 * Usage: php Tests/Fixtures/Documents/generate.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../.Build/vendor/autoload.php';

$dir = __DIR__;

// sample.txt
file_put_contents($dir . '/sample.txt', 'Hello TXT');

// corrupt files — invalid bytes
file_put_contents($dir . '/corrupt.pdf', str_repeat("\x00\xFF\xAB\x12", 5));
file_put_contents($dir . '/corrupt.docx', str_repeat("\x00\xFF\xAB\x12", 5));
file_put_contents($dir . '/corrupt.xlsx', str_repeat("\x00\xFF\xAB\x12", 5));

// sample.pdf — minimal valid PDF with "Hello PDF" text
$pdfContent = '%PDF-1.4' . PHP_EOL
    . '1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj' . PHP_EOL
    . '2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj' . PHP_EOL
    . '3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj' . PHP_EOL
    . '4 0 obj<</Length 44>>' . PHP_EOL
    . 'stream' . PHP_EOL
    . 'BT /F1 12 Tf 100 700 Td (Hello PDF) Tj ET' . PHP_EOL
    . 'endstream' . PHP_EOL
    . 'endobj' . PHP_EOL
    . '5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj' . PHP_EOL
    . 'xref' . PHP_EOL
    . '0 6' . PHP_EOL
    . '0000000000 65535 f ' . PHP_EOL
    . '0000000009 00000 n ' . PHP_EOL
    . '0000000058 00000 n ' . PHP_EOL
    . '0000000115 00000 n ' . PHP_EOL
    . '0000000266 00000 n ' . PHP_EOL
    . '0000000360 00000 n ' . PHP_EOL
    . 'trailer<</Size 6/Root 1 0 R>>' . PHP_EOL
    . 'startxref' . PHP_EOL
    . '441' . PHP_EOL
    . '%%EOF';
file_put_contents($dir . '/sample.pdf', $pdfContent);

// encrypted.pdf — a real password-protected PDF (owner=test, user=test)
// This is a minimal PDF with standard encryption (RC4-40bit) for testing purposes.
// Generated with: openssl / qpdf --encrypt test test 40 -- minimal.pdf encrypted.pdf
$encryptedPdfBase64 = 'JVBERi0xLjQKMSAwIG9iajw8L1R5cGUvQ2F0YWxvZy9QYWdlcyAyIDAgUj4+ZW5kb2JqCjIgMCBv'
    . 'Yjw8L1R5cGUvUGFnZXMvS2lkc1szIDAgUl0vQ291bnQgMT4+ZW5kb2JqCjMgMCBvYmo8PC9UeXBl'
    . 'L1BhZ2UvTWVkaWFCb3hbMCAwIDMgM10+PmVuZG9iagp4cmVmCjAgNAowMDAwMDAwMDAwIDY1NTM1'
    . 'IGYgCjAwMDAwMDAwMDkgMDAwMDAgbiAKMDAwMDAwMDA1OCAwMDAwMCBuIAowMDAwMDAwMTE1IDAw'
    . 'MDAwIG4gCnRyYWlsZXI8PC9TaXplIDQvUm9vdCAxIDAgUi9FbmNyeXB0IDw8L0ZpbHRlci9TdGFu'
    . 'ZGFyZC9WIDEvUiAyL08gKDxCQTc2MTJBQTlGNEIxNTM2NTA3MzI1MEVDNDM0RUNDNz4pL1UgKDxC'
    . 'QTc2MTJBQTlGNEIxNTM2NTA3MzI1MEVDNDM0RUNDNz4pL1AgLTQ+Pj4+CnN0YXJ0eHJlZgoxNjAK'
    . 'JSVFT0Y=';
file_put_contents($dir . '/encrypted.pdf', base64_decode($encryptedPdfBase64));

// sample.docx — create with phpoffice/phpword
$phpWord = new \PhpOffice\PhpWord\PhpWord();
$section = $phpWord->addSection();
$section->addText('Hello DOCX');
$writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
$writer->save($dir . '/sample.docx');

// sample.xlsx — create with phpoffice/phpspreadsheet (only if installed)
if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $spreadsheet->getActiveSheet()->setCellValue('A1', 'Hello XLSX');
    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save($dir . '/sample.xlsx');
    echo "sample.xlsx generated\n";
} else {
    echo "Skipping sample.xlsx (phpoffice/phpspreadsheet not installed)\n";
}

echo "Fixtures generated in: $dir\n";
```

- [ ] **Step 5: Run the fixture generation script**

```bash
php /srv/projects/nr-mcp-agent/Tests/Fixtures/Documents/generate.php
```

Expected output:
```
sample.xlsx generated   (or skip message if phpspreadsheet not installed)
Fixtures generated in: .../Tests/Fixtures/Documents
```

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock Configuration/Services.yaml Tests/Fixtures/Documents/
git commit -m "feat: add document extractor dependencies and fixtures"
```

---

## Task 5: PdfExtractor (TDD)

**Files:**
- Create: `Classes/Document/Extractor/PdfExtractor.php`
- Create: `Tests/Unit/Document/Extractor/PdfExtractorTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Document\Extractor;

use Netresearch\NrMcpAgent\Document\Extractor\PdfExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PdfExtractorTest extends TestCase
{
    private PdfExtractor $subject;
    private string $fixtures;

    protected function setUp(): void
    {
        $this->subject = new PdfExtractor();
        $this->fixtures = __DIR__ . '/../../../Fixtures/Documents';
    }

    #[Test]
    public function supportsPdfMimeType(): void
    {
        self::assertContains('application/pdf', $this->subject->getSupportedMimeTypes());
    }

    #[Test]
    public function isAvailableWhenLibraryInstalled(): void
    {
        // smalot/pdfparser is a hard dep — always true
        self::assertTrue($this->subject->isAvailable());
    }

    #[Test]
    public function validateThrowsForCorruptFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->subject->validate($this->fixtures . '/corrupt.pdf');
    }

    #[Test]
    public function validateThrowsForEncryptedFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->subject->validate($this->fixtures . '/encrypted.pdf');
    }

    #[Test]
    public function extractReturnsTextFromPdf(): void
    {
        $text = $this->subject->extract($this->fixtures . '/sample.pdf');
        self::assertStringContainsString('Hello PDF', $text);
    }
}
```

- [ ] **Step 2: Run tests — verify they FAIL**

```bash
.Build/bin/phpunit -c Build/phpunit.xml Tests/Unit/Document/Extractor/PdfExtractorTest.php
```

- [ ] **Step 3: Implement PdfExtractor**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Document\Extractor;

use Netresearch\NrMcpAgent\Document\DocumentExtractorInterface;
use RuntimeException;
use Smalot\PdfParser\Parser;

final class PdfExtractor implements DocumentExtractorInterface
{
    public function getSupportedMimeTypes(): array
    {
        return ['application/pdf'];
    }

    public function isAvailable(): bool
    {
        return class_exists(Parser::class);
    }

    public function validate(string $path): void
    {
        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($path);
            $details = $pdf->getDetails();
            if (isset($details['Encrypt'])) {
                throw new RuntimeException('PDF is encrypted and cannot be processed', 1743000031);
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException('PDF validation failed: ' . $e->getMessage(), 1743000030, $e);
        }
    }

    public function extract(string $path): string
    {
        try {
            $parser = new Parser();
            return $parser->parseFile($path)->getText();
        } catch (\Throwable $e) {
            throw new RuntimeException('PDF extraction failed: ' . $e->getMessage(), 1743000032, $e);
        }
    }
}
```

- [ ] **Step 4: Run tests — verify they PASS**

```bash
.Build/bin/phpunit -c Build/phpunit.xml Tests/Unit/Document/Extractor/PdfExtractorTest.php
```

> **Note:** If `validateThrowsForEncryptedFile` fails because the sample encrypted.pdf is not recognised as encrypted by smalot, regenerate `encrypted.pdf` using `qpdf --encrypt test test 40 -- sample.pdf encrypted.pdf` (requires `qpdf` installed) and update `generate.php` to document this. The test covers the behaviour, not the fixture generation method.

- [ ] **Step 5: Commit**

```bash
git add Classes/Document/Extractor/PdfExtractor.php Tests/Unit/Document/Extractor/PdfExtractorTest.php
git commit -m "feat: add PdfExtractor with unit tests"
```

---

## Task 6: DocxExtractor (TDD)

**Files:**
- Create: `Classes/Document/Extractor/DocxExtractor.php`
- Create: `Tests/Unit/Document/Extractor/DocxExtractorTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Document\Extractor;

use Netresearch\NrMcpAgent\Document\Extractor\DocxExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DocxExtractorTest extends TestCase
{
    private DocxExtractor $subject;
    private string $fixtures;

    protected function setUp(): void
    {
        $this->subject = new DocxExtractor();
        $this->fixtures = __DIR__ . '/../../../Fixtures/Documents';
    }

    #[Test]
    public function supportsDocxMimeType(): void
    {
        self::assertContains(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $this->subject->getSupportedMimeTypes()
        );
    }

    #[Test]
    public function isAvailableWhenLibraryInstalled(): void
    {
        // phpoffice/phpword is a hard dep — always true
        self::assertTrue($this->subject->isAvailable());
    }

    #[Test]
    public function validateThrowsForCorruptFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->subject->validate($this->fixtures . '/corrupt.docx');
    }

    #[Test]
    public function extractReturnsTextFromDocx(): void
    {
        $text = $this->subject->extract($this->fixtures . '/sample.docx');
        self::assertStringContainsString('Hello DOCX', $text);
    }
}
```

- [ ] **Step 2: Run tests — verify they FAIL**

```bash
.Build/bin/phpunit -c Build/phpunit.xml Tests/Unit/Document/Extractor/DocxExtractorTest.php
```

- [ ] **Step 3: Implement DocxExtractor**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Document\Extractor;

use Netresearch\NrMcpAgent\Document\DocumentExtractorInterface;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;

final class DocxExtractor implements DocumentExtractorInterface
{
    public function getSupportedMimeTypes(): array
    {
        return ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    }

    public function isAvailable(): bool
    {
        return class_exists(PhpWord::class);
    }

    public function validate(string $path): void
    {
        try {
            IOFactory::load($path);
        } catch (\Throwable $e) {
            throw new RuntimeException('DOCX validation failed: ' . $e->getMessage(), 1743000040, $e);
        }
    }

    public function extract(string $path): string
    {
        try {
            $phpWord = IOFactory::load($path);
            return $this->extractText($phpWord);
        } catch (\Throwable $e) {
            throw new RuntimeException('DOCX extraction failed: ' . $e->getMessage(), 1743000041, $e);
        }
    }

    private function extractText(PhpWord $phpWord): string
    {
        $parts = [];
        foreach ($phpWord->getSections() as $section) {
            $parts[] = $this->extractFromSection($section);
        }
        return trim(implode("\n", array_filter($parts)));
    }

    private function extractFromSection(Section $section): string
    {
        $parts = [];
        foreach ($section->getElements() as $element) {
            $text = $this->extractFromElement($element);
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        return implode("\n", $parts);
    }

    private function extractFromElement(AbstractElement $element): string
    {
        if ($element instanceof Text) {
            return $element->getText();
        }
        if ($element instanceof TextRun) {
            $parts = [];
            foreach ($element->getElements() as $child) {
                $text = $this->extractFromElement($child);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
            return implode('', $parts);
        }
        return '';
    }
}
```

- [ ] **Step 4: Run tests — verify they PASS**

```bash
.Build/bin/phpunit -c Build/phpunit.xml Tests/Unit/Document/Extractor/DocxExtractorTest.php
```

- [ ] **Step 5: Commit**

```bash
git add Classes/Document/Extractor/DocxExtractor.php Tests/Unit/Document/Extractor/DocxExtractorTest.php
git commit -m "feat: add DocxExtractor with unit tests"
```

---

## Task 7: XlsxExtractor (TDD)

**Files:**
- Create: `Classes/Document/Extractor/XlsxExtractor.php`
- Create: `Tests/Unit/Document/Extractor/XlsxExtractorTest.php`

Note: phpoffice/phpspreadsheet is a `composer suggest` dep. `isAvailable()` uses `class_exists`. The tests for `extract()` require phpspreadsheet to be installed.

- [ ] **Step 1: Write failing tests**

```php
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
            $this->subject->getSupportedMimeTypes()
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
```

- [ ] **Step 2: Run tests — verify they FAIL**

```bash
.Build/bin/phpunit -c Build/phpunit.xml Tests/Unit/Document/Extractor/XlsxExtractorTest.php
```

- [ ] **Step 3: Implement XlsxExtractor**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Document\Extractor;

use Netresearch\NrMcpAgent\Document\DocumentExtractorInterface;
use RuntimeException;

final class XlsxExtractor implements DocumentExtractorInterface
{
    public function getSupportedMimeTypes(): array
    {
        return ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    public function isAvailable(): bool
    {
        return class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class);
    }

    public function validate(string $path): void
    {
        try {
            \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        } catch (\Throwable $e) {
            throw new RuntimeException('XLSX validation failed: ' . $e->getMessage(), 1743000050, $e);
        }
    }

    public function extract(string $path): string
    {
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $parts = [];
            foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
                $parts[] = $worksheet->getTitle();
                foreach ($worksheet->getRowIterator() as $row) {
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(true);
                    $cells = [];
                    foreach ($cellIterator as $cell) {
                        $value = $cell->getValue();
                        if ($value !== null && $value !== '') {
                            $cells[] = (string) $value;
                        }
                    }
                    if ($cells !== []) {
                        $parts[] = implode("\t", $cells);
                    }
                }
            }
            return trim(implode("\n", $parts));
        } catch (\Throwable $e) {
            throw new RuntimeException('XLSX extraction failed: ' . $e->getMessage(), 1743000051, $e);
        }
    }
}
```

- [ ] **Step 4: Run tests — verify they PASS**

```bash
.Build/bin/phpunit -c Build/phpunit.xml Tests/Unit/Document/Extractor/XlsxExtractorTest.php
```

- [ ] **Step 5: Commit**

```bash
git add Classes/Document/Extractor/XlsxExtractor.php Tests/Unit/Document/Extractor/XlsxExtractorTest.php
git commit -m "feat: add XlsxExtractor with unit tests"
```

---

## Task 8: Update ChatService — inject registry, update getProviderCapabilities()

`ChatService` gets a new constructor parameter `DocumentExtractorRegistry`. All test helpers that instantiate `ChatService` must be updated.

**Files:**
- Modify: `Classes/Service/ChatService.php`
- Modify: `Tests/Unit/Service/ChatServiceCapabilitiesTest.php`
- Modify: `Tests/Unit/Service/ChatWorkerCommandExecuteTest.php` (createChatService helper)
- Modify: `Tests/Unit/Command/ChatWorkerCommandExecuteTest.php` (createChatService helper)

- [ ] **Step 1: Add new tests to ChatServiceCapabilitiesTest**

Add these three tests at the end of `ChatServiceCapabilitiesTest`. Also update `createChatService()` to accept an optional registry parameter.

In `createChatService()`, add a new parameter and pass it to the constructor:

```php
private function createChatService(
    ProviderInterface $provider,
    ?\Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry $registry = null,
): ChatService {
    // ... existing mock setup ...
    $registry ??= new \Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry([]);

    return new ChatService(
        $repository, $config, $mcpProvider, $llmTaskRepository,
        $adapterRegistry, $this->createMock(ResourceFactory::class),
        $this->createMock(SiteFinder::class), $registry,
    );
}
```

Add these three new tests:

```php
#[Test]
public function extractionFormatsAppearsEvenWithoutVisionSupport(): void
{
    $extractor = $this->createMock(\Netresearch\NrMcpAgent\Document\DocumentExtractorInterface::class);
    $extractor->method('isAvailable')->willReturn(true);
    $extractor->method('getSupportedMimeTypes')->willReturn(['application/pdf']);

    $registry = new \Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry([$extractor]);
    $provider = $this->createMock(ProviderInterface::class); // not VisionCapable

    $service = $this->createChatService($provider, $registry);
    $caps = $service->getProviderCapabilities();

    self::assertContains('application/pdf', $caps['supportedFormats']);
}

#[Test]
public function extractionFormatsMergeWithProviderFormats(): void
{
    $extractor = $this->createMock(\Netresearch\NrMcpAgent\Document\DocumentExtractorInterface::class);
    $extractor->method('isAvailable')->willReturn(true);
    $extractor->method('getSupportedMimeTypes')->willReturn(['application/pdf']);

    $registry = new \Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry([$extractor]);

    $provider = $this->createMockForIntersectionOfInterfaces([ProviderInterface::class, VisionCapableInterface::class]);
    $provider->method('supportsVision')->willReturn(true);
    $provider->method('getMaxImageSize')->willReturn(1024);
    $provider->method('getSupportedImageFormats')->willReturn(['image/jpeg']);

    $service = $this->createChatService($provider, $registry);
    $caps = $service->getProviderCapabilities();

    self::assertContains('image/jpeg', $caps['supportedFormats']);
    self::assertContains('application/pdf', $caps['supportedFormats']);
}

#[Test]
public function mimeInBothProviderAndRegistryAppearsOnce(): void
{
    $extractor = $this->createMock(\Netresearch\NrMcpAgent\Document\DocumentExtractorInterface::class);
    $extractor->method('isAvailable')->willReturn(true);
    $extractor->method('getSupportedMimeTypes')->willReturn(['image/jpeg']); // also in provider

    $registry = new \Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry([$extractor]);

    $provider = $this->createMockForIntersectionOfInterfaces([ProviderInterface::class, VisionCapableInterface::class]);
    $provider->method('supportsVision')->willReturn(true);
    $provider->method('getMaxImageSize')->willReturn(1024);
    $provider->method('getSupportedImageFormats')->willReturn(['image/jpeg']);

    $service = $this->createChatService($provider, $registry);
    $caps = $service->getProviderCapabilities();

    self::assertSame(1, count(array_filter($caps['supportedFormats'], fn($f) => $f === 'image/jpeg')));
}
```

- [ ] **Step 2: Run existing capabilities tests — verify they FAIL** (constructor mismatch)

```bash
.Build/bin/phpunit -c Build/phpunit.xml Tests/Unit/Service/ChatServiceCapabilitiesTest.php
```

- [ ] **Step 3: Update ChatService constructor and getProviderCapabilities()**

Add to `ChatService` constructor (last parameter):

```php
private readonly \Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry $documentExtractorRegistry,
```

Update `getProviderCapabilities()` to merge extraction formats unconditionally:

```php
public function getProviderCapabilities(): array
{
    $extractionFormats = $this->documentExtractorRegistry->getAvailableMimeTypes();

    try {
        $provider = $this->resolveProvider();
        if ($provider instanceof VisionCapableInterface && $provider->supportsVision()) {
            $documentFormats = $provider instanceof DocumentCapableInterface && $provider->supportsDocuments()
                ? $provider->getSupportedDocumentFormats()
                : [];

            return [
                'visionSupported' => true,
                'maxFileSize' => $provider->getMaxImageSize(),
                'supportedFormats' => array_values(array_unique(array_merge(
                    $provider->getSupportedImageFormats(),
                    $documentFormats,
                    $extractionFormats,
                ))),
            ];
        }
    } catch (Throwable) {
        // Provider resolution failed — fall through to extraction-only response
    }

    return [
        'visionSupported' => false,
        'maxFileSize' => 0,
        'supportedFormats' => array_values($extractionFormats),
    ];
}
```

- [ ] **Step 4: Fix ChatWorkerCommandExecuteTest — add registry mock to createChatService()**

In `Tests/Unit/Command/ChatWorkerCommandExecuteTest.php`, update `createChatService()`:

```php
// Add to imports:
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;

// In createChatService(), add before the return:
$registry = new DocumentExtractorRegistry([]);

// Update the constructor call to add $registry as last argument:
return new ChatService(
    $this->createMock(ConversationRepository::class),
    $config,
    $this->createMock(McpToolProviderInterface::class),
    $llmTaskRepository,
    $adapterRegistry,
    $this->createMock(ResourceFactory::class),
    $this->createMock(SiteFinder::class),
    $registry,
);
```

- [ ] **Step 5: Run all capabilities + worker tests — verify they PASS**

```bash
.Build/bin/phpunit -c Build/phpunit.xml Tests/Unit/Service/ChatServiceCapabilitiesTest.php Tests/Unit/Command/ChatWorkerCommandExecuteTest.php
```

- [ ] **Step 6: Commit**

```bash
git add Classes/Service/ChatService.php Tests/Unit/Service/ChatServiceCapabilitiesTest.php Tests/Unit/Command/ChatWorkerCommandExecuteTest.php
git commit -m "feat: inject DocumentExtractorRegistry into ChatService, merge extraction formats in getProviderCapabilities()"
```

---

## Task 9: Update ChatService::buildFileContentBlock()

**Files:**
- Modify: `Classes/Service/ChatService.php`
- Modify: `Tests/Unit/Service/ChatServiceTest.php` (add 1 new test)

- [ ] **Step 1: Write a failing test for extraction fallback**

In `Tests/Unit/Service/ChatServiceTest.php`, add a test for the extraction branch. Find the existing `createChatService()` helper and add a variant, or add a new test class if the existing file is already large. Add:

```php
#[Test]
public function buildFileContentBlockReturnsTextBlockWhenProviderCannotHandleDocument(): void
{
    // The provider is not DocumentCapableInterface — extraction must be used.
    // We verify by checking the returned block type is 'text'.
    // This test accesses buildFileContentBlock via buildLlmMessages indirectly:
    // set up a conversation with a file attachment pointing to sample.txt,
    // verify the resulting message content contains a text block.

    // Use reflection to call the private method directly for a cleaner test:
    $provider = $this->createMock(\Netresearch\NrLlm\Provider\Contract\ProviderInterface::class);

    $extractor = $this->createMock(\Netresearch\NrMcpAgent\Document\DocumentExtractorInterface::class);
    $extractor->method('isAvailable')->willReturn(true);
    $extractor->method('getSupportedMimeTypes')->willReturn(['text/plain']);
    $extractor->method('extract')->willReturn('Hello TXT');
    $registry = new \Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry([$extractor]);

    $service = $this->createChatServiceWithRegistry($registry);

    $method = new \ReflectionMethod($service, 'buildFileContentBlock');
    $method->setAccessible(true);

    $tmpPath = tempnam(sys_get_temp_dir(), 'nr_test_');
    file_put_contents($tmpPath, 'Hello TXT');
    try {
        $block = $method->invoke($service, 'text/plain', base64_encode('Hello TXT'), $tmpPath, $provider);
    } finally {
        unlink($tmpPath);
    }

    self::assertSame('text', $block['type']);
    self::assertStringContainsString('Hello TXT', $block['text']);
}
```

You'll need a `createChatServiceWithRegistry()` helper in the test class that passes a real registry.

- [ ] **Step 2: Run test — verify it FAILS**

```bash
.Build/bin/phpunit -c Build/phpunit.xml --filter buildFileContentBlockReturnsTextBlockWhenProviderCannotHandleDocument Tests/Unit/Service/ChatServiceTest.php
```

- [ ] **Step 3: Update buildFileContentBlock() and its call site**

Update the method signature:

```php
private function buildFileContentBlock(
    string $mimeType,
    string $base64,
    string $localPath,
    ProviderInterface $provider,
): array {
    if (str_starts_with($mimeType, 'image/')) {
        return [
            'type' => 'image_url',
            'image_url' => ['url' => 'data:' . $mimeType . ';base64,' . $base64],
        ];
    }
    if ($provider instanceof DocumentCapableInterface && $provider->supportsDocuments()) {
        return [
            'type' => 'document',
            'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => $base64],
        ];
    }
    if ($this->documentExtractorRegistry->canExtract($mimeType)) {
        $text = $this->documentExtractorRegistry->extract($localPath, $mimeType);
        return [
            'type' => 'text',
            'text' => '[Extracted from ' . basename($localPath) . ']' . "\n"
                . ($text !== '' ? $text : '[File contained no extractable text]'),
        ];
    }
    throw new RuntimeException(
        'Provider "' . $provider->getIdentifier() . '" does not support document uploads (mime type: ' . $mimeType . ')',
        1742320000,
    );
}
```

Update the call site in `buildLlmMessages()` (line ~401):

```php
$this->buildFileContentBlock($mimeType, $base64, $localPath, $provider),
```

- [ ] **Step 4: Run test — verify it PASSES**

```bash
.Build/bin/phpunit -c Build/phpunit.xml Tests/Unit/Service/ChatServiceTest.php
```

- [ ] **Step 5: Commit**

```bash
git add Classes/Service/ChatService.php Tests/Unit/Service/ChatServiceTest.php
git commit -m "feat: add extraction fallback in buildFileContentBlock()"
```

---

## Task 10: Update ChatApiController — dynamic allowlist + validate()

**Files:**
- Modify: `Classes/Controller/ChatApiController.php`
- Modify: `Tests/Unit/Controller/ChatApiControllerTest.php`

- [ ] **Step 1: Write failing tests**

In `ChatApiControllerTest`, update `setUp()` to inject a `DocumentExtractorRegistry` mock, and add three new tests.

Update `setUp()`:

```php
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;

// In setUp():
$this->documentExtractorRegistry = $this->createMock(DocumentExtractorRegistry::class);
$this->documentExtractorRegistry->method('getAvailableMimeTypes')->willReturn([]);
$this->documentExtractorRegistry->method('canExtract')->willReturn(false);

$this->subject = new ChatApiController(
    $this->repository, $this->processor, $this->config,
    $this->chatService, $this->resourceFactory,
    $this->storageRepository, $this->documentExtractorRegistry,
);
```

Add new property: `private DocumentExtractorRegistry $documentExtractorRegistry;`

Update the existing `fileUploadRejectsInvalidMimeType` test: change `assertSame(400, ...)` to `assertSame(422, ...)`.

Add these three new tests:

```php
#[Test]
public function fileUploadAcceptsExtractionBackedMimeType(): void
{
    $tmpPath = tempnam(sys_get_temp_dir(), 'nr_test_');
    file_put_contents($tmpPath, 'Hello TXT');

    $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
    $stream->method('getMetadata')->with('uri')->willReturn($tmpPath);

    $uploadedFile = $this->createMock(UploadedFileInterface::class);
    $uploadedFile->method('getError')->willReturn(UPLOAD_ERR_OK);
    $uploadedFile->method('getSize')->willReturn(9);
    $uploadedFile->method('getStream')->willReturn($stream);
    $uploadedFile->method('getClientFilename')->willReturn('test.txt');

    $this->documentExtractorRegistry->method('getAvailableMimeTypes')->willReturn(['text/plain']);
    $this->documentExtractorRegistry->method('canExtract')->with('text/plain')->willReturn(true);
    // validate() does not throw — file is valid

    $falFile = $this->createMock(\TYPO3\CMS\Core\Resource\File::class);
    $falFile->method('getUid')->willReturn(99);
    $falFile->method('getName')->willReturn('test.txt');
    $falFile->method('getMimeType')->willReturn('text/plain');
    $falFile->method('getSize')->willReturn(9);

    $storage = $this->createMock(\TYPO3\CMS\Core\Resource\ResourceStorage::class);
    $storage->method('addFile')->willReturn($falFile);
    $storage->method('getFolder')->willReturn($this->createMock(\TYPO3\CMS\Core\Resource\Folder::class));
    $storage->method('hasFolder')->willReturn(true);
    $this->storageRepository->method('getDefaultStorage')->willReturn($storage);

    $request = $this->createMock(ServerRequestInterface::class);
    $request->method('getUploadedFiles')->willReturn(['file' => $uploadedFile]);

    try {
        $response = $this->subject->fileUpload($request);
    } finally {
        @unlink($tmpPath);
    }

    self::assertSame(200, $response->getStatusCode());
}

#[Test]
public function fileUploadReturns422ForUnsupportedMimeType(): void
{
    $tmpPath = tempnam(sys_get_temp_dir(), 'nr_test_');
    file_put_contents($tmpPath, 'Hello, this is plain text content.');

    $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
    $stream->method('getMetadata')->with('uri')->willReturn($tmpPath);

    $file = $this->createMock(UploadedFileInterface::class);
    $file->method('getError')->willReturn(UPLOAD_ERR_OK);
    $file->method('getSize')->willReturn(34);
    $file->method('getStream')->willReturn($stream);

    $request = $this->createMock(ServerRequestInterface::class);
    $request->method('getUploadedFiles')->willReturn(['file' => $file]);

    try {
        $response = $this->subject->fileUpload($request);
    } finally {
        @unlink($tmpPath);
    }

    self::assertSame(422, $response->getStatusCode());
}

#[Test]
public function fileUploadReturns422WhenValidationFails(): void
{
    $tmpPath = tempnam(sys_get_temp_dir(), 'nr_test_');
    file_put_contents($tmpPath, 'Hello TXT');

    $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
    $stream->method('getMetadata')->with('uri')->willReturn($tmpPath);

    $uploadedFile = $this->createMock(UploadedFileInterface::class);
    $uploadedFile->method('getError')->willReturn(UPLOAD_ERR_OK);
    $uploadedFile->method('getSize')->willReturn(9);
    $uploadedFile->method('getStream')->willReturn($stream);

    $this->documentExtractorRegistry->method('getAvailableMimeTypes')->willReturn(['text/plain']);
    $this->documentExtractorRegistry->method('canExtract')->with('text/plain')->willReturn(true);
    $this->documentExtractorRegistry->method('validate')->willThrowException(new \RuntimeException('corrupt'));

    $request = $this->createMock(ServerRequestInterface::class);
    $request->method('getUploadedFiles')->willReturn(['file' => $uploadedFile]);

    try {
        $response = $this->subject->fileUpload($request);
    } finally {
        @unlink($tmpPath);
    }

    self::assertSame(422, $response->getStatusCode());
}
```

- [ ] **Step 2: Run tests — verify they FAIL**

```bash
.Build/bin/phpunit -c Build/phpunit.xml Tests/Unit/Controller/ChatApiControllerTest.php
```

- [ ] **Step 3: Update ChatApiController**

Add `DocumentExtractorRegistry` to the constructor:

```php
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;

public function __construct(
    private readonly ConversationRepository $repository,
    private readonly ChatProcessorInterface $processor,
    private readonly ExtensionConfiguration $config,
    private readonly ChatCapabilitiesInterface $chatService,
    private readonly ResourceFactory $resourceFactory,
    private readonly StorageRepository $storageRepository,
    private readonly DocumentExtractorRegistry $documentExtractorRegistry,
) {}
```

Replace the `fileUpload()` method body from the MIME check onwards:

```php
$capabilities = $this->chatService->getProviderCapabilities();
$allowedMimeTypes = array_merge(
    $capabilities['supportedFormats'],
    $this->documentExtractorRegistry->getAvailableMimeTypes(),
);
$allowedMimeTypes = array_values(array_unique($allowedMimeTypes));

$maxSize = 20 * 1024 * 1024; // 20 MB
if ($file->getSize() > $maxSize) {
    return new JsonResponse(['error' => 'File too large (max 20 MB)'], 400);
}

// Validate MIME type server-side via finfo — client-supplied Content-Type is untrusted
$uri = $file->getStream()->getMetadata('uri');
$tempPath = is_string($uri) ? $uri : '';
$finfo = new finfo(FILEINFO_MIME_TYPE);
$detectedMime = $finfo->file($tempPath);
if (!is_string($detectedMime) || !in_array($detectedMime, $allowedMimeTypes, true)) {
    return new JsonResponse(['error' => 'File type not supported'], 422);
}

// For extraction-backed formats, run lightweight validation at upload time
if ($this->documentExtractorRegistry->canExtract($detectedMime)) {
    try {
        $this->documentExtractorRegistry->validate($tempPath, $detectedMime);
    } catch (\RuntimeException $e) {
        return new JsonResponse(['error' => 'File could not be processed: ' . $e->getMessage()], 422);
    }
}
```

- [ ] **Step 4: Run all controller tests — verify they PASS**

```bash
.Build/bin/phpunit -c Build/phpunit.xml Tests/Unit/Controller/ChatApiControllerTest.php
```

- [ ] **Step 5: Run all unit tests**

```bash
.Build/bin/phpunit -c Build/phpunit.xml --testsuite unit
```

All tests must pass.

- [ ] **Step 6: Commit**

```bash
git add Classes/Controller/ChatApiController.php Tests/Unit/Controller/ChatApiControllerTest.php
git commit -m "feat: dynamic upload allowlist and extraction validation in ChatApiController"
```

---

## Task 11: Architecture Test

**Files:**
- Create: `Tests/Unit/Architecture/DocumentExtractorArchitectureTest.php`

- [ ] **Step 1: Create architecture test**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

class DocumentExtractorArchitectureTest
{
    public function extractorsDoNotDependOnChatService(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrMcpAgent\Document'))
            ->shouldNotDependOn()
            ->classes(
                Selector::classname(\Netresearch\NrMcpAgent\Service\ChatService::class),
                Selector::inNamespace('Netresearch\NrMcpAgent\Controller'),
            );
    }
}
```

- [ ] **Step 2: Run architecture tests**

```bash
.Build/bin/phpunit -c Build/phpunit.xml --testsuite architecture
```

- [ ] **Step 3: Commit**

```bash
git add Tests/Unit/Architecture/DocumentExtractorArchitectureTest.php
git commit -m "test(arch): extractors must not depend on ChatService or controllers"
```

---

## Task 12: Quality Pass

- [ ] **Step 1: PHPStan**

```bash
.Build/bin/phpstan analyse -c Build/phpstan/phpstan.neon --no-progress
```

Fix any level-10 errors in the new classes before proceeding.

- [ ] **Step 2: Code style**

```bash
.Build/bin/php-cs-fixer fix --dry-run --diff
```

If there are findings:

```bash
.Build/bin/php-cs-fixer fix
git add -p
git commit -m "style: apply php-cs-fixer to document extractor classes"
```

- [ ] **Step 3: Full test suite**

```bash
.Build/bin/phpunit -c Build/phpunit.xml
```

All suites (unit, architecture) must be green.

- [ ] **Step 4: Mutation testing**

```bash
.Build/bin/infection --configuration=infection.json.dist --threads=4 --no-progress
```

Target: Covered Code MSI ≥ 70%. Focus areas for escaped mutants:
- `DocumentExtractorRegistry::findExtractor()` — `in_array` strict comparison
- `PdfExtractor::validate()` — the `Encrypt` key check
- `ChatService::getProviderCapabilities()` — `array_unique` / `array_values`

Fix escaped mutants by adding targeted assertions to the relevant test.

- [ ] **Step 5: Commit quality fixes if needed**

```bash
git add .
git commit -m "test: fix escaped mutation survivors in document extractor classes"
```

---

## Task 13: Update CHANGELOG / README

- [ ] **Step 1: Update README.md**

Add a "Supported file formats" section documenting PDF, DOCX, TXT (always available) and XLSX (requires phpoffice/phpspreadsheet). Note that text extraction is a fallback when the provider does not natively support the format.

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs: document file format support and XLSX optional dependency"
```
