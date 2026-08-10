# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.9.0] - 2026-08-10

### Added

- The chat empty state carries the primary action and an explanation instead of
  the bare sentence "Select or start a chat". The only way to start one was an
  icon-only button in the sidebar header, so the screen named a choice and hid
  both of its options. Both surfaces get it, the full-page module and the popup.
- The panel can be detached into a window of its own via Document
  Picture-in-Picture — a chrome-less window that floats above other
  applications and can be dragged anywhere, second monitor included. A DOM
  element cannot leave the browser window, so this is the only way to put the
  chat "next to the browser". Chromium only; the button is absent elsewhere
  rather than failing, because window.open() yields a window with browser
  chrome that cannot float above other applications.

### Changed

- The panel may now be pushed off the left, right or bottom edge, down to a
  64px margin. It was clamped entirely inside the viewport, so it always
  covered part of what was underneath and the only ways out were collapsing or
  closing it. The top edge stays closed: dragging happens by the header, so a
  panel above y=0 loses its own handle.
- Requires nr-llm ^0.28.

### Fixed

- A detached panel fills its window instead of keeping the main window's
  position:fixed coordinates, which placed it outside a window a fraction of
  that size — it opened empty while every DOM assertion passed.

### Tests

- The Lit components render under Jest now instead of being checked by source
  analysis. `lit` resolves through the TYPO3 importmap at runtime, which the
  suites read as "cannot be tested"; it is a plain npm package, so mapping the
  specifier makes them render under jsdom. A grep cannot tell whether a button
  is reachable or what it does when clicked, which is exactly what was wrong.

## [0.8.0] - 2026-08-07

### Changed

- Require `netresearch/nr-llm` `^0.26.0` (was `^0.25`), and suggest
  `netresearch/nr-vault` `^0.14` (was `^0.4`). nr-llm 0.26 is the first release
  requiring nr-vault `^0.14`, so the suggested version is only installable from
  this release on. The installation requirements named nr-llm `^0.22` against a
  `^0.25` constraint; both now state `^0.26` (#92).

  Operator note: nr-vault 0.14 replaces its admin-only model with grantable
  operation permissions. Backend users who reach an API key through nr-llm need
  `tx_nrvault:secret.use`, and `secret.create` to store one.

### Fixed

- The chat iframe's `postMessage` handler did not check the message origin, so
  any framing page could post to it. Each E2E test also now gets a private temp
  directory instead of sharing one (#93).
- The ffmpeg container runs without a shell, removing the shell-interpretation
  step from a path that handles user-supplied filenames (#94).

## [0.7.0] - 2026-07-24

### Changed
- Require `nr-llm` `^0.25` (raised from `^0.23.1`). The agent run request now carries the full acting identity: `AgentRunRequest` takes a required `AiActorContext` instead of a bare `beUserUid`. `ChatService` sources the actor from the live backend user the worker commands already initialise, preserving the exact backend-user authorization (admin flag + groups) that the previous `beUserUid` gave — never a scopeless service account.

### Note
- nr-llm 0.25 flips the tool data-class gate default to `enforce` for fresh installs; some of nr-llm's builtin backend tools may be withheld from the model on configurations whose trust zone is below the tool's data class. Upgraded sites are pinned to `observe` by nr-llm's `DataClassEnforcementDefaultUpdateWizard` and stay unchanged until the operator opts in. Run the nr-llm upgrade wizard and DB schema update after upgrading.

## [0.6.0] - 2026-07-19

### Changed
- Require `nr-llm` `^0.22` (drops support for nr-llm 0.12–0.19). No code changes: every consumed nr-llm symbol (`ProviderAdapterRegistryInterface`, the `Provider\Contract` interfaces, `CompletionResponse`, `ToolSpec`, `ToolCall`, `Model`) is unchanged across 0.20–0.22.

## [0.5.0] - 2026-06-12

### Added
- FAL file picker: users can now select existing TYPO3 FAL files as chat attachments via the TYPO3 Element Browser, in addition to uploading new files
- New backend endpoint `GET /ai-chat/file-info` resolves FAL file metadata (name, MIME type, size) by UID
- Integrated AI chat module in the TYPO3 backend (Admin Tools > AI Chat)
- Floating chat panel in the backend toolbar, persistent across module navigation
- Conversation history with resume, pin, and auto-archive support
- Background processing via CLI commands (`ai-chat:process`, `ai-chat:worker`)
- MCP (Model Context Protocol) integration for TYPO3 content management tools
- File/image upload support with per-provider capability detection (PNG, JPEG, WebP)
- PDF attachment support for providers implementing `DocumentCapableInterface` (Claude, Gemini); file picker accept filter is set dynamically per provider
- Document text extraction fallback: PDF, DOCX, TXT, and XLSX files can now be uploaded as chat attachments regardless of LLM provider. Text is extracted server-side using smalot/pdfparser (PDF) and phpoffice/phpword (DOCX). XLSX support is optional via phpoffice/phpspreadsheet.
- Group-based access control and concurrency caps
- Sanitized error messages (API keys and URLs are redacted)
- Transient error retry logic (429, 503, overloaded) with configurable backoff
- Architecture layer enforcement via phpat tests
- Markdown rendering for LLM responses in the chat UI: headings, lists, code blocks, tables, blockquotes, and inline formatting are rendered via vendored marked.js v15 and DOMPurify v3 (no build step; XSS-safe)
- JavaScript unit test suite (Jest) covering markdown rendering and XSS sanitization

### Changed
- nr-llm dependency raised to `^0.12.0`: tool definitions are converted to typed `ToolSpec` value objects before each provider call, and `ToolCall` responses are normalised back to the legacy wire shape before persisting — conversations store tool calls as JSON and resumed conversations replay plain arrays
- `ChatService` and unit tests depend on `ProviderAdapterRegistryInterface` (`ProviderAdapterRegistry` became `final` in nr-llm 0.12)
- CI test matrix re-resolves the full dependency tree for the older TYPO3 branch instead of a partial `composer require -W` downgrade
- Chat `sendMessage` endpoint now accepts any FAL file the backend user has read permission for, not only files previously uploaded via the chat upload endpoint
