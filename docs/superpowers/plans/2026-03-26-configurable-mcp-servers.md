# Implementation Plan: Configurable MCP Servers

## Task 1: DB schema + static data + cache config

**Files:**
- `ext_tables.sql` — add `tx_nrmcpagent_mcp_server` table
- `ext_tables_static+adt.sql` — default TYPO3 MCP server record
- `ext_localconf.php` — cache configuration for `nr_mcp_agent_tools`

**TDD:** Schema is declarative; no unit test. Verified by TCA + repository tests in later tasks.

## Task 2: McpServerRepository

**Files:**
- `Classes/Domain/Repository/McpServerRepository.php`
- `Tests/Unit/Domain/Repository/McpServerRepositoryTest.php`

**Methods:** `findAllActive(): array`, `updateConnectionStatus(int $uid, string $status, string $error): void`

## Task 3: TCA for tx_nrmcpagent_mcp_server

**Files:**
- `Configuration/TCA/tx_nrmcpagent_mcp_server.php`

**TDD:** TCA is declarative; validated by PHPStan + manual inspection.

## Task 4: McpToolProvider rewrite

**Files:**
- `Classes/Mcp/McpToolProvider.php` — multi-server, caching, prefixed tool names
- `Tests/Unit/Mcp/McpToolProviderTest.php` — rewrite tests

**Key changes:**
- Constructor takes `ExtensionConfiguration`, `McpServerRepository`, `CacheInterface`
- `connect()` = no-op (backward compat)
- `getToolDefinitions()` loops active servers, cache hit/miss, populates `$toolIndex`
- `executeTool()` routes via `$toolIndex`, lazy connection open
- `disconnect()` closes all, clears state, does NOT flush cache
- Tool names: `{server_key}__{original_name}`

## Task 5: ChatService updates

**Files:**
- `Classes/Service/ChatService.php`
- `Tests/Unit/Service/ChatServiceToolLoopTest.php`

**Changes:**
- Remove `$this->mcpToolProvider->connect()` call
- Remove `$this->config->isMcpServerInstalled()` guard
- Inject `McpServerRepository`, add namespace hints to system prompt
- `mcpEnabled` now just checks `$this->config->isMcpEnabled()`

## Task 6: ExtensionConfiguration cleanup

**Files:**
- `Classes/Configuration/ExtensionConfiguration.php` — remove `getMcpServerCommand`, `getMcpServerArgs`, `isMcpServerInstalled`; add `hasLegacyMcpFields()`
- `ext_conf_template.txt` — remove `mcpServerCommand`, `mcpServerArgs`
- `Configuration/Services.yaml` — update if needed

## Task 7: Architecture tests + DataHandler hook

**Files:**
- `Classes/Hook/McpServerCacheFlushHook.php`
- `ext_localconf.php` — register hook
- `Tests/Architecture/LayerDependencyTest.php` — add Hook layer rule
- `Build/phpstan/phpstan.neon` — no changes needed (Hook is under Classes/)
