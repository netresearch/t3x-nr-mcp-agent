# Document Text Extraction Fallback

**Date:** 2026-03-25
**Status:** Approved

## Problem

When a user uploads a PDF, DOCX, XLSX, or TXT file and the configured LLM provider does not natively
support that document format (i.e., does not implement `DocumentCapableInterface`), `buildFileContentBlock()`
throws a `RuntimeException`. The file cannot be sent to the model at all.

**Goal:** Extract text from these files server-side and inject it as a plain-text block into the prompt.
This makes all four formats usable regardless of the provider's native capabilities.

---

## Architecture

### Ownership

Extraction capabilities are an **nr-mcp-agent concern**, not an nr-llm concern.

- `DocumentCapableInterface` (nr-llm) — reports what a provider can accept natively as binary.
- `DocumentExtractorInterface` (nr-mcp-agent) — reports what the extension can transform into text.

The two systems are independent and compose in `getProviderCapabilities()`.

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

    /**
     * Returns false when a required optional library is not installed.
     * PlainTextExtractor always returns true.
     */
    public function isAvailable(): bool;

    /**
     * Lightweight validation at upload time.
     * Checks that the file can be opened; does NOT extract full text.
     *
     * @throws \RuntimeException on corrupt or unreadable files.
     */
    public function validate(string $path): void;

    /** Full text extraction. Returns empty string when file has no text content. */
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
- `extract(string $path, string $mimeType): string` — throws `\RuntimeException` for unknown MIME

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

**Rationale for hard vs. suggest split:**
- `smalot/pdfparser` and `phpoffice/phpword` are hard deps because PDF and DOCX are by far the
  most common document formats in enterprise use. Users installing this extension expect these to
  work without additional setup.
- `phpoffice/phpspreadsheet` (~3 MB) is a suggest dep because XLSX uploads are a less common
  use case and the library is substantially larger. `XlsxExtractor::isAvailable()` uses
  `class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)`. When absent, XLSX does not appear
  in the upload allowlist and uploading that type returns 422.

---

## Data Flow

### Upload path

```
POST /ai-chat/file-upload
  → ChatApiController::fileUpload()
  → allowedMimeTypes = array_merge(
        providerCapabilities['supportedFormats'],   // images + native doc types
        registry->getAvailableMimeTypes(),           // extraction-backed types
    )
  → MIME not in list → 422
  → if registry->canExtract($mimeType):
        registry->validate($tempPath, $mimeType)    [lightweight check]
        validation throws → 422 "File could not be processed"
    // provider-native MIMEs (images, native docs) skip validate() — the provider handles them
  → storage->addFile() → FAL
  → return { uid, name, mimeType }
```

**Rule: `registry->validate()` is called only when the MIME will use the extraction path.**
A MIME that appears in both `providerFormats` and `extractionFormats` (e.g., a provider that
natively accepts PDF and PdfExtractor is also registered) is treated as provider-native at the
upload stage — `validate()` is skipped. `buildFileContentBlock()` will use the native document
block for it. `array_unique` in `getProviderCapabilities()` ensures it appears once in the
allowlist.

**Note on image types in the dynamic allowlist:**
Image types (`image/png`, `image/jpeg`, `image/webp`) come from `providerCapabilities['supportedFormats']`
(populated by `VisionCapableInterface`). If the provider has no vision support, image types are
absent from the allowlist — intentional, since the provider cannot process them. The current static
allowlist is replaced entirely by this dynamic merge.

File storage: FAL (TYPO3 File Abstraction Layer). No temp-dir accumulation. Cleanup is handled by
the admin through TYPO3's file module.

### LLM-send path

`buildFileContentBlock()` currently receives `(string $mimeType, string $base64, ProviderInterface $provider)`.
For text extraction, the local filesystem path is required. The method signature is extended:

```php
private function buildFileContentBlock(
    string $mimeType,
    string $base64,
    string $localPath,      // NEW — needed for DocumentExtractorRegistry::extract()
    ProviderInterface $provider,
): array
```

**Source of `$localPath`:** `buildLlmMessages()` already retrieves the FAL file object and calls
`$falFile->getForLocalProcessing()` (or equivalent) to obtain the absolute local path before
base64-encoding the binary. This path is passed through unchanged to `buildFileContentBlock()`.
No additional FAL reads are required.

```
ChatService::buildLlmMessages()
  → per attachment: buildFileContentBlock($mimeType, $base64, $localPath, $provider)
    → image/*                         → image_url block (unchanged)
    → DocumentCapableInterface nativ  → document block (unchanged)
    → registry->canExtract($mime)     → registry->extract($localPath, $mime) → text block:
           [{'type': 'text', 'text': "[Extracted from file.pdf]\n{content}"}]
           empty result: "[File contained no extractable text]"
    → otherwise                       → RuntimeException (unreachable: upload filtered)
```

**Extraction errors at LLM-send time:**
`buildLlmMessages()` currently has a `catch (\Throwable $e)` block that converts exceptions to
"[Attached file … is no longer available]". Extraction failures (corrupt file that passed
validation, I/O error after upload) will be caught by this existing handler. This is acceptable
defensive behaviour. The spec does not require changing this catch block.

