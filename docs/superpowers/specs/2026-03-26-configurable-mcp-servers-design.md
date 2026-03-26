# Configurable MCP Servers — Design Spec

## Goal

Replace the single hardcoded TYPO3 MCP server (configured via Extension Configuration) with a
database-backed registry of MCP servers. Admins can define multiple MCP servers (stdio or SSE),
each identified by a unique key that becomes the namespace prefix for its tools.

## Context

The extension currently supports exactly one MCP server, configured via three Extension
Configuration fields (`enableMcp`, `mcpServerCommand`, `mcpServerArgs`). This works for the
bundled TYPO3 MCP server but makes it impossible to connect additional MCPs (e.g. a WordPress
MCP, a Google Drive MCP, or any internal tool server).

**Existing code changes:**
- `ChatService` — two small changes required:
  1. Remove the explicit `$this->mcpToolProvider->connect()` call (connections are opened lazily)
  2. Remove the `isMcpServerInstalled()` guard (see below)
- `McpConnection` — handles a single stdio or SSE connection; reused as-is.
- `McpToolProviderInterface` — `connect()` is kept as a no-op for backwards compatibility but
  deprecated; `disconnect()` still called by `ChatService` at end of processing.
- `ExtensionConfiguration::isMcpEnabled()` / `enableMcp` — kept as a global on/off switch.

**`isMcpServerInstalled()` guard — removal rationale:**
`ChatService` currently guards tool use with `$this->config->isMcpServerInstalled()`, which
checks whether the `hn/typo3-mcp-server` TYPO3 extension is loaded. With a multi-server
registry this check is meaningless — MCPs may be external processes with no TYPO3 extension.
The guard is replaced by the simpler rule: if `enableMcp = 1` and at least one active record
exists in the table, tools are offered. `McpToolProvider::getToolDefinitions()` returns `[]`
if no active servers are configured.

**Removed from Extension Configuration:**
- `mcpServerCommand` — moved to DB table
- `mcpServerArgs` — moved to DB table

---

## Architecture

### Data Flow

```
enableMcp == 0
  → McpToolProvider::getToolDefinitions() returns []  (no DB query, no connections)

enableMcp == 1
  → McpToolProvider::getToolDefinitions()
      for each hidden=0, deleted=0 record in tx_nrmcpagent_mcp_server:
        cache hit?  → tool list from cache; populate $toolIndex from cached data
        cache miss? → McpConnection::open(), tools/list, write to cache,
                      connection stays open in $connections[]; populate $toolIndex
      return flat list with prefixed names: '{server_key}__{tool_name}'

  → ChatService passes tool list to LLM as usual

  → LLM responds with tool_call: {name: 'typo3__create_page', ...}

  → McpToolProvider::executeTool('typo3__create_page', $input)
      $toolIndex['typo3__create_page'] → server_key = 'typo3'
      $connections['typo3'] open? → call tools/call
      not open?                   → open connection lazily, then call tools/call

  → McpToolProvider::disconnect()
      close all connections
      clear $connections and $toolIndex
      (cache NOT cleared — persists in cache framework)
```

**Important:** `$toolIndex` is always populated during `getToolDefinitions()`, regardless of
whether tools came from cache or a live `tools/list` call. This ensures `executeTool()` can
always route correctly, even on a pure cache-hit path.

---

## Components

### 1. Database Table: `tx_nrmcpagent_mcp_server`

```sql
CREATE TABLE tx_nrmcpagent_mcp_server (
    uid                int(11) unsigned NOT NULL AUTO_INCREMENT,
    pid                int(11) unsigned DEFAULT 0 NOT NULL,
    deleted            smallint(5) unsigned DEFAULT 0 NOT NULL,
    hidden             smallint(5) unsigned DEFAULT 0 NOT NULL,
    sorting            int(11) DEFAULT 0 NOT NULL,

    name               varchar(255) DEFAULT '' NOT NULL,
    server_key         varchar(64)  DEFAULT '' NOT NULL,
    transport          varchar(10)  DEFAULT 'stdio' NOT NULL,

    -- stdio fields
    command            varchar(1000) DEFAULT '' NOT NULL,
    arguments          text,

    -- sse fields
    url                varchar(2000) DEFAULT '' NOT NULL,
    auth_token         text,

    -- connection health (written by McpToolProvider, read-only in TCA)
    connection_status  varchar(20) DEFAULT 'unknown' NOT NULL,
    connection_checked int(11) unsigned DEFAULT 0 NOT NULL,
    connection_error   text,

    PRIMARY KEY (uid),
    UNIQUE KEY server_key (server_key),
    KEY hidden_deleted (hidden, deleted)
);
```

