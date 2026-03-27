# ADR-014: Configurable MCP Server Registry with Auto-Init Default

**Status:** Accepted
**Date:** 2026-03-27

## Context

The original implementation used a single hardcoded MCP server configuration supplied via extension settings (`mcpServerCommand`, `mcpServerArgs`). This prevented:

- Running multiple MCP servers simultaneously (e.g. a TYPO3-specific server alongside a project-specific one)
- Distinguishing tool origins at the LLM prompt level
- Changing server configuration without deploying new extension settings

Alternatives considered:

- **Multiple extension settings entries**: Flat key/value pairs do not scale for N servers; no per-server enable/disable; no UI for reordering.
- **YAML/JSON file in fileadmin**: Flexible but requires filesystem access; no TYPO3 access control; not managed via the standard backend.
- **Database-driven registry**: Fits the TYPO3 record model; benefits from TCA-based editing, `hidden`/`deleted` flags, and sorting; manageable without CLI access.

## Decision

Introduce a `tx_nrmcpagent_mcp_server` database table. Each record represents one MCP server with fields for `server_key`, `transport` (`stdio`/`sse`), `command`, `arguments`, `url`, and `auth_token`. The `server_key` value is used as a prefix for all tool names from that server (`{server_key}__{tool_name}`), making the origin unambiguous in LLM tool calls and in the system prompt namespace hint.

`McpToolProvider` loads all active (non-hidden, non-deleted) records on each `getToolDefinitions()` call and manages one `McpConnection` per server key.

### Auto-initialisation of the default record

When `enableMcp=1` is configured but the registry table is empty, `McpToolProvider::getToolDefinitions()` calls `McpServerRepository::initDefault()`, which inserts a single default record (`server_key=typo3`, `transport=stdio`, `arguments=mcp:server`).

Alternatives considered for triggering this init:

- **`AfterExtensionConfigurationWriteEvent`**: Fires when an admin saves the extension configuration in the TYPO3 backend. Clean and intentional, but does not cover deployments where `enableMcp` is set via environment variable or `AdditionalConfiguration.php` — the event never fires in those cases.
- **Upgrade wizard**: TYPO3-native and visible, but requires manual admin action after every installation; inappropriate for a one-time default record.
- **Lazy init inside `getToolDefinitions()`**: The `findAllActive()` query already runs on every call; if the result is empty, `initDefault()` inserts the default record and `findAllActive()` is called once more. No extra SELECT is needed because the empty result from the first call is itself the existence check. Covers all deployment scenarios including image-based configs.

The lazy approach was chosen because it reliably handles both UI-driven and deployment-driven activation without requiring additional infrastructure or manual steps.

## Consequences

- Administrators can add, reorder, enable/disable, and delete MCP servers via the TYPO3 List module (root page, PID 0).
- New installations get a working default configuration automatically on first chat interaction when MCP is enabled.
- Tool name collisions across servers are prevented by the `server_key` prefix; the LLM receives a namespace hint in the system prompt.
- The `auth_token` field is stored as a TYPO3 password field (masked in the backend) but is excluded from the tool-list cache key to avoid leaking sensitive values into cache identifiers.
- SSE transport is reserved for a future implementation; selecting it currently raises a `RuntimeException`.
