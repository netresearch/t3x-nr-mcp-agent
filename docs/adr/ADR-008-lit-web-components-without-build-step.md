# ADR-008: Lit Web Components Without a Build Step

**Status:** Accepted
**Date:** 2026-03-15

## Context

The chat UI requires a reactive component model: dynamic message lists, optimistic updates, streaming state indicators, and a floating panel that persists across iframe navigation. Options considered:

- **React / Vue / Svelte**: Rich ecosystems, but require a build pipeline (Webpack, Vite). TYPO3 extensions ship static assets; introducing a build step adds tooling complexity and diverges from TYPO3 core patterns.
- **Vanilla JS with manual DOM updates**: No dependencies, but managing reactive state manually at this complexity level is error-prone.
- **Lit 3**: Lightweight (~6 kB), standards-based web components with reactive properties and declarative templates. Can be loaded directly from an ES module import map — no build step.
- **TYPO3 core components**: No suitable chat-oriented components exist in TYPO3 core.

## Decision

Use Lit 3 web components, loaded via TYPO3's import map mechanism. JavaScript is written in ES modules and shipped as-is. No transpilation, no bundler.

## Consequences

- No build tooling required; assets are edited and deployed directly.
- Lit's web component model integrates cleanly with TYPO3's outer backend frame: the `<ai-chat-panel>` element is appended to `document.body` and persists across module navigation (see ADR-011).
- Browser support is limited to evergreen browsers — acceptable for a TYPO3 backend tool.
- Unit tests use Jest with `@web/test-runner` compatible setup; the same no-build constraint applies.