**Field notes:**
- `server_key`: `[a-z0-9_]+` (non-empty), globally unique (DB `UNIQUE KEY` + TCA `eval=unique,required`)
- `sorting`: signed `int(11)` — consistent with TYPO3 core convention
- `hidden`: standard TYPO3 enable/disable toggle
- `arguments`: one argument per line (avoids comma-escaping issues with args containing commas)
- `command` empty → `McpToolProvider` falls back to `Environment::getProjectPath() . '/vendor/bin/typo3'`
- `auth_token`: Bearer token for SSE; empty = no auth. Stored as plain text in DB (see Security section).
- `connection_status`: written back by `McpToolProvider` after each connect attempt; `'ok'|'error'|'unknown'`
- `connection_checked`: Unix timestamp of last connect attempt
- `connection_error`: human-readable error message when `connection_status = 'error'`

### 2. Static Data: `ext_tables_static+adt.sql`

Ships a pre-configured, enabled entry for the TYPO3 MCP server:

```sql
INSERT INTO tx_nrmcpagent_mcp_server
    (uid, pid, name, server_key, transport, command, arguments, hidden, sorting)
VALUES
    (1, 0, 'TYPO3 MCP Server', 'typo3', 'stdio', '', 'mcp:server', 0, 1);
```

Omitted columns (`url`, `auth_token`, `connection_status`, `connection_checked`,
`connection_error`) fall back to their DB column defaults. `command` is intentionally empty
so `McpToolProvider` resolves the project path at runtime. The entry is `hidden = 0` (active)
but only takes effect when `enableMcp = 1`.

### 3. Tool List Cache

Registered in `ext_localconf.php`:

```php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nr_mcp_agent_tools'] ??= [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend'  => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
    'options'  => ['defaultLifetime' => 3600],
    'groups'   => ['system'],
];
```

Admins can override the backend to Redis, APCu, or any other TYPO3 cache backend via
`TYPO3_CONF_VARS`. This is the standard TYPO3 pattern and requires no extension changes.

**Cache key:** `mcp_tools_{server_key}_{md5(command|arguments|url|auth_token)}`

The hash component ensures the cache is automatically invalidated if the server config changes,
even without a DataHandler hook firing (e.g. direct DB edits in development).

**Cache invalidation on record save:** A `DataHandlerHook` flushes the cache entry by key
(`mcp_tools_{server_key}_*`) when a record is saved via the backend. Tag-based flushing is
avoided to keep the implementation portable across cache backends that do not implement
`TaggableFrontendInterface`; key-based deletion via `CacheFrontendInterface::remove()` is
sufficient and universally supported.

### 4. `McpToolProvider` — updated internals

```php
/** @var array<string, McpConnection> */
private array $connections = [];

/** @var array<string, string> prefixed tool name → server_key */
private array $toolIndex = [];
```

**`connect()`** remains on the interface as a no-op for backwards compatibility. `ChatService`
will be updated to remove the explicit `connect()` call; connections are opened lazily inside
`getToolDefinitions()` and `executeTool()`.

**`getToolDefinitions()`:** loads all `hidden=0, deleted=0` records via DBAL; for each:
- Checks cache by key. **On hit or miss**, populates `$toolIndex` from the tool list
  (prefixed names → server_key). On miss only: opens `McpConnection`, calls `tools/list`,
  writes to cache, keeps connection in `$connections[]`, writes `connection_status` to DB.
  On connection error: logs, sets `connection_status = 'error'`, skips the server.

**`executeTool(string $toolName, array $input)`:** looks up `$toolIndex[$toolName]` to find
the server key. If `$connections[$serverKey]` is not open (cache-hit path), opens it lazily.
Calls `tools/call` on the correct connection.

**`disconnect()`:** closes all open connections, clears `$connections` and `$toolIndex`.
Does **not** flush the cache.