Full extraction is **lazy**: it happens only when the LLM request is built, not at upload time.

---

## Error Handling

| Situation | HTTP / Behaviour |
|-----------|-----------------|
| MIME type not in allowlist | 422 |
| Optional lib missing (e.g., phpspreadsheet) | `isAvailable()` = false → format absent from allowlist → 422 on upload |
| Corrupt / encrypted file at upload | `validate()` throws → 422 with user-readable message |
| Empty extraction result | Text block contains `[File contained no extractable text]` |
| Extraction fails at LLM-send time | Caught by existing `catch (\Throwable)` → "[Attached file … is no longer available]" |

All upload rejections use **HTTP 422** (Unprocessable Entity). The existing 400 responses for
missing-file and PHP upload errors remain 400 — those are transport errors, not semantic failures.

---

## Changes to Existing Classes

| Class | Change |
|-------|--------|
| `ChatService::getProviderCapabilities()` | Merge `registry->getAvailableMimeTypes()` into `supportedFormats` unconditionally (extraction formats are provider-agnostic); deduplicate with `array_unique` |
| `ChatService::buildFileContentBlock()` | Add `string $localPath` parameter; add extraction branch before RuntimeException |
| `ChatApiController::fileUpload()` | Dynamic allowlist; call `registry->validate()` only for extraction-backed MIMEs |
| `Configuration/Services.yaml` | Register extractors with `document.extractor` tag |
| `composer.json` | Add hard deps + suggest for phpspreadsheet |

### `getProviderCapabilities()` — extraction formats are always present

```php
$extractionFormats = $this->documentExtractorRegistry->getAvailableMimeTypes();
$providerFormats   = /* ... existing vision + native-doc logic ... */;

return [
    'visionSupported'  => $visionSupported,
    'maxFileSize'      => $maxFileSize,
    'supportedFormats' => array_values(array_unique(array_merge($providerFormats, $extractionFormats))),
];
```

Extraction formats appear unconditionally — even when `VisionCapableInterface` is absent.
`array_unique` prevents a MIME type that is both provider-native and extraction-backed from
appearing twice. The result is always a sequential list (`array_values`).

---

## Tests

### Unit tests (TDD — tests written before implementation)

| Test class | What it covers |
|-----------|---------------|
| `DocumentExtractorRegistryTest` | `getAvailableMimeTypes()` excludes unavailable extractors; `extract()` delegates correctly; unknown MIME → RuntimeException |
| `PdfExtractorTest` | `isAvailable()` = true; `validate()` corrupt → exception; `validate()` encrypted → exception; `extract()` with sample.pdf |
| `DocxExtractorTest` | `isAvailable()` = true; `validate()` corrupt → exception; `extract()` with sample.docx |
| `XlsxExtractorTest` | `isAvailable()` = false when lib absent; `validate()` corrupt → exception; `extract()` with sample.xlsx when lib present |
| `PlainTextExtractorTest` | `isAvailable()` always true; `validate()` unreadable path → exception; `extract()` returns file contents |
| `ChatServiceTest` | `buildFileContentBlock()` with extraction-capable registry returns text block (not document block); `$localPath` is passed through correctly |
| `ChatServiceCapabilitiesTest` | (1) extraction formats present when provider **not** VisionCapableInterface; (2) extraction formats merged with provider formats when both available; (3) MIME appearing in both provider and extractor lists appears exactly once (kills `array_unique` mutation) |
| `ChatApiControllerTest` | (1) extraction-backed MIME accepted; (2) unsupported MIME → 422; (3) corrupt file → 422; (4) provider-native-only MIME (e.g., image/png with vision-capable provider) accepted without calling `registry->validate()` |

### Fixtures

```
Tests/Fixtures/Documents/
  sample.pdf          # valid, contains "Hello PDF"
  sample.docx         # valid, contains "Hello DOCX"
  sample.xlsx         # valid, single cell "Hello XLSX"
  sample.txt          # "Hello TXT"
  corrupt.pdf         # invalid PDF header (truncated/random bytes)
  corrupt.docx        # invalid DOCX (truncated zip/xml)
  corrupt.xlsx        # invalid XLSX (truncated zip/xml)
  encrypted.pdf       # password-protected, valid structure but unreadable
```

`corrupt.pdf` / `corrupt.docx` / `corrupt.xlsx` — file structure is broken; parser throws immediately.
`encrypted.pdf` — file is structurally valid but content-inaccessible without a password; tested
as a distinct case in `PdfExtractorTest::validateThrowsForEncryptedFile()`.

### Code quality

- PHPStan level 10 on all new classes; no `@phpstan-ignore`
- PHP-CS-Fixer clean (`runTests.sh -s cgl`)
- Mutation testing: extractors are simple classes with high MSI potential;
  critical mutations to kill: empty-extraction handling, `isAvailable()` guard in registry,
  `array_unique` in `getProviderCapabilities()` (covered by `ChatServiceCapabilitiesTest` case 3)
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
  - Upload error code alignment (422 for semantic failures, 400 for transport errors)
  - validate() skip rule for provider-native MIMEs
