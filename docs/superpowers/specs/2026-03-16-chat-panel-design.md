# AI Chat Bottom Panel

## Problem

The AI Chat is a full-page backend module. Users must leave their current workspace (Page module, List module) to interact with the chat. After requesting content changes via chat, they must switch back to verify results. This context-switching breaks the workflow.

## Solution

Add a floating bottom panel that lives outside the TYPO3 module iframe, attached to `document.body`. The panel stays visible across all module navigations and provides the full chat experience without leaving the current workspace.

The existing full-page module remains as a fullscreen alternative for history browsing and long conversations.

## Architecture

### DOM Placement

```
<body>
├── .scaffold (TYPO3 backend)
│   ├── .scaffold-header        ← Toolbar with chat button
│   ├── .scaffold-modulemenu    ← Module sidebar
│   └── .scaffold-content
│       └── iframe              ← Module content (Page, List, ...)
│
└── <ai-chat-panel>             ← NEW: fixed-position panel, outside iframe
```

The panel is created by JavaScript at runtime and appended to `document.body`. It floats above the entire backend using `z-index: 4900` (below TYPO3 modals at 5000+, above scaffold content). Because it lives outside the module iframe, it persists across module navigation.

### Toolbar Integration

A new `ChatToolbarItem` registers in the TYPO3 top toolbar (right side, alongside Search, Bookmarks, User). It provides:

- **Icon:** Chat/message bubble icon
- **Badge:** Count of active (processing/locked/tool_loop) conversations
- **Click:** Toggles the panel between hidden and last visible state

Implementation via `ToolbarItemInterface` and `RequestAwareToolbarItemInterface`. Auto-registered by TYPO3 DI (`autoconfigure: true` is set in `Services.yaml`).

The toolbar item's `getItem()` renders the button HTML. The JavaScript module is loaded via `PageRenderer::loadJavaScriptModule()` in the toolbar item — this JS module creates the `<ai-chat-panel>` element and appends it to `document.body` at runtime. The PHP class does NOT inject the panel DOM node directly.

### AJAX Routes in Outer Frame

All AJAX routes registered in `Configuration/Backend/AjaxRoutes.php` are available in `TYPO3.settings.ajaxUrls` in the outer backend frame (not only inside the module iframe). This is standard TYPO3 behavior — toolbar items and other outer-frame components can use AJAX routes. The existing `api-client.js` resolves URLs from this global, so it works unchanged in the panel context.

### Panel States

The panel has four states with fluid transitions:

| State | Height | What's visible |
|-------|--------|----------------|
| **Hidden** | 0px | Nothing — only the toolbar button. Default state. |
| **Collapsed** | 36px | Header bar with active conversation title + status indicator |
| **Expanded** | User-defined (default 350px) | Chat messages + input + compact conversation switcher |
| **Maximized** | Full viewport height | Chat + full sidebar with conversations, pin, archive, search |

State transitions:
- Toolbar button click: hidden ↔ last visible state
- Header bar click (collapsed): collapsed → expanded
- Header bar close button: any → hidden
- Header bar minimize button: any → collapsed
- Header bar maximize button: expanded ↔ maximized
- Drag resize: fluid between collapsed and maximized

### Resize Behavior

The top edge of the panel is draggable (`cursor: ns-resize`). Supports both mouse and touch events (`mousedown`/`mousemove` + `touchstart`/`touchmove`). Dragging changes the panel height continuously. The state label follows the height:

- Below 50px: snaps to collapsed (36px)
- 50px–90% viewport: expanded (free height)
- Above 90% viewport: snaps to maximized (100%)

Constraints:
- Minimum height: 36px (collapsed)
- Maximum height: 100vh
- Stored height persisted in `localStorage`

### Conversation Management

The panel is a full-featured chat client, not a simplified view:

**Expanded state:**
- Compact conversation switcher (dropdown or horizontal tabs)
- Active conversation chat with messages and input
- New conversation button
- Resume button for failed conversations

**Maximized state:**
- Full sidebar (left) with:
  - Search/filter
  - Pin filter toggle
  - Archive toggle
  - Conversation list with status indicators
- Main chat area (right) with messages and input

**All states support:**
- Creating new conversations
- Switching between conversations
- Sending messages
- Viewing tool calls and results
- Pin/unpin conversations
- Archive conversations

### Shared Code Strategy

The existing `chat-app.js` component contains chat logic (message rendering, polling, API calls) tightly coupled with layout. Refactor into:

1. **`chat-core.js`** — Chat logic as Lit ReactiveController: message handling, polling, API integration, conversation state management. No layout opinions.
2. **`chat-app.js`** — Full-page module component. Uses chat-core. Existing layout preserved.
3. **`ai-chat-panel.js`** — Panel component. Uses chat-core. Panel-specific layout, state management, resize, toolbar integration.
4. **`api-client.js`** — Unchanged, used by both.

All new JS modules must be registered in `Configuration/JavaScriptModules.php` (TYPO3 import map) under the `@netresearch/nr-mcp-agent/` namespace.

This keeps the full-page module working without changes while sharing all business logic.

### Persistence

Stored in `localStorage` per backend user (keyed by `ai-chat-panel-{beUserUid}`):

