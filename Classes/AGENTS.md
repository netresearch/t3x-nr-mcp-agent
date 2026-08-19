<!-- Managed by agent: keep sections and order; edit content, not structure -->
<!-- Last updated: 2026-08-19 -->

# AGENTS.md — Classes/

## Overview

PHP source of the extension, namespace `Netresearch\NrMcpAgent\` (PSR-4 from `Classes/`). Key layers: `Domain/` (entities, repositories, enums via `Enum/`), `Service/` (chat processing: `ChatService`, `ExecChatProcessor`, `WorkerChatProcessor`), `Controller/` (AJAX endpoints), `Command/` (CLI: `ProcessChatCommand`, `ChatWorkerCommand`, `CleanupCommand`), `Mcp/` (MCP stdio client), `Document/` (text extractors for uploads), plus `Backend/`, `Checker/`, `Configuration/`, `Hook/`, `Utility/`, `Exception/`.

## Setup

- Dependency injection via `../Configuration/Services.yaml` — namespace-wide autowiring plus explicit per-service overrides (processor default, tool-provider cache, tagged document extractors); no `GeneralUtility::makeInstance()` for own services
- AJAX routes live in `../Configuration/Backend/AjaxRoutes.php`, backend modules in `../Configuration/Backend/Modules.php`
- `declare(strict_types=1)` in every file

## Build & Tests

- Static analysis: `make phpstan` (PHPStan level 10, config `Build/phpstan/phpstan.neon`)
- Style: `make lint` / `make lint-fix` (PHP-CS-Fixer, `.php-cs-fixer.dist.php`)
- Every class change: matching test in `../Tests/Unit/<Layer>/` (mirrors this directory structure)

## Code style

- PHPStan level 10 — narrow `mixed` with `is_string()`/`is_array()` instead of casting; fix types, do not suppress
- Layering enforced by phpat (runs with PHPStan): Domain must not depend on Controller/Command/Mcp; Services must not use `ConnectionPool` directly (use repositories); Controllers and Hooks must not depend on Mcp; see `../docs/ARCHITECTURE.md`
- LLM error messages are sanitized before persisting (`error_message` must never contain API keys — ADR-010)

## Security

- All chat endpoints go through group-based access control (ADR-009) — do not add routes that bypass it
- `$GLOBALS['BE_USER']` is set intentionally in CLI workers (TYPO3 CLI auth flow) — never remove
- MCP servers run as stdio subprocesses (`Mcp/McpConnection`): treat tool output as untrusted input
- File uploads are validated for MIME type and size in the controller — keep validation when touching upload code

## Checklist

- [ ] `make lint` clean, `make phpstan` clean (phpat rules included)
- [ ] Unit test added/updated under `../Tests/Unit/`
- [ ] `Documentation/` updated when behavior or config changes
- [ ] Conventional Commit, signed (`git commit -S --signoff`)

## Examples

- Service with DI + interface split: `Service/ChatService.php`, `Service/ChatProcessorInterface.php`
- State handling via enum: `Enum/` + `Domain/` conversation status (ADR-005)
- Optional-dependency guard: `Document/Extractor/XlsxExtractor.php` (checks `isAvailable()` before using phpspreadsheet)

## When stuck

- Component map and dependency rules: `../docs/ARCHITECTURE.md`
- Design rationale: `../Documentation/Developer/ADR/` (ADR-001…014)
- Agent loop details: `../Documentation/Developer/AgentLoop.rst`
