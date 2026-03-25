# ADR-013: Server-Side Document Text Extraction as Provider Fallback

**Status:** Accepted
**Date:** 2026-03-25

## Context

Users can attach files (PDF, DOCX, XLSX, TXT) to chat messages. Some LLM providers (e.g. Anthropic Claude) natively accept these formats as binary content. Others do not implement `DocumentCapableInterface` and cannot receive binary documents at all — the agent loop would throw a `RuntimeException` and the file would be unusable.

Alternatives considered:
- **Reject files for non-capable providers**: Simple, but severely limits usability across providers.
- **Require a document-capable provider**: Forces configuration choices on the administrator; incompatible with ADR-004 (provider agnosticism).
- **Server-side extraction as a fallback**: Extract text from the document on the server, inject it as a plain-text block in the prompt. Works with any provider.

## Decision

Introduce a `DocumentExtractorRegistry` with a `DocumentExtractorInterface`. When the configured provider does not natively support a document format, the extension extracts the text server-side and injects it into the prompt as a fenced text block.

Extractors:
- `PlainTextExtractor` — always available, no dependencies.
- `PdfExtractor` — uses `smalot/pdfparser` (hard dependency).
- `DocxExtractor` — uses `phpoffice/phpword` (hard dependency).
- `XlsxExtractor` — uses `phpoffice/phpspreadsheet` (optional; XLSX uploads return 422 if not installed).

The two systems are independent and compose in the capability detection layer: a format is usable if either the provider supports it natively OR an extractor is available.

## Consequences

- All four document formats work with any configured LLM provider.
- XLSX support is deliberately optional to avoid a heavy dependency for users who do not need it.
- Extracted text loses formatting (tables become flat text, DOCX styles are stripped) — acceptable given the goal of making content accessible to the LLM.
- The registry is an extension point: additional extractors can be registered via DI without modifying core classes.