**Tool name prefixing:** `{server_key}__{original_tool_name}`. Applied in both
`getToolDefinitions()` (outgoing to LLM) and resolved in `executeTool()` (routing).
Collision between server keys is prevented by the `UNIQUE KEY` on `server_key`.

### 5. System Prompt Addition

One line per active MCP server is appended to the system prompt:

```
Available tools are namespaced by source: typo3__* for TYPO3 CMS operations.
```

If multiple MCPs are active:

```
Available tools are namespaced by source: typo3__* for TYPO3 CMS, wordpress__* for WordPress.
```

This helps the LLM reason about which tool family to use when tool names are ambiguous.

### 6. TCA — `tx_nrmcpagent_mcp_server`

- `adminOnly = true`, `rootLevel = 1` — consistent with `tx_nrmcpagent_conversation`
- `type` field on `transport`: shows stdio fields (`command`, `arguments`) or SSE fields
  (`url`, `auth_token`) depending on selection
- `server_key`: `eval = 'trim,unique,lower'`, `required = true`, regex validation `[a-z0-9_]+`
- `connection_status`: read-only, rendered with a custom `fieldWizard` showing a green/red
  indicator and the timestamp of the last check
- `auth_token`: `eval = 'password'` to prevent casual exposure in the backend UI

No dedicated backend module needed — records are managed via the List module on PID 0,
which admins already know from other `rootLevel` tables.

### 7. Extension Configuration — changes

**Removed:**
```
mcpServerCommand
mcpServerArgs
```

**Kept:**
```
enableMcp   # global on/off switch
```

**Migration:** On extension update, `ExtensionConfiguration` checks whether the removed fields
are still set in `LocalConfiguration.php`. If so, it logs a `FlashMessage` warning in the
backend informing the admin that these fields are no longer read and the TYPO3 MCP Server entry
in the List module should be verified. No automatic data migration.

---

## Error Handling

| Situation | Behaviour |
|-----------|-----------|
| `enableMcp = 0` | `getToolDefinitions()` returns `[]` immediately, no DB/cache access |
| MCP server record `hidden = 1` | Skipped silently |
| No active records in table | `getToolDefinitions()` returns `[]`; no tools offered to LLM |
| Connection failure (stdio/SSE) | Log error, set `connection_status = 'error'`, skip server, other MCPs continue |
| `executeTool` on unconnected server | Returns `{"error": "MCP server '{key}' not connected"}` — LLM sees it as tool result |
| Duplicate `server_key` in DB | Prevented by `UNIQUE KEY`; TCA `eval=unique` shows error on save |
| Empty `server_key` | Prevented by TCA `required = true` and DB default not accepted on save |
| Old `mcpServerCommand` still set | FlashMessage warning in backend after update |
| Cache backend unavailable | Falls through to live `tools/list` call (TYPO3 cache framework handles gracefully) |

---

## Performance

Known limits with many MCPs and large tool bases:

- **Token cost:** all active tool definitions are sent to the LLM on every request. Grows
  linearly with number of MCPs × tools per MCP. Accepted for now; selective tool passing
  is out of scope.
- **stdio process startup:** one process per MCP server, started lazily only when
  `executeTool` is first called. `getToolDefinitions()` is served from cache after the
  first request — no process startup for prompt construction on subsequent requests.
- **SSE connection overhead:** one HTTP connection per SSE server, opened lazily.

Recommended limit for production: ≤ 5 MCP servers, ≤ 30 tools per server. Document this
in the extension README.

---

## Security

- Table is `adminOnly` — only TYPO3 admins can create/edit MCP server records.
- `proc_open` uses array form `[$command, ...$args]` — no shell injection possible.
- `auth_token` is rendered as a password field in TCA (not shown in plain text in the UI).
  **Known limitation:** tokens are stored as plain text in the DB. Encryption at rest
  (e.g. via `nr-vault`) is out of scope for this iteration but should be considered if
  tokens grant access to sensitive external services.
- Extension Configuration `enableMcp` provides a hard off-switch independent of individual
  server records.

---

## Out of Scope

- OAuth / token refresh flows
- Encryption at rest for `auth_token`
- Per-conversation or per-user-group MCP selection
- MCP server health monitoring / alerting
- Tool filtering (passing only a subset of tools to the LLM)
- A dedicated backend module (List module on PID 0 is sufficient)
