# Architecture

## System Overview

`nr-mcp-agent` adds a conversational AI chat module to the TYPO3 backend. The user sends a message via a Lit web component; the web server persists it and delegates processing to a CLI command (`ai-chat:process` or `ai-chat:worker`). The CLI command runs the agent loop: it calls the configured LLM (via `nr-llm`), optionally invokes MCP tools (via `hn/typo3-mcp-server`), and writes the assistant reply back to the database. The browser polls for the reply and renders it.

## Component Map

| Component | Responsibility | Key Files |
|---|---|---|
| **Backend Module** | Chat UI (Admin Tools > AI Chat) | `Classes/Controller/`, `Resources/Private/Templates/` |
| **Floating Panel** | Toolbar chat widget, persistent across navigation | `Resources/Public/JavaScript/` (Lit) |
| **Agent Loop** | LLM call → tool use → reply, with retry logic | `Classes/Service/AgentLoopService.php` |
| **MCP Client** | Spawns `typo3-mcp-server`, handles stdio protocol | `Classes/Mcp/` |
| **Conversation Store** | Persists messages, pins, auto-archive | `Classes/Domain/Repository/` |
| **CLI Commands** | `ai-chat:process` (exec), `ai-chat:worker` (long-running) | `Classes/Command/` |
| **Access Control** | Group-based access, concurrency caps, length limits | `Classes/Service/AccessControlService.php` |

## Dependency Rules

Enforced via [PHPAt](https://github.com/carlosas/phpat) — runs automatically with PHPStan:

- `Domain` MUST NOT depend on `Controller` or `Command`
- `Controller` may depend on `Domain` and `Service`
- `Service` may depend on `Domain`; MUST NOT depend on `Controller`
- `Mcp` may depend on `Domain` and `Service`; MUST NOT depend on `Controller`
- `Tests` may depend on anything

Architecture tests: `Tests/Architecture/LayerDependencyTest.php`

## Data Flow

```
Browser (Lit)
  → POST /api/message
    → ConversationController::sendMessage()
      → persists Message (status: pending)
      → dispatches CLI: ai-chat:process <messageUid>
        → AgentLoopService::run()
          → nr-llm: LLM API call
          → [optional] McpClient: tool calls via typo3-mcp-server
          → persists assistant reply (status: done)
  ← GET /api/message/{uid}/poll
    ← returns reply when status = done
```

## Key Decisions

| Decision | Rationale | Date |
|---|---|---|
| CLI-based processing (not async HTTP) | Keeps web server responsive; no long-running PHP processes in FPM | 2024 |
| MCP via stdio subprocess | Reuses existing `hn/typo3-mcp-server` without modification | 2024 |
| Lit web components for UI | Framework-agnostic, no build step required in TYPO3 context | 2024 |
| nr-llm abstraction | Provider-agnostic (OpenAI, Anthropic, Gemini) via single interface | 2024 |
| Proof-of-concept scope | Validates agent-like behavior in TYPO3 backend; not a production product | 2024 |
