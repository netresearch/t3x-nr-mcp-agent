# Document Text Extraction Fallback

**Date:** 2026-03-25
**Status:** Approved

## Problem

When a user uploads a PDF, DOCX, XLSX, or TXT file and the configured LLM provider does not natively support
that document format (i.e., does not implement `DocumentCapableInterface`), `buildFileContentBlock()` throws
a `RuntimeException`. The file cannot be sent to the model at all.

**Goal:** Extract text from these files server-side and inject it as a plain-text block into the prompt.
This makes all four formats usable regardless of the provider's native capabilities.

---

## Architecture

### Ownership

Extraction capabilities are an **nr-mcp-agent concern**, not an nr-llm concern.

- `DocumentCapableInterface` (nr-llm) — reports what a provider can accept natively as binary.
- `DocumentExtractorInterface` (nr-mcp-agent) — reports what the extension can transform into text.

The two systems are independent and compose in `getProviderCapabilities()`: native formats come from
the provider adapter, extraction-backed formats come from the registry.

### New Classes

```
Classes/
  Document/
    DocumentExtractorInterface.php
    DocumentExtractorRegistry.php
    Extractor/
      PdfExtractor.php          # smalot/pdfparser (hard dep)
      DocxExtractor.php         # phpoffice/phpword (hard dep)
      XlsxExtractor.php         # phpoffice/phpspreadsheet (composer suggest)
      PlainTextExtractor.php    # no dependency
```

### Interface

```php
interface DocumentExtractorInterface
{
    /** MIME types this extractor handles. */
    public function getSupportedMimeTypes(): array;

    /** Returns false when a required optional library is not installed. */
    public function isAvailable(): bool;

    /**
     * Lightweight validation at upload time.
     * Checks that the file can be opened; does NOT extract full text.
     *
     * @throws \RuntimeException on corrupt or unreadable files.
     */
    public function validate(string $path): void;

    /** Full text extraction, called at LLM-send time. */
    public function extract(string $path): string;
}
```

### Registry

`DocumentExtractorRegistry` collects all extractors via Symfony DI tagged services.

```yaml
# Configuration/Services.yaml
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

The Registry exposes:
- `getAvailableMimeTypes(): array` — only formats where `isAvailable()` is true
- `canExtract(string $mimeType): bool`
- `validate(string $path, string $mimeType): void`
- `extract(string $path, string $mimeType): string`

Adding a new format = one new class + one tag. No existing code changes.

---

## Dependencies

```json
"require": {
    "smalot/pdfparser": "^2.0",
    "phpoffice/phpword": "^1.0"
},
"suggest": {
    "phpoffice/phpspreadsheet": "^3.0 — Required for XLSX upload support"
}
```

`XlsxExtractor::isAvailable()` uses `class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)`.
When the library is absent, XLSX does not appear in the upload allowlist and the upload endpoint
returns 422 for that type.

---

## Data Flow

### Upload path

```
POST /ai-chat/file-upload
  → ChatApiController::fileUpload()
  → allowedMimeTypes = providerCapabilities['supportedFormats']
                     + registry->getAvailableMimeTypes()
  → MIME not in list → 422
  → registry->validate($tempPath, $mimeType)   [lightweight check]
  → validation throws → 422 "File could not be processed"
  → storage->addFile() → FAL
  → return { uid, name, mimeType }
