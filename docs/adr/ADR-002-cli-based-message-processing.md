# ADR-002: CLI-Based Message Processing

**Status:** Accepted
**Date:** 2026-03-14

## Context

Processing an AI chat message involves: calling the LLM API (seconds to tens of seconds), potentially executing multiple MCP tool calls (each spawning a subprocess), and looping until the agent produces a final reply. This cannot complete within a reasonable HTTP request timeout and would block a PHP-FPM worker for the entire duration.

Alternatives considered:
- **Synchronous HTTP response**: Ties up an FPM worker; times out on slow LLMs or long tool chains.
- **Async HTTP (ReactPHP/Swoole)**: Requires a non-standard PHP runtime; incompatible with most TYPO3 hosting environments.
- **Queue system (RabbitMQ, Redis Queue)**: Adds external infrastructure dependencies.
- **CLI subprocess**: PHP CLI has no timeout constraints; uses no FPM workers during processing.

## Decision

Process messages via CLI commands, dispatched by the web server:

- **`exec` mode**: The web request forks a `ai-chat:process <messageUid>` subprocess per message and returns immediately.
- **`worker` mode**: A long-running `ai-chat:worker` process polls for pending messages. Suitable for environments where forking per request is undesirable.

Both modes write the assistant reply back to the database. The browser polls for completion.

## Consequences

- Web server stays responsive regardless of LLM latency or tool chain depth.
- No external queue infrastructure required.
- `exec` mode requires `proc_open` / `shell_exec` to be available.
- `worker` mode requires a process supervisor (systemd, supervisor) to keep the worker alive.
- The processing strategy is configurable via extension configuration.
