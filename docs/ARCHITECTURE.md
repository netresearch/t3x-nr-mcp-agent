# Architecture (agent-facing)

Component map for AI agents working on `nr_mcp_agent`. Canonical prose documentation lives in `Documentation/Developer/Architecture.rst`; this file is the quick, verified index.

## System overview

A Lit-based chat UI in the TYPO3 backend polls `ChatApiController` via AJAX. Messages are persisted through `ConversationRepository` (`tx_nrmcpagent_conversation`) and processed in CLI context — either a forked `ai-chat:process` run or the long-running `ai-chat:worker` — where `ChatService` resolves the nr-llm Task/Configuration, builds the system prompt, and hands the turn to nr-llm's `AgentRuntime` (LLM calls plus tool execution). There are no WebSocket or SSE connections; the frontend communicates exclusively by polling.

## Components

| Component | Responsibility | Key files |
|-----------|----------------|-----------|
| API controller | AJAX endpoints: poll, send, file upload | `Classes/Controller/ChatApiController.php` |
| Backend module | Full-page chat UI (Admin Tools > AI Chat) | `Classes/Controller/ChatModuleController.php`, `Resources/Private/Templates/Chat/Index.html` |
| Floating panel | Toolbar chat widget, persistent across navigation | `Classes/Backend/ToolbarItems/ChatToolbarItem.php`, `Resources/Public/JavaScript/toolbar/chat-panel.js` |
| Chat service | Task/Configuration resolution, prompt building, LLM message assembly | `Classes/Service/ChatService.php` |
| Processors | Dispatch a turn to CLI: fork (`exec`) or queue (worker) | `Classes/Service/ExecChatProcessor.php`, `Classes/Service/WorkerChatProcessor.php`, `Classes/Service/ChatProcessorInterface.php` |
| CLI commands | `ai-chat:process`, `ai-chat:worker`, `ai-chat:cleanup` | `Classes/Command/ProcessChatCommand.php`, `Classes/Command/ChatWorkerCommand.php`, `Classes/Command/CleanupCommand.php` |
| Conversation store | Persistence, state machine, auto-archive | `Classes/Domain/Model/Conversation.php`, `Classes/Domain/Repository/ConversationRepository.php` |
| Document extraction | Text extraction from uploaded PDF/DOCX/XLSX/TXT | `Classes/Document/DocumentExtractorRegistry.php`, `Classes/Document/Extractor/` |
| Frontend | Lit web components, polling API client, sanitized markdown | `Resources/Public/JavaScript/` (`chat-app.js`, `chat-core.js`, `api-client.js`, `markdown.js`) |

## Dependency rules

Enforced by phpat; the rules run as part of PHPStan (`Build/phpstan/phpstan.neon` registers the test classes as `phpat.test` services), so `make phpstan` fails on violations. Rule sources: `Tests/Architecture/LayerDependencyTest.php` and `Tests/Architecture/DocumentExtractorArchitectureTest.php`.

- `Domain` MUST NOT depend on `Controller`, `Command`, or `Mcp` (infrastructure)
- `Service` MUST NOT depend on `TYPO3\CMS\Core\Database\ConnectionPool` — use repositories
- `Controller` MUST NOT depend on `Mcp`
- `Hook` MUST NOT depend on `Controller`, `Mcp`, or `Service`
- `Document` MUST NOT depend on `Service\ChatService` or `Controller` — extractors stay pure, reusable utilities

## Data flow

1. User sends a message → `ChatApiController` validates (access control, length, file limits) and enqueues it on the conversation
2. A processor starts CLI processing (`exec` fork or worker dequeue); the conversation moves `idle → processing`
3. `ChatService` builds the LLM message list (attachments become base64 images or extracted text) and runs nr-llm's `AgentRuntime`
4. The reply is persisted; the conversation returns to `idle` (or `failed` on error; `ai-chat:cleanup` fails conversations stuck > 5 min)
5. The frontend polls and renders the sanitized result

## Key decisions

Recorded as ADRs in `Documentation/Developer/ADR/` (ADR-001 … ADR-014). Most load-bearing for code changes: CLI-based processing (ADR-002), nr-llm as abstraction layer (ADR-004), conversation state machine (ADR-005), phpat-enforced layering (ADR-006), polling over WebSockets/SSE (ADR-007), Lit without build step (ADR-008), group-based access control (ADR-009), error-message sanitization (ADR-010).
