# ADR-007: Polling over WebSockets or SSE

**Status:** Accepted
**Date:** 2026-03-15

## Context

The browser needs to know when the CLI worker has finished processing a message. Three push/pull patterns were considered:

- **WebSockets**: Bidirectional, low-latency — but requires a persistent connection, a compatible server (not standard FPM), and non-trivial infrastructure.
- **Server-Sent Events (SSE)**: Server-push, lightweight — but holds an HTTP connection open per conversation, which is problematic under FPM connection limits and incompatible with the CLI-based processing model (the SSE endpoint cannot receive events from a separate process without a shared message bus).
- **Polling**: The browser calls `GET /api/message/{uid}/poll` on an interval until status is `done`. Stateless, FPM-compatible, no persistent connections.

## Decision

Use polling. The browser polls the message status endpoint every 1.5 seconds while a message is processing. The endpoint is optimized for minimal overhead (single indexed lookup by UID and status).

## Consequences

- No persistent connections, no external message bus, no special server requirements.
- Response latency is bounded by the poll interval (~1.5 s), which is acceptable for a backend content management tool.
- Under load, polling generates additional HTTP requests. The per-request overhead is low (indexed DB query, no session), and the poll stops immediately when the message reaches a terminal state.
- If future requirements demand lower latency, the polling endpoint can be replaced with SSE without changes to the processing layer.
