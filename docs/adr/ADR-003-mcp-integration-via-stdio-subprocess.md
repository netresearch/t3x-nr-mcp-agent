# ADR-003: MCP Integration via stdio Subprocess

**Status:** Accepted
**Date:** 2026-03-14

## Context

`hn/typo3-mcp-server` implements the Model Context Protocol over stdio. It is designed to be launched as a subprocess by an MCP host. The MCP specification also defines HTTP+SSE as a transport option.

Alternatives considered:
- **HTTP+SSE transport**: Would require `hn/typo3-mcp-server` to run as a persistent HTTP server, adding deployment complexity and changing its operational model.
- **Direct PHP function calls**: Would require forking or reimplementing the MCP server logic inside `nr_mcp_agent`, coupling the two extensions tightly.
- **stdio subprocess**: Uses `hn/typo3-mcp-server` exactly as designed, with zero modifications.

## Decision

Connect to `hn/typo3-mcp-server` by spawning it as a stdio subprocess. `McpConnection` manages the process lifecycle (start, communication, shutdown). `McpToolProvider` translates between the agent loop and the MCP protocol.

## Consequences

- `hn/typo3-mcp-server` is used without modification.
- The MCP connection is process-local: each CLI processing job spawns its own MCP server instance.
- The stdio transport is synchronous within the agent loop, which is sufficient given that processing already runs in a CLI subprocess (see ADR-002).
- MCP is an optional dependency: if `hn/typo3-mcp-server` is not installed, the extension works without tool-calling capability.
