# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **Approve and Deny are the only actions of the approval card.** A link sat beside them, styled like a third button and labelled *Grant approval* — but it opens the run's timeline in AI Tasks, a read-only view where nothing can be granted, so the card offered two ways to one decision and one of them led nowhere (NEXT-162). The link is gone from a card that shows a preview. Where a call has no usable preview — it failed, it was withheld, or the tool offers none — the card keeps the link as plain text below the buttons, because the card itself then cannot show what is being decided. Its label now says what it opens: *Open the run in AI Tasks*. The notices without a decidable card (an input pause, an unreadable run, a user who may not decide) keep the link as before, under the new label.

### Added

- **The card says when a call came back because its record changed.** nr-llm refuses an approved write whose record no longer matches the preview and hands the run back with `previewStale` set. The chat dropped that field, so the card reappeared looking exactly as it had before the click; only the approvals module explained it. The flag now reaches the card and renders as a warning above the fresh preview.

## [0.13.1] - 2026-09-17

### Changed

- **`netresearch/nr-llm` is accepted at `^0.34 || ^0.35`.** nr-llm 0.35.0 is released and on a 0.x `^0.34` does not admit it, so this extension pinned every installation it is part of to nr-llm 0.34. The constraint is declared in `composer.json` and in `ext_emconf.php`, and both now carry the wider range. Source compatibility was measured rather than assumed: of nr-llm 0.35's three breaking changes, `ToolResult::withWriteTarget()` affects only extensions that register tools, `ConversationService::startSession()` is not called here, and the `ModelResolution` added to `chatForConfiguration()`/`chatWithConfiguration()` is nullable and last, so existing call sites are unchanged.

## [0.13.0] - 2026-09-16

### Fixed

- **An approval granted in the chat looked like it had failed, and the retry next to it created the record twice.** Clicking *Approve* answered with a red `Error: … waiting for your approval`, and the same line was still there after the run had completed. Two causes, one field: `recordDecision()` claims the conversation as `processing` but left the notice the pause had written standing, and `applyResult()` writes the whole row on success without clearing it. `processing` is a resumable status, so the chat rendered a *Retry* button beside that error — and retrying clears the recorded decision and runs the turn again, while the worker is still carrying out the approved write. That is where the duplicated pages and content elements came from (NEXT-155 UAT-A07, NEXT-156, NEXT-153): the reader was told their approval had failed, and the button offered to them made it true twice. Both paths now clear the notice, `resumeConversation()` answers `409` while a recorded decision is still in flight, and the click is answered at once with *Approval granted — the assistant is carrying the step out* (or the denial), which stands until the assistant's own answer arrives and replaces it. The pending-approval notice no longer reads "Grant it under Web > AI Tasks > Approvals" either: it sent the reader to the second approval place while the card with the two buttons sat directly beneath the sentence, and a decision taken there after one taken here is answered with nr-llm's "The run could not be resumed", because the first one had already consumed the run. The way to the module stays as the card's link. One more stale path fixed with it: the "finished outside the chat" message still said *Web > AI Tasks*, where the modules have not lived since nr-llm 0.34 moved them into the shared *AI* section.

### Added

- **The assistant is told that a chat attachment is a managed file, and where it is.** The upload endpoint has always written an attachment into the file storage and indexed it, so an attached image or PDF is a `sys_file` record in `fileadmin/ai-chat/<be_user_uid>/` before the model ever sees it. The model had no way of knowing: it received pixels or extracted text and nothing else. Asked on the demo to place an attached image in `fileadmin` and reference it from a content element, it answered that no upload or import tool for chat attachments was available to it (NEXT-155, UAT-A04/A05) — and both halves of that were wrong, because the file was already there and referencing an existing one is what nr-llm's `attach_file_to_content_element` does. Each attachment now reaches the model with its `sys_file` uid, its name and its combined identifier, and the system prompt says what that means. The announcement is derived per turn and never written into the stored transcript, which describes a record that can be renamed or moved.
- **`attachmentFolder` decides where chat attachments are stored** (default `ai-chat`, i.e. `fileadmin/ai-chat/`). It used to be a constant in the controller. Where a file lands is an editorial decision about someone's `fileadmin` — the more so now that the file can be referenced from a content element — and the documentation says plainly that a folder denied to HTTP and a folder editors publish from cannot be the same folder. The per-user subfolder below it stays unconfigurable: it is what keeps one user's attachments out of another's.

