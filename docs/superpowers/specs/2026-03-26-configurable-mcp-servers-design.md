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

**Existing code that remains unchanged:**
- `ChatService` — calls only `McpToolProviderInterface::getToolDefinitions()` and `executeTool()`;
  it never knows how many MCPs are connected or where they come from.
- `McpConnection` — handles a single stdio or SSE connection; reused as-is.
- `ExtensionConfiguration::isMcpEnabled()` / `enableMcp` — kept as a global on/off switch.

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
      for each enabled tx_nrmcpagent_mcp_server record:
        cache hit?  → tool list from cache (no connection)
        cache miss? → McpConnection::open(), tools/list, write to cache
                      connection stays open in $connections[]
      return flat list with prefixed names: '{server_key}__{tool_name}'

  → ChatService passes tool list to LLM as usual

  → LLM responds with tool_call: {name: 'typo3__create_page', ...}

  → McpToolProvider::executeTool('typo3__create_page', $input)
      split on '__' → server_key = 'typo3'
      $connections['typo3'] open? → call tools/call
      not open?                   → connect(), then call tools/call

  → McpToolProvider::disconnect()
      close all connections
      clear toolIndex
      (cache NOT cleared — persists in cache framework)
```

---

## Components

### 1. Database Table: `tx_nrmcpagent_mcp_server`

```sql
CREATE TABLE tx_nrmcpagent_mcp_server (
    uid                int(11) unsigned NOT NULL AUTO_INCREMENT,
    pid                int(11) unsigned DEFAULT 0 NOT NULL,
    deleted            smallint(5) unsigned DEFAULT 0 NOT NULL,
    hidden             smallint(5) unsigned DEFAULT 0 NOT NULL,
    sorting            int(11) unsigned DEFAULT 0 NOT NULL,

    name               varchar(255) DEFAULT '' NOT NULL,
    server_key         varchar(64)  DEFAULT '' NOT NULL,
    transport          varchar(10)  DEFAULT 'stdio' NOT NULL,

    -- stdio fields
    command            varchar(1000) DEFAULT '' NOT NULL,
    arguments          text,

    -- sse fields
    url                varchar(2000) DEFAULT '' NOT NULL,
    auth_token         text,

    -- connection health
    connection_status  varchar(20) DEFAULT 'unknown' NOT NULL,
    connection_checked int(11) unsigned DEFAULT 0 NOT NULL,
    connection_error   text,

    PRIMARY KEY (uid),
    UNIQUE KEY server_key (server_key),
    KEY hidden_deleted (hidden, deleted)
);
```

**Field notes:**
- `server_key`: `[a-z0-9_]` only, globally unique (DB constraint + TCA `eval=unique`)
- `hidden`: standard TYPO3 enable/disable toggle
- `arguments`: one argument per line (avoids comma-escaping issues)
- `command` empty → `McpToolProvider` falls back to `Environment::getProjectPath() . '/vendor/bin/typo3'`
- `auth_token`: Bearer token for SSE; empty = no auth
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

`command` is intentionally empty so `McpToolProvider` resolves the path at runtime via
`Environment::getProjectPath()`. The entry is `hidden = 0` (active) but only takes effect
when `enableMcp = 1` is set by the admin.

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
even without the DataHandler hook firing (e.g. direct DB edits in development).

**DataHandler hook** additionally flushes by tag `mcp_server_{server_key}` when a record is
saved via the backend — provides instant invalidation for normal admin workflows.

### 4. `McpToolProvider` — updated internals

```php
/** @var array<string, McpConnection> */
private array $connections = [];

/** @var array<string, string> tool name (prefixed) → server_key */
private array $toolIndex = [];
```

**`connect()`** is removed as a public entry point. Connections are opened lazily:

- `getToolDefinitions()`: loads all `hidden=0, deleted=0` records via DBAL; for each record,
  checks cache first; on miss, opens a `McpConnection`, calls `tools/list`, writes to cache,
  keeps connection in `$connections[]`. Writes `connection_status` back to DB.
  On any connection error: logs, sets `connection_status = 'error'`, skips the server.

- `executeTool(string $toolName, array $input)`: splits `$toolName` on the first `__`,
  resolves the server key via `$toolIndex`, checks `$connections[]`. If not open (cache-hit
  path), opens the connection lazily. Calls `tools/call` on the correct connection.

- `disconnect()`: closes all open connections, clears `$connections` and `$toolIndex`.
  Does **not** flush the cache.

**Tool name prefixing:** `{server_key}__{original_tool_name}`. Both `getToolDefinitions()`
(for the tool list sent to the LLM) and `executeTool()` (for routing) use the same convention.
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
- `server_key`: `eval = 'trim,unique,lower'`, regex validation `[a-z0-9_]+`
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
| Connection failure (stdio/SSE) | Log error, set `connection_status = 'error'`, skip server, other MCPs continue |
| `executeTool` on failed server | Returns `{"error": "MCP server '{key}' not connected"}` — LLM sees it as tool result |
| Duplicate `server_key` in DB | Prevented by `UNIQUE KEY`; TCA `eval=unique` shows error on save |
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
  first request — no process startup for prompt construction.
- **SSE connection overhead:** one HTTP connection per SSE server, opened lazily.

Recommended limit for production: ≤ 5 MCP servers, ≤ 30 tools per server. Document this
in the extension README.

---

## Security

- Table is `adminOnly` — only TYPO3 admins can create/edit MCP server records.
- `proc_open` uses array form `[$command, ...$args]` — no shell injection possible.
- `auth_token` rendered as password field in TCA — not shown in plain text in the backend.
- Extension Configuration `enableMcp` provides a hard off-switch independent of individual
  server records.

---

## Out of Scope

- OAuth / token refresh flows
- Per-conversation or per-user-group MCP selection
- MCP server health monitoring / alerting
- Tool filtering (passing only a subset of tools to the LLM)
- A dedicated backend module (List module on PID 0 is sufficient)
