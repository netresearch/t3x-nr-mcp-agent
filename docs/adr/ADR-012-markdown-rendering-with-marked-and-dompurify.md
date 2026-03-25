# ADR-012: Markdown Rendering with marked.js and DOMPurify

**Status:** Accepted
**Date:** 2026-03-17

## Context

LLM responses frequently contain Markdown: headings, bullet lists, numbered steps, code blocks, and tables. Displaying these as raw text degrades readability significantly. The rendered output must be XSS-safe: a compromised or adversarially prompted LLM could produce HTML or JavaScript in its response.

Alternatives considered:
- **Plain text only**: Safe, but unreadable for structured responses.
- **Server-side Markdown-to-HTML (PHP)**: Requires a PHP Markdown library, adds a server round-trip for each render, and moves rendering responsibility to the server.
- **`innerHTML` without sanitization**: Fast, but allows XSS if the LLM output contains `<script>` tags or event handlers.
- **marked.js + DOMPurify in the browser**: Client-side rendering, no server round-trip, XSS-safe via DOMPurify sanitization after parsing.

## Decision

Vendor marked.js v15 and DOMPurify v3 as static assets (no build step, consistent with ADR-008). LLM response text is parsed by marked.js into HTML, then sanitized by DOMPurify before being set as `innerHTML`. Both libraries are treated as untrusted-input pipelines: marked produces HTML from untrusted text, DOMPurify strips anything dangerous before it reaches the DOM.

## Consequences

- LLM responses render as rich text (headings, lists, code blocks, tables) without a server round-trip.
- XSS is prevented even if the LLM produces malicious HTML in its output.
- Vendored libraries must be updated manually when security patches are released.
- No build step is introduced (libraries are used as ES modules or UMD bundles loaded directly).
