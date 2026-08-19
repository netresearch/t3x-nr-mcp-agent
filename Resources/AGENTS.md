<!-- Managed by agent: keep sections and order; edit content, not structure -->
<!-- Last updated: 2026-08-19 -->

# AGENTS.md — Resources/

## Overview

Frontend assets of the backend module and toolbar panel. `Private/`: Fluid layout (`Layouts/Default.html`), template (`Templates/Chat/Index.html`), XLIFF translations (`Language/locallang_chat.xlf`, `locallang_mod.xlf`, `de.` prefix for German). `Public/`: Lit web components in `JavaScript/` (`chat-app.js`, `chat-core.js`, `ai-chat-panel.js`, `api-client.js`, `markdown.js`, `theme.js`, `icons.js`, `toolbar/chat-panel.js`, vendored libs in `Vendor/`), styles in `Css/chat.css`, icons in `Icons/`.

## Setup

- **No build step** — Lit components ship as native ES modules (ADR-008); module mapping in `../Configuration/JavaScriptModules.php`
- Markdown rendering uses marked + DOMPurify (ADR-012); vendored copies live in `Public/JavaScript/Vendor/`

## Tests

- Jest unit tests for components: `make test-js` (specs in `../Tests/JavaScript/`)
- Playwright E2E against a running TYPO3: `make test-e2e` (specs in `../Build/tests/playwright/specs/`)
- After JS/CSS changes run `make sync` so DDEV instances pick them up

## Conventions

- One Lit component per file; keep the polling-based API contract (`api-client.js` ↔ `ChatApiController`) — no WebSockets/SSE (ADR-007)
- Translations: every new UI label goes into `locallang_chat.xlf` AND `de.locallang_chat.xlf`
- The floating toolbar panel lives outside the module iframe (ADR-011) — style changes must be verified in both the module and the pop-out panel

## Security

- All LLM/markdown output is sanitized with DOMPurify before insertion into the DOM — never bypass it with `innerHTML` on raw model output
- Do not add third-party CDN references; vendored dependencies only (`Public/JavaScript/Vendor/`)

## Checklist

- [ ] `make test-js` green; E2E run for UI-visible changes
- [ ] Both language files updated for new labels
- [ ] Checked in light and dark backend themes (`theme.js`)
- [ ] No inline event handlers or unsanitized HTML injection

## Examples

- Component structure to copy: `Public/JavaScript/toolbar/chat-panel.js` with its test `../Tests/JavaScript/chat-panel-position.test.js`
- Safe markdown pipeline: `Public/JavaScript/markdown.js` + `../Tests/JavaScript/markdown.test.js`

## When stuck

- UI architecture and panel design decisions: `../Documentation/Developer/ADR/` (ADR-008, ADR-011, ADR-012)
- Fluid entry point: `Private/Templates/Chat/Index.html`
- Repo-wide rules: `../AGENTS.md`
