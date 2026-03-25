# ADR-004: nr-llm as LLM Abstraction Layer

**Status:** Accepted
**Date:** 2026-03-14

## Context

The extension needs to call an LLM API. Multiple providers are relevant (OpenAI, Anthropic Claude, Google Gemini, Ollama), each with different SDKs, authentication schemes, and capability sets (vision, native document handling, tool calling).

Alternatives considered:
- **Direct provider SDK integration**: Fast to start, but locks the extension to one provider; adding a second requires forking the agent loop.
- **Custom abstraction inside `nr_mcp_agent`**: Duplicates work already done in `nr-llm`.
- **`netresearch/nr-llm`**: Existing Netresearch TYPO3 extension providing a provider-agnostic LLM interface, Task-based configuration, and capability interfaces (`DocumentCapableInterface`, `VisionCapableInterface`).

## Decision

Use `netresearch/nr-llm` as the sole LLM integration point. The extension references an `nr-llm` Task record (configured by the TYPO3 administrator) and delegates all LLM calls through its interface.

## Consequences

- Provider selection and credential management are handled by `nr-llm` and `nr-vault`; `nr_mcp_agent` has no provider-specific code.
- Capability detection (e.g. whether the provider supports native PDF handling) uses `nr-llm` interfaces, enabling the document extraction fallback (see ADR-013).
- The extension inherits `nr-llm`'s provider support: adding a new provider to `nr-llm` makes it available in `nr_mcp_agent` without changes.
- `nr-llm` is a hard dependency.
