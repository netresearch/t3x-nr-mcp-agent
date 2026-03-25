# ADR-010: LLM Error Message Sanitization Before Browser Output

**Status:** Accepted
**Date:** 2026-03-15

## Context

When the LLM API call or MCP tool execution fails, the exception message may contain sensitive data from the provider stack: Bearer tokens, API keys, internal URLs, or credential fragments embedded in HTTP error responses. If forwarded to the browser as-is, these leak credentials to the end user (and potentially to browser logs, network proxies, or JavaScript error trackers).

Alternatives considered:
- **Generic error messages only** ("An error occurred"): Safe, but provides no useful diagnostic information to the user or administrator.
- **Server-side logging only, generic client message**: Good for production, but loses context for debugging.
- **Sanitize before sending**: Strip known credential patterns and truncate, then forward the cleaned message to the client.

## Decision

All exception messages that originate from LLM or MCP calls are passed through `ErrorMessageSanitizer::sanitize()` before being stored in the database or returned to the browser. The sanitizer:

- Redacts `Bearer <token>` patterns.
- Redacts strings matching common API key patterns (`sk-...`, `key-...`, `api-key-...`).
- Replaces URLs with `[URL]`.
- Truncates to 500 characters.

## Consequences

- Credential leaks via error messages are prevented at the boundary between the processing layer and the database/browser.
- Sanitized messages still carry enough context (HTTP status codes, provider error codes) for debugging.
- The sanitizer is a simple utility class with no dependencies, independently testable.
- Patterns may need updating as provider error formats evolve.