- `panelState`: hidden | collapsed | expanded | maximized
- `panelHeight`: number (pixels, for expanded state)
- `activeConversationUid`: number (last active conversation)

Handles missing or corrupted localStorage gracefully — falls back to defaults.

### Badge Updates

The toolbar badge shows the count of conversations in `processing`, `locked`, or `tool_loop` status (matching the existing `PROCESSING_STATUSES` set in `chat-app.js`). Updated via:

- The existing `ai_chat_status` endpoint, extended with an `activeConversationCount` field in the response
- When panel is visible: piggybacks on the panel's regular poll cycle (2s during processing, 5s idle)
- When panel is hidden: reduced poll frequency (every 30s) for badge-only updates
- Badge disappears when count is 0

### Accessibility

- Toolbar button: standard TYPO3 toolbar keyboard navigation (Tab/Enter)
- Panel open/close: `Escape` key collapses the panel
- Focus management: when panel opens, focus moves to the message input; when panel closes, focus returns to the toolbar button
- ARIA: `role="complementary"`, `aria-label="AI Chat"`, `aria-expanded` reflects panel visibility
- Resize handle: `role="separator"`, `aria-orientation="horizontal"`, keyboard resize via `Arrow Up`/`Arrow Down`

## Components

### New PHP Classes

**`ChatToolbarItem`**
- Implements `ToolbarItemInterface` and `RequestAwareToolbarItemInterface`
- `getItem()`: renders toolbar button with icon + badge container
- `checkAccess()`: respects `allowedGroups` extension configuration
- Loads `@netresearch/nr-mcp-agent/ai-chat-panel.js` via `PageRenderer::loadJavaScriptModule()`
- Badge count queried via `ConversationRepository` (count by status in processing/locked/tool_loop for current BE user)

### New JavaScript Modules

**`ai-chat-panel.js`** (Lit component)
- Properties: `state`, `height`, `activeConversationUid`, `conversations`, `messages`
- Handles: panel rendering, state transitions, resize drag (mouse + touch), localStorage persistence
- Delegates chat logic to shared core
- Self-appends to `document.body` when loaded

**`chat-core.js`** (Lit ReactiveController)
- Extracted from current `chat-app.js`
- Message polling, send, conversation CRUD, status management
- Used by both `chat-app.js` and `ai-chat-panel.js`

### Modified Files

**`chat-app.js`**
- Refactored to use `chat-core.js` for logic
- Layout code stays, now only responsible for full-page rendering

**`Configuration/JavaScriptModules.php`**
- Register `chat-core.js` and `ai-chat-panel.js` in import map

**`ChatApiController::getStatus()`**
- Extend response with `activeConversationCount` field

## Testing

### Unit Tests (JavaScript)

Panel state management:
- State transitions: hidden → expanded → collapsed → hidden
- Toolbar click toggles between hidden and last visible state
- Resize drag updates height and persists to localStorage
- Snap behavior: below 50px snaps to collapsed, above 90% snaps to maximized
- Height constraints enforced (min 36px, max 100vh)

localStorage persistence:
- Panel state saved on every transition
- Panel height saved after resize
- Active conversation UID saved on switch
- State restored correctly on page reload
- Handles missing/corrupted localStorage gracefully (falls back to defaults)

Conversation management:
- Conversation switcher shows correct list in expanded state
- Sidebar renders with search/filter/pin/archive in maximized state
- New conversation creation works from panel
- Pin/archive actions work from panel

Accessibility:
- Escape key collapses panel
- Focus moves to input on panel open
- Focus returns to toolbar button on panel close
- ARIA attributes update with panel state

### Functional Tests (PHP)

ChatToolbarItem:
- Implements ToolbarItemInterface and RequestAwareToolbarItemInterface
- `getItem()` renders correct HTML structure (button + icon + badge container)
- `checkAccess()` returns false when user is not in allowed groups
- Badge count reflects active conversation count (requires fixture data with conversations in processing/locked/tool_loop status for the test BE user)
- Rendered HTML output contains `<script type="module">` tag for panel JS (verifies PageRenderer integration)

ChatApiController (extended):
- `getStatus()` response includes `activeConversationCount` field
- Count reflects conversations in processing/locked/tool_loop for current user

### E2E Tests (Playwright)

Core workflows:
- Toolbar button visible in backend, click opens panel
- Panel persists across module navigation (switch from Page to List)
- Send message in panel, receive response
- Create new conversation from panel
- Switch between conversations in panel

Panel behavior:
- Resize panel by dragging top edge
- Collapse panel via header button
- Maximize panel shows sidebar with conversation list
- Pin/archive conversation from panel sidebar
- Panel state persists after page reload

Integration:
- Send content creation request in panel, verify in Page module (without closing panel)
- Resume failed conversation from panel
- Badge updates when conversation starts processing

Edge cases:
- Panel open when session expires — handles 401 gracefully
- Corrupted localStorage — panel falls back to default state

## Migration Path

1. Extend `ChatApiController::getStatus()` with `activeConversationCount`
2. Extract `chat-core.js` from `chat-app.js` (refactor, no new features)
3. Register new JS modules in `Configuration/JavaScriptModules.php`
4. Build `<ai-chat-panel>` component using chat-core
5. Build `ChatToolbarItem` PHP class
6. Add E2E tests for panel workflows
7. Full-page module continues to work unchanged