```

File storage: FAL (TYPO3 File Abstraction Layer). No temp-dir accumulation.
Cleanup is handled by the admin through TYPO3's file module.

### LLM-send path

```
ChatService::buildLlmMessages()
  → per attachment: buildFileContentBlock($mimeType, $base64, $provider)
    → image/*                        → image_url block (unchanged)
    → DocumentCapableInterface nativ → document block (unchanged)
    → registry->canExtract($mime)    → extract() → text block:
         [{'type': 'text', 'text': "[Extracted from file.pdf]\n{content}"}]
    → otherwise                      → RuntimeException (unreachable: upload filtered)
```

Full extraction is **lazy**: it happens only when the LLM request is built, not at upload time.
The upload-time `validate()` is a lightweight open/parse-header check only.

---

## Error Handling

| Situation | Behaviour |
|-----------|-----------|
| Optional lib missing (e.g., phpspreadsheet) | `isAvailable()` = false → format absent from allowlist → 422 on upload |
| Corrupt / encrypted file at upload | `validate()` throws → HTTP 422 with user-readable message |
| Empty extraction result | Text block contains `[File contained no extractable text]` |
| Extraction fails at LLM-send time (edge case) | `RuntimeException` caught in `ChatService` → error surfaced in chat response |

Upload-time failure (c) is preferred over chat-time failure (b) because it gives immediate
feedback before the user has composed their message.

---

## Changes to Existing Classes

| Class | Change |
|-------|--------|
| `ChatService::getProviderCapabilities()` | Merge `registry->getAvailableMimeTypes()` into `supportedFormats` |
| `ChatService::buildFileContentBlock()` | Add extraction branch before RuntimeException |
| `ChatApiController::fileUpload()` | Dynamic allowlist from provider + registry; call `registry->validate()` |
| `Configuration/Services.yaml` | Register extractors with `document.extractor` tag |
| `composer.json` | Add hard deps + suggest for phpspreadsheet |

---

## Tests

### Unit tests (TDD — tests written before implementation)

| Test class | What it covers |
|-----------|---------------|
| `DocumentExtractorRegistryTest` | `getAvailableMimeTypes()` excludes unavailable extractors; `extract()` delegates correctly; unknown MIME → exception |
| `PdfExtractorTest` | `isAvailable()`, `validate()` with corrupt fixture → exception, `extract()` with sample.pdf |
| `DocxExtractorTest` | Same pattern for DOCX |
| `XlsxExtractorTest` | `isAvailable()` = false when lib absent (mocked via `class_exists` wrapper); `extract()` with sample.xlsx |
| `PlainTextExtractorTest` | `isAvailable()` always true; `extract()` returns file contents |
| `ChatServiceTest` | `buildFileContentBlock()` with extraction-capable registry returns text block |
| `ChatApiControllerTest` | Upload with extraction-backed MIME: 200; unsupported MIME: 422; corrupt file: 422 |

### Fixtures

```
Tests/Fixtures/Documents/
  sample.pdf          # valid, contains "Hello PDF"
  sample.docx         # valid, contains "Hello DOCX"
  sample.xlsx         # valid, single cell "Hello XLSX"
  sample.txt          # "Hello TXT"
  corrupt.pdf         # invalid PDF header
  encrypted.pdf       # password-protected
```

### Code quality

- PHPStan level 10 on all new classes; no `@phpstan-ignore`
- PHP-CS-Fixer clean (`runTests.sh -s cgl`)
- Mutation testing: extractors are simple classes with high MSI potential;
  critical mutations to kill: empty-extraction handling, `isAvailable()` guard in registry
- Architecture tests: `DocumentExtractorInterface` implementations must not depend on
  `ChatService` or controllers (unidirectional dependency)

---

## Known Gaps / Future Work

- **FAL file cleanup** — `CleanupCommand` currently removes conversation DB rows but not the
  associated FAL files. This affects all uploads (images and documents). Separate ticket.
- **Per-type upload limits** — currently one global `maxFileSize` applies to all formats.
  If needed, `DocumentExtractorInterface` can be extended with `getMaxFileSize(): ?int`
  (`null` = use global limit) without breaking existing extractors.
- **ADRs** — After implementation, document the following decisions in `docs/adr/`:
  - Extraction ownership in nr-mcp-agent vs nr-llm
  - Hard dep vs suggest split for phpspreadsheet
  - Registry/strategy pattern choice
  - Lazy extraction (LLM-time, not upload-time)