### Changed

- **The upload never overwrites, never deletes, and stops making copies of the same file.** `addFile()` is now called with `DuplicationBehavior::RENAME` explicitly rather than relying on the default — this is the one place in the extension that writes into a `fileadmin`, and a reader should not have to go and check which default applies. A name that is taken therefore yields `report_01.pdf`; but when the file of that name has the same `sha1`, the existing one is returned instead, so attaching the same picture to three conversations no longer leaves three `sys_file` rows and three sets of metadata for one picture. A user who may not write to the attachment folder gets `403` instead of a `500`: core decides that inside `addFile()`, and its refusal used to surface as a server error.
- **Content written into TYPO3 keeps the language of the material it came from.** The system prompt said "always answer in the same language the user writes in", which is right for the conversation and wrong for a page: a German PDF summarised in an English-language chat produced a German-titled page with an English body (NEXT-155). It now separates the two — the reply follows the user, the record follows its source material and the `sys_language_uid` it is written to, and translation happens only when asked for. Prompt guidance, not a guarantee; it steers the model, it cannot bind it.

### Removed

- **A test target that ran no tests.** `Build/phpunit.xml` defines the suites `unit` and `functional` and nothing else, so `--testsuite architecture` matched an empty set: PHPUnit printed `No tests executed!` and exited 0. Three places called it — `composer ci:tests:architecture`, `make test-arch`, and `make test`, which lists `test-arch` among its prerequisites. `make test` is the command the pull-request template's checklist names, so every PR has been ticking a box over a step that did nothing. All three are gone; the phpat layer rules are registered in `Build/phpstan/phpstan.neon` as `phpat.test` services and run with `make phpstan` / `composer ci:phpstan`, which is what `Documentation/Developer/Testing.rst` already said.

### Changed

- **`Documentation/Changelog.rst` points at the changelog instead of copying it.** The page carried a second, hand-written changelog that the release flow never wrote to — that flow bumps `ext_emconf.php`, `composer.json`, `Documentation/guides.xml` and `CHANGELOG.md` — so it stopped at 0.1.0 while the extension went on to 0.12.3, thirty tags later. Backfilling would only restart the drift at the next release, so the page now links `CHANGELOG.md` and the releases list on GitHub, as absolute URLs that work for a reader on docs.typo3.org. It names which covers what: this file starts at 0.5.0, and the releases list is the only place the earlier tags are described — the GitHub release for 0.1.0 carries the deleted section verbatim. The `changelog` anchor and the `Index.rst` toctree entry are unchanged, and the page still renders.


### Fixed

- **A file the picker offered could come back rejected.** `getProviderCapabilities()` advertises the formats the active provider names, and the frontend puts them straight into the file input's `accept` attribute; the upload endpoint then validates the MIME type `finfo` detects, against a private extension→MIME table that listed png, jpg, jpeg, gif, webp and pdf. Gemini also announces `heic` and `heif`, so on a Gemini installation the picker offered `.heic`, the table could not translate it, and the upload answered `422 File type not supported`. Both sides now read one `UploadMimeTypeMap`: it covers every extension a provider currently announces, and an extension it cannot translate is dropped from the advertised list rather than guessed at — guessing would widen what the endpoint accepts, and that list is a security boundary.

### Changed

- **Two architecture rules were passing without checking anything.** `testControllerDoesNotExecuteProcesses` and `testHookDoesNotDependOnController` selected the `Mcp` and `Hook` namespaces, both of which 0.12.0 deleted along with the MCP client. A phpat rule over an empty namespace is vacuously true, so the two had been green and empty ever since. They are replaced by rules that bind to namespaces that exist: `Controller` must not depend on `Command` (background processing is reached through `ChatProcessorInterface`), and `Service` must not depend on `Controller`. The `Mcp` selector is gone from the `Domain` rule for the same reason.
- Documentation caught up with what 0.12.0 removed: the component map no longer lists an *MCP Client* row pointing at `Classes/Mcp/`, nor `AgentLoopService.php` and `AccessControlService.php`, neither of which exists; the dependency-rule lists in `Architecture.rst`, `docs/ARCHITECTURE.md` and ADR-006 match the tests again; `Classes/AGENTS.md` no longer names the deleted `Checker/` and `Hook/` directories or the removed tool-provider cache; ADR-001 carries a status amendment saying which half of it still holds. Usage and Introduction no longer speak of MCP being "enabled", which was the `enableMcp` setting, and the system-prompt example in the configuration reference no longer instructs the model about `WriteTable` — a tool from the MCP server this extension no longer talks to — but about tools nr-llm actually registers.


