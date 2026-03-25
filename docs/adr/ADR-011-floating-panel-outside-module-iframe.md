# ADR-011: Floating Chat Panel Outside the Module iframe

**Status:** Accepted
**Date:** 2026-03-16

## Context

The initial AI Chat implementation is a full-page backend module (Admin Tools > AI Chat). To use it, editors must leave their current workspace (Page module, List module), interact with the chat, then navigate back to verify results. For workflows where the editor issues a series of content changes via AI, this creates constant context-switching.

Alternatives considered:
- **Embedded iframe inside each module**: Requires patching every TYPO3 core module; not feasible.
- **Sidebar panel inside the module iframe**: Only visible in the AI Chat module itself; disappears when navigating away.
- **Floating element inside the module iframe**: Iframes are isolated; an element inside one iframe cannot span the full backend.
- **Floating element in the outer backend frame (`document.body`)**: Persists across all module navigations because it lives outside the iframe.

## Decision

Inject an `<ai-chat-panel>` web component into `document.body` of the outer TYPO3 backend frame. The component is loaded via the import map `backend.module` tag (the same mechanism TYPO3 core uses for toolbar items like live search), so no PHP `PageRenderer` call is needed. The panel uses `position: fixed` with `z-index` coordinated with TYPO3's layering scale.

The existing full-page module is retained for history browsing and extended sessions.

## Consequences

- The panel persists across all module navigations without any module cooperation.
- The panel's AJAX calls use the same `ajaxUrls` available in the outer frame as any toolbar item.
- `z-index` coordination is required: the panel sits above the scaffold header but below TYPO3 modals.
- Drag and resize use the Pointer Events API (`setPointerCapture`) for reliable cross-element interaction.
