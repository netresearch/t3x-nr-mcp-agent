# AI Chat for TYPO3 (`nr_mcp_agent`)

[![CI](https://github.com/netresearch/t3x-nr-mcp-agent/actions/workflows/ci.yml/badge.svg)](https://github.com/netresearch/t3x-nr-mcp-agent/actions)
<!-- [![Latest Stable Version](https://poser.pugx.org/netresearch/nr-mcp-agent/v)](https://packagist.org/packages/netresearch/nr-mcp-agent) -->

> [!NOTE]
> **Proof of concept.** This extension explores a concrete question: is agent-like behavior possible within the TYPO3 backend? It is not intended to answer whether this is the right architectural approach — the space is moving fast, and the tradeoffs between MCP, tool-calling, browser-side agents, and custom integrations are far from settled. The goal here is to show that it *works*, and to invite feedback from anyone thinking about the same problem. If you have thoughts, [open an issue](https://github.com/netresearch/t3x-nr-mcp-agent/issues).

AI Chat integrates a conversational AI assistant into the TYPO3 backend.
Powered by [nr-llm](https://github.com/netresearch/t3x-nr-llm) and the
[Model Context Protocol (MCP)](https://modelcontextprotocol.io/), it enables
backend users to manage content through natural language.

## Features

- **Integrated chat module** -- A dedicated backend module under Admin Tools with
  a modern chat interface built as a Lit web component.
- **Content management via MCP** -- Connect to hn/typo3-mcp-server to give the AI
  access to TYPO3 content operations (pages, records, content elements).
- **Conversation history** -- Persistent conversations with resume, pin, and
  auto-archive support.
- **Background processing** -- Messages are processed via CLI commands (`exec` or
  `worker` mode), keeping the web server responsive.
- **Floating chat panel** -- A toolbar-triggered bottom panel that stays visible
  across module navigation, allowing users to chat while working in the page tree.
- **Markdown rendering** -- LLM responses are rendered as rich Markdown (headings,
  lists, code blocks, tables) using vendored [marked.js](https://marked.js.org/) v15
  and [DOMPurify](https://github.com/cure53/DOMPurify) v3. No build step required.
- **Secure by design** -- Group-based access control, message length limits,
  concurrency caps, sanitized error messages, and XSS-safe Markdown rendering.

## Quick Start

1. Install the extension:

   ```bash
   composer require netresearch/nr-mcp-agent
   vendor/bin/typo3 database:updateschema
   ```

2. In nr-llm, create a **Task** record that configures your LLM provider
   (e.g. OpenAI, Anthropic). Note the UID.

3. Go to **Admin Tools > Settings > Extension Configuration > nr_mcp_agent** and
   set `llmTaskUid` to the Task UID from step 2.

The AI Chat module is now available under **Admin Tools > AI Chat**.

### Enable MCP (optional)

```bash
composer require hn/typo3-mcp-server
```

Then set `enableMcp = 1` in the extension configuration.

## DDEV Development

```bash
git clone https://github.com/netresearch/t3x-nr-mcp-agent.git
cd t3x-nr-mcp-agent
ddev start
ddev composer install
ddev typo3 database:updateschema
```

Run quality checks:

```bash
ddev composer ci             # All checks (PHPStan + CGL + tests)
ddev composer ci:phpstan     # Static analysis (includes architecture tests)
ddev composer ci:cgl         # Code style check
ddev composer ci:tests:unit  # Unit tests only
ddev composer ci:tests       # Unit + functional tests
ddev composer ci:mutation    # Mutation testing (Infection)
ddev composer fix:cgl        # Fix code style
```

Run JavaScript unit tests (Jest):

```bash
npm install
npm run test:js
```

For Docker-based testing that mirrors CI exactly (no DDEV required):

```bash
./Build/Scripts/runTests.sh -s unit        # Unit tests
./Build/Scripts/runTests.sh -s phpstan     # PHPStan
./Build/Scripts/runTests.sh -s cgl         # Code style check
./Build/Scripts/runTests.sh -s mutation    # Mutation testing
./Build/Scripts/runTests.sh -s unit -p 8.3 # Specific PHP version
```

## Configuration

All settings are in **Admin Tools > Settings > Extension Configuration > nr_mcp_agent**:

| Setting | Default | Description |
|---------|---------|-------------|
| `llmTaskUid` | `0` | UID of the nr-llm Task record **(required)** |
| `processingStrategy` | `exec` | `exec` (fork per request) or `worker` (long-running) |
| `allowedGroups` | *(empty)* | Comma-separated group UIDs (empty = all) |
| `enableMcp` | `false` | Enable MCP server integration |
| `maxMessageLength` | `10000` | Max characters per message |
| `maxActiveConversationsPerUser` | `3` | Max concurrent processing conversations |
| `maxConversationsPerUser` | `50` | Max conversations kept per user |
| `autoArchiveDays` | `30` | Auto-archive after N days of inactivity |

For the full documentation, see the [Documentation/](Documentation/) folder or
the rendered docs on [docs.typo3.org](https://docs.typo3.org/).

## Acknowledgments

- **[hauptsache.net](https://hauptsache.net/)** -- For creating
  [hn/typo3-mcp-server](https://github.com/hauptsache-net/typo3-mcp-server),
  the MCP server that exposes TYPO3 content operations as tools.
- **[nr-llm](https://github.com/netresearch/t3x-nr-llm)** -- The Netresearch
  LLM abstraction layer for TYPO3.
- **[nr-vault](https://github.com/netresearch/t3x-nr-vault)** -- Secure
  credential storage for TYPO3.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) for details.