## [0.12.3] - 2026-09-03

### Changed

- Requires `netresearch/nr-llm` `^0.34`. The floor rises because 0.34.0 is where the demo instance and every sibling extension are going, and staying on `^0.33` would keep an installation from taking both. 0.34.0's one breaking change is the backend module move (nr-llm ADR-183): the modules left Administration for a shared `AI` section and the container URL `/module/nrllm` is gone. Nothing here registers a module under that container or links to that URL, so nothing else changes.
- `ext_emconf.php` is raised with it, and its upper bound is now `0.34.99` rather than `0.99.99` — the two install paths had disagreed about everything above 0.34.

## [0.12.2] - 2026-08-21

### Fixed

- **The worker dequeue runs on the databases this extension is actually installed on.** `dequeueForWorker` built `UPDATE … ORDER BY tstamp ASC LIMIT 1`, which is MySQL syntax that SQLite accepts only when compiled with `SQLITE_ENABLE_UPDATE_DELETE_LIMIT` — a flag most builds do not set. Where it is missing the statement fails with `near "ORDER": syntax error` and no queued conversation is ever picked up, in production and not only in tests. CI could not show it because both environments are called "sqlite" and are not the same build (#138).

### Changed

- Requires `netresearch/nr-llm` `^0.33`. The floor rises because 0.33.0 removes a regression 0.32.0 introduced: `vision()` and `embed()` handed the provider registry the `tx_nrllm_provider` row's identifier where it is keyed by the adapter's own name, so a call that names no provider — which is what this extension makes — failed with "Provider … not found" on an installation that has a perfectly good default configuration. 0.32.0 did not fix the failure it was written for, it renamed it.
- `ext_emconf.php` declares the same dependency and is raised with it, so the two cannot disagree about which versions this extension accepts.

## [0.12.1] - 2026-08-21

### Changed

- Requires `netresearch/nr-llm` `^0.32`. The floor rises because 0.32.0 is what
  makes the annotation below arrive: until then three feature services rebuilt
  the options object and dropped the caller source before dispatch. 0.32.0 also
  gives `vision()` and `embed()` the default-configuration fallback `chat()`
  already had, which is what made an image upload in the chat answer HTTP 500
  on an installation that had a perfectly good default configuration.

### Added

- The chat turn names this extension on its nr-llm call (`withCallerSource`,
  nr-llm ADR-177), so nr-llm's Analytics module lists its usage and cost under
  `nr_mcp_agent` instead of grouping it as *Unattributed*. Two operations are
  reported: `chatTurn` for a queued turn and `resumeChatTurn` for a turn re-run
  over an existing transcript. The identity is call metadata and never reaches
  the provider. The approval continuation stays unattributed —
  `AgentRuntimeInterface::approve()` has no caller-source channel (#134,
  upstream [nr-llm#847](https://github.com/netresearch/t3x-nr-llm/issues/847)).

## [0.12.0] - 2026-08-20

### Removed

- The extension's own MCP client — `McpToolProvider`, `McpConnection`, the
  `tx_nrmcpagent_mcp_server` table and TCA, the `enableMcp` setting, the
  `nr_mcp_agent_tools` cache and the record-save cache-flush hook. None of it
  had been used since 0.11 moved the chat turn onto nr-llm's AgentRuntime
  (nr-llm ADR-116); the documentation still described it as the way to get
  writing tools, which sent operators down a dead path (NEXT-153). External
  MCP servers are configured in nr-llm's *MCP Servers* module and reach the
  chat through the same registry as the builtin tools. ADR-003 and ADR-014
  are marked superseded. The `tx_nrmcpagent_mcp_server` table stays in the
  database until a DB compare removes it; nothing reads it.
- `ChatApiController::getStatus()` no longer reports `mcpEnabled` or the
  legacy `mcpServerCommand`/`mcpServerArgs` migration hint; the JS client's
  doc comment follows.

## [0.11.3] - 2026-08-20

### Changed

- netresearch/nr-llm requirement raised to `^0.31` (0.30 support dropped)

## [0.11.2] - 2026-08-19

### Changed

- netresearch/nr-llm requirement raised to `^0.30` (0.28/0.29 support dropped)

## [0.11.1] - 2026-08-13

### Fixed

- The approval card now arrives on the poll that first sees the pause. A run
  that pauses writes no message, so the client polled with `after` already at
  the message count and landed on the metadata fast path — which answered
  without card data. Polling then stopped, because awaiting approval is not a
  processing status, so nothing fetched it afterwards: the user was left with
  the notice and the module link until they reloaded the conversation. That is
  most of what the in-chat approval was built to replace.
- The panel keeps its styling when it pops out into its own window. Lit applies
  `static styles` through `adoptedStyleSheets`, and a constructed stylesheet
  belongs to the document that made it, so moving the element left the shadow
  root holding sheets the new document ignores — the chat rendered as bare
  serif HTML. The sheets are rebuilt for whichever window the panel lands in,
  going out and coming back, and the detached document gets a margin reset and
  the backend's background as a resolved value.

## [0.11.0] - 2026-08-13

### Added

- The pending tool call can be approved or denied in the chat itself, with what
  it would do, its arguments and both decisions on the card. Until now the chat
  could only point at the AI Tasks module, and a run approved there continued
  where the chat could not see it — the conversation stayed parked forever with
  a button offering a decision that had already been made.
  The decision goes through nr-llm's own `approve()`, so it passes the same
  per-run authorisation as the module and carries the same turn digest; a
  decision made on a card that has since been superseded is refused by the
  runtime rather than applied. The link to the module stays, as the way to see
  the whole run.

### Changed

- The approval decision is carried out by the worker instead of the web
  request. `approve()` drives the whole continuation — up to twenty further
  provider round-trips — which a gateway timeout would kill with the write
  already done and nothing written back. The request now records the decision,
  claims the conversation and answers 202, exactly as sending a message does;
  the outcome arrives through the poll.
- A conversation whose worker never took the decision is reconciled against the
  run itself rather than a timeout: still waiting means the card comes back,
  still running means it is left alone, and settled means the chat says the run
  finished where it cannot see it instead of showing a spinner forever.

### Database

- `tx_nrmcpagent_conversation` gains `approval_run_uuid`, `approval_decision`
  and `approval_turn_digest`. Run the database compare (or
  `typo3 extension:setup`) after updating; without the columns the approval
  card cannot be shown and no decision can be recorded.

## [0.10.1] - 2026-08-13

### Fixed

- Supports nr-llm 0.28 again. 0.10.0 raised the floor to ^0.29 because the run
  detail the approval link points at arrived there, which made this extension
  uninstallable next to every other one that still asks for ^0.28 — and with it
  the fix that stops a pending approval reading as a crash. The link is now
  checked instead of required: on 0.29 it is offered, on 0.28 the notice
  carries none, which is what it did before the link existed.

## [0.10.0] - 2026-08-13

### Added

- The pending-approval notice in the chat links to the run that is waiting.
  Naming the module was not enough: the approvals inbox lists every run the
  user may act on, so finding the right one was still their job.
- The LICENSE file composer.json has always named.

### Changed

- Requires nr-llm ^0.29. The run detail the approval link points at was added
  there; on 0.28 the route resolves but the action does not exist, so the link
  would have answered with an exception page.

### Fixed

- A tool that waits for an approval is shown as a state of its own instead of
  an error. The chat reported the pause as FAILED with "the assistant needs
  additional confirmation ... which this chat cannot handle yet", which made a
  safeguard working as designed look like a crash. There is no Retry button on
  that state: restarting would step past a decision that is still pending.
- The lint suite in runTests.sh excludes .Build again. The exclusion was
  written so that find looked for a literal asterisk and never matched, so the
  suite linted the vendor tree and died on a template file that is deliberately
  not valid PHP.

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
