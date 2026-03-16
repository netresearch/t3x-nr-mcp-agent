# AI Chat Bottom Panel Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a floating bottom panel to the TYPO3 backend that provides full chat functionality without leaving the current workspace.

**Architecture:** Extract chat logic from `chat-app.js` into a shared `ChatCoreController` (Lit ReactiveController), then build `<ai-chat-panel>` as a new Lit component consuming that controller. A `ChatToolbarItem` PHP class adds the toolbar button, and a tagged JS entry point auto-loads the panel in the outer backend frame.

**Tech Stack:** PHP 8.4, TYPO3 v13 Toolbar API, Lit 3.x, Playwright E2E

**Spec:** `docs/superpowers/specs/2026-03-16-chat-panel-design.md`

---

## Chunk 1: Backend API Extension + ChatToolbarItem

### Task 1: Extend getStatus() with activeConversationCount

**Files:**
- Modify: `packages/nr_mcp_agent/Classes/Controller/ChatApiController.php:29-57`
- Test: `packages/nr_mcp_agent/Tests/Unit/Controller/ChatApiControllerTest.php`

- [ ] **Step 1: Write the failing test**

In the existing `ChatApiControllerTest.php`, add a test that verifies `getStatus()` returns `activeConversationCount`:

```php
#[Test]
public function getStatusReturnsActiveConversationCount(): void
{
    $repository = $this->createMock(ConversationRepository::class);
    $repository->method('countActiveByBeUser')->willReturn(2);

    // ... construct controller with repository mock, config with llmTaskUid=1 ...

    $response = $controller->getStatus($request);
    $data = json_decode($response->getBody()->__toString(), true);

    self::assertArrayHasKey('activeConversationCount', $data);
    self::assertSame(2, $data['activeConversationCount']);
}
```

- [ ] **Step 2: Run test — expect FAIL** (no `activeConversationCount` in response yet)

Run: `composer exec -- phpunit -c phpunit.xml --filter=getStatusReturnsActiveConversationCount`

- [ ] **Step 3: Implement — add activeConversationCount to getStatus()**

In `ChatApiController::getStatus()`, after the existing response array, add the count:

```php
return new JsonResponse([
    'available' => $taskUid > 0,
    'mcpEnabled' => $mcpEnabled,
    'issues' => $issues,
    'activeConversationCount' => $this->repository->countActiveByBeUser($this->getBeUserUid()),
]);
```

- [ ] **Step 4: Run test — expect PASS**

Run: `composer exec -- phpunit -c phpunit.xml --filter=getStatusReturnsActiveConversationCount`

- [ ] **Step 5: Run full test suite**

Run: `composer exec -- phpunit -c phpunit.xml`
Expected: All tests pass (285+)

- [ ] **Step 6: Commit**

```
feat(nr-mcp-agent): add activeConversationCount to getStatus response
```

---

### Task 2: Create ChatToolbarItem PHP class

**Files:**
- Create: `packages/nr_mcp_agent/Classes/Backend/ToolbarItems/ChatToolbarItem.php`
- Create: `packages/nr_mcp_agent/Tests/Unit/Backend/ToolbarItems/ChatToolbarItemTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Backend\ToolbarItems;

use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;

class ChatToolbarItemTest extends TestCase
{
    #[Test]
    public function implementsToolbarInterfaces(): void
    {
        $item = $this->createToolbarItem();
        self::assertInstanceOf(ToolbarItemInterface::class, $item);
        self::assertInstanceOf(RequestAwareToolbarItemInterface::class, $item);
    }

    #[Test]
    public function getItemRendersButtonWithBadge(): void
    {
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('countActiveByBeUser')->willReturn(3);
        $item = $this->createToolbarItem(repository: $repository);

        $html = $item->getItem();

        self::assertStringContainsString('ai-chat-toolbar', $html);
        self::assertStringContainsString('3', $html);
    }

    #[Test]
    public function getItemHidesBadgeWhenCountIsZero(): void
    {
        $repository = $this->createMock(ConversationRepository::class);
        $repository->method('countActiveByBeUser')->willReturn(0);
        $item = $this->createToolbarItem(repository: $repository);

        $html = $item->getItem();

        self::assertStringContainsString('display:none', $html);
    }

    #[Test]
    public function checkAccessReturnsTrueWhenNoGroupRestriction(): void
    {
        $item = $this->createToolbarItem();
        self::assertTrue($item->checkAccess());
    }

    #[Test]
    public function hasDropDownReturnsFalse(): void
    {
        $item = $this->createToolbarItem();
        self::assertFalse($item->hasDropDown());
    }

    private function createToolbarItem(
        ?ConversationRepository $repository = null,
        ?ExtensionConfiguration $config = null,
    ): ChatToolbarItem {
        $repository ??= $this->createMock(ConversationRepository::class);
        $config ??= $this->createStub(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);
        $config->method('getLlmTaskUid')->willReturn(1);

        return new ChatToolbarItem($repository, $config);
    }
}
```

- [ ] **Step 2: Run test — expect FAIL** (class does not exist)

- [ ] **Step 3: Implement ChatToolbarItem**

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Backend\ToolbarItems;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class ChatToolbarItem implements ToolbarItemInterface, RequestAwareToolbarItemInterface
{
    private ServerRequestInterface $request;

    public function __construct(
        private readonly ConversationRepository $repository,
        private readonly ExtensionConfiguration $config,
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function checkAccess(): bool
    {
        if ($this->config->getLlmTaskUid() === 0) {
            return false;
        }
        $allowed = $this->config->getAllowedGroupIds();
        if ($allowed === []) {
            return true;
        }
        $beUser = $this->getBackendUser();
        if ($beUser === null) {
            return false;
        }
        $userGroups = array_map('intval', explode(',', $beUser->user['usergroup'] ?? ''));
        return array_intersect($allowed, $userGroups) !== [];
    }

    public function getItem(): string
    {
        $count = 0;
        $beUser = $this->getBackendUser();
        if ($beUser !== null) {
            $count = $this->repository->countActiveByBeUser((int)$beUser->user['uid']);
        }
        $badgeStyle = $count > 0 ? '' : 'display:none';

        return '<button class="toolbar-item ai-chat-toolbar-btn" title="AI Chat">'
            . '<span class="toolbar-item-icon">'
            . '<typo3-backend-icon identifier="actions-message" size="small"></typo3-backend-icon>'
            . '</span>'
            . '<span class="toolbar-item-badge badge badge-warning ai-chat-badge" style="' . $badgeStyle . '">'
            . $count
            . '</span>'
            . '</button>';
    }

    public function hasDropDown(): bool
    {
        return false;
    }

    public function getDropDown(): string
    {
        return '';
    }

    public function getAdditionalAttributes(): array
    {
        return ['class' => 'ai-chat-toolbar'];
    }

    public function getIndex(): int
    {
        return 25;
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
```

- [ ] **Step 4: Run test — expect PASS**

- [ ] **Step 5: Run full test suite**

- [ ] **Step 6: Commit**

```
feat(nr-mcp-agent): add ChatToolbarItem for backend toolbar integration
```

---

## Chunk 2: Extract chat-core.js from chat-app.js

### Task 3: Create ChatCoreController as Lit ReactiveController

**Files:**
- Create: `packages/nr_mcp_agent/Resources/Public/JavaScript/chat-core.js`
- Modify: `packages/nr_mcp_agent/Resources/Public/JavaScript/chat-app.js`

This is a pure refactor — no new features. The full-page module must work identically after.

- [ ] **Step 1: Create chat-core.js with extracted logic**

Extract these from `chat-app.js` into a `ChatCoreController` class:

**State properties** (managed by controller, reflected to host via `requestUpdate()`):
- `conversations`, `activeUid`, `messages`, `status`, `errorMessage`
- `loading`, `sending`, `available`, `issues`, `hasInput`, `inputValue`
- `knownMessageCount`, `pollFailures`, `expandedTools`

**Methods** (pure logic, no DOM access):
- `init()`, `loadConversations()`, `selectConversation(uid)`, `loadMessages()`
- `pollMessages()`, `startPollingIfNeeded()`, `schedulePoll()`, `stopPolling()`
- `isProcessing()`, `handleSend(content)`, `handleNewConversation()`
- `handleResume()`, `handleArchive()`, `handleTogglePin()`
- `handleToolMessageClick(idx)`, `getActiveConversation()`
- `renderMessageContent(msg)`

**Callback hooks** (host implements these):
- `onScrollToBottom(force)` — called after messages change
- `onFocusInput()` — called after conversation select
- `onResetInput()` — called after send

```javascript
import {ApiClient} from './api-client.js';

const PROCESSING_STATUSES = new Set(['processing', 'locked', 'tool_loop']);

/**
 * ChatCoreController — Lit ReactiveController encapsulating all chat logic.
 *
 * The host component must implement three callback methods:
 * - onScrollToBottom(force: boolean)
 * - onFocusInput()
 * - onResetInput()
 */
export class ChatCoreController {
    /** @type {import('lit').ReactiveControllerHost} */
    host;

    // State
    conversations = [];
    activeUid = null;
    messages = [];
    status = '';
    errorMessage = '';
    inputValue = '';
    hasInput = false;
    loading = true;
    sending = false;
    available = false;
    issues = [];
    maxLength = 0;
    expandedTools = new Set();

    /** @type {ApiClient} */
    _api;
    /** @type {AbortController} */
    _abortController;
    _pollTimer = null;
    _knownMessageCount = 0;
    _pollFailures = 0;

    constructor(host) {
        this.host = host;
        host.addController(this);
    }

    hostConnected() {
        this._abortController = new AbortController();
        this._api = new ApiClient(this._abortController.signal);
        this.init();
    }

    hostDisconnected() {
        this._abortController?.abort();
        this.stopPolling();
    }

    async init() {
        // ... same logic as current _init(), but uses this.host.requestUpdate()
        // ... and calls this.host.onScrollToBottom etc.
    }

    // ... all other methods extracted from chat-app.js
    // Replace this.renderRoot queries with callback hooks
    // Replace this.requestUpdate() with this.host.requestUpdate()
}
```

The full implementation follows the exact logic from `chat-app.js` lines 346-600, with three substitutions:
- `this.renderRoot.querySelector('.messages')` → `this.host.onScrollToBottom(force)`
- `this.renderRoot.querySelector('.input-area textarea')?.focus()` → `this.host.onFocusInput()`
- `textarea.style.height = 'auto'` in handleSend → `this.host.onResetInput()`

- [ ] **Step 2: Refactor chat-app.js to use ChatCoreController**

Replace all extracted logic with delegation to the controller. The component becomes a pure rendering shell:

```javascript
import {LitElement, html, css, nothing} from 'lit';
import {ChatCoreController} from './chat-core.js';

export class ChatApp extends LitElement {
    static properties = {
        maxLength: {type: Number, attribute: 'data-max-length'},
        _sidebarCollapsed: {state: true},
    };

    constructor() {
        super();
        this.chat = new ChatCoreController(this);
        this._sidebarCollapsed = false;
    }

    connectedCallback() {
        super.connectedCallback();
        this.chat.maxLength = this.maxLength || 0;
    }

    // Callback hooks
    onScrollToBottom(force = false) {
        const container = this.renderRoot?.querySelector('.messages');
        if (!container) return;
        if (force) { container.scrollTop = container.scrollHeight; return; }
        const nearBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 100;
        if (nearBottom) container.scrollTop = container.scrollHeight;
    }
    onFocusInput() {
        this.renderRoot?.querySelector('.input-area textarea')?.focus();
    }
    onResetInput() {
        const ta = this.renderRoot?.querySelector('.input-area textarea');
        if (ta) ta.style.height = 'auto';
    }

    // ... render() and _render* methods stay, but read from this.chat.*
    // e.g. this._messages → this.chat.messages
    // e.g. this._handleSend() → this.chat.handleSend(this.chat.inputValue)

    static styles = css`/* ... unchanged ... */`;
}
customElements.define('nr-chat-app', ChatApp);
```

- [ ] **Step 3: Verify full-page module still works**

Run the DDEV instance and manually verify:
1. Navigate to Admin Tools → AI Chat
2. Create a conversation
3. Send a message
4. Receive response
5. Tool calls render correctly
6. Sidebar works (collapse/expand, switch conversations)

- [ ] **Step 4: Run existing E2E tests if available**

Run: `npx playwright test`

- [ ] **Step 5: Commit**

```
refactor(nr-mcp-agent): extract ChatCoreController from chat-app.js

Pure refactor — no functional changes. Chat logic now lives in
chat-core.js as a Lit ReactiveController. chat-app.js delegates
all state management and API calls to the controller, keeping only
DOM rendering and callback hooks.
```

---

## Chunk 3: Panel Component + Toolbar Entry Point

### Task 4: Register toolbar JS entry point in import map

**Files:**
- Modify: `packages/nr_mcp_agent/Configuration/JavaScriptModules.php`
- Create: `packages/nr_mcp_agent/Resources/Public/JavaScript/toolbar/chat-panel.js` (stub)

- [ ] **Step 1: Update JavaScriptModules.php**

```php
<?php
declare(strict_types=1);

return [
    'dependencies' => ['backend'],
    'tags' => [
        'backend.module',
    ],
    'imports' => [
        '@netresearch/nr-mcp-agent/' => 'EXT:nr_mcp_agent/Resources/Public/JavaScript/',
    ],
];
```

Note: The `backend.module` tag causes all modules under this namespace to be loadable in the outer frame. The actual auto-execution happens from `toolbar/chat-panel.js` which self-initializes.

- [ ] **Step 2: Create stub toolbar/chat-panel.js**

```javascript
/**
 * Entry point for AI Chat panel — auto-loaded in the outer backend frame
 * via the backend.module import map tag.
 *
 * Finds the toolbar button rendered by ChatToolbarItem and wires it
 * to the <ai-chat-panel> component.
 */

// Will be fleshed out in Task 5
console.debug('[ai-chat-panel] toolbar entry point loaded');
```

- [ ] **Step 3: Commit**

```
chore(nr-mcp-agent): register toolbar JS entry point in import map
```

---

### Task 5: Build `<ai-chat-panel>` Lit component

**Files:**
- Create: `packages/nr_mcp_agent/Resources/Public/JavaScript/ai-chat-panel.js`
- Modify: `packages/nr_mcp_agent/Resources/Public/JavaScript/toolbar/chat-panel.js`

- [ ] **Step 1: Create ai-chat-panel.js with panel shell**

The panel component handles: state management (hidden/collapsed/expanded/maximized), resize drag, localStorage persistence, and renders the chat UI using `ChatCoreController`.

Key structure:
```javascript
import {LitElement, html, css, nothing} from 'lit';
import {ChatCoreController} from './chat-core.js';

const PANEL_STATES = { HIDDEN: 'hidden', COLLAPSED: 'collapsed', EXPANDED: 'expanded', MAXIMIZED: 'maximized' };
const DEFAULT_HEIGHT = 350;
const COLLAPSED_HEIGHT = 36;
const SNAP_THRESHOLD = 50;
const MAXIMIZE_THRESHOLD = 0.9;

export class AiChatPanel extends LitElement {
    static properties = {
        state: {type: String, reflect: true},
        _height: {state: true},
    };

    constructor() {
        super();
        this.chat = new ChatCoreController(this);
        this.state = PANEL_STATES.HIDDEN;
        this._height = DEFAULT_HEIGHT;
        this._storageKey = 'ai-chat-panel';
        this._restoreState();
    }

    // Panel state management
    toggle() { /* hidden ↔ last visible state */ }
    collapse() { /* any → collapsed */ }
    expand() { /* collapsed → expanded */ }
    maximize() { /* expanded ↔ maximized */ }
    hide() { /* any → hidden */ }

    // Resize drag handling (mouse + touch)
    _onResizeStart(e) { /* ... */ }
    _onResizeMove(e) { /* ... */ }
    _onResizeEnd(e) { /* ... */ }

    // localStorage persistence
    _saveState() { /* ... */ }
    _restoreState() { /* ... */ }

    // ChatCoreController callback hooks
    onScrollToBottom(force) { /* ... */ }
    onFocusInput() { /* ... */ }
    onResetInput() { /* ... */ }

    // Keyboard handling
    _onKeydown(e) {
        if (e.key === 'Escape') this.collapse();
    }

    static styles = css`
        :host {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: calc(var(--typo3-zindex-modal-backdrop, 1050) - 10);
            font-family: var(--typo3-font-family, sans-serif);
            box-shadow: 0 -2px 8px rgba(0,0,0,0.15);
        }
        :host([state="hidden"]) { display: none; }
        /* ... collapsed, expanded, maximized styles ... */
        .panel-header { height: 36px; /* ... */ }
        .resize-handle { height: 4px; cursor: ns-resize; /* ... */ }
        .panel-body { flex: 1; display: flex; overflow: hidden; }
        /* ... conversation switcher, sidebar, messages, input ... */
    `;

    render() {
        if (this.state === PANEL_STATES.HIDDEN) return nothing;
        return html`
            <div class="resize-handle"
                @mousedown=${this._onResizeStart}
                @touchstart=${this._onResizeStart}
                role="separator"
                aria-orientation="horizontal"
                tabindex="0"></div>
            <div class="panel-header">
                <!-- title, status, minimize/maximize/close buttons -->
            </div>
            ${this.state !== PANEL_STATES.COLLAPSED ? html`
                <div class="panel-body">
                    ${this.state === PANEL_STATES.MAXIMIZED ? html`
                        <div class="panel-sidebar"><!-- conversation list --></div>
                    ` : nothing}
                    <div class="panel-main">
                        <!-- messages + input, compact switcher when expanded -->
                    </div>
                </div>
            ` : nothing}
        `;
    }
}
customElements.define('ai-chat-panel', AiChatPanel);
```

- [ ] **Step 2: Wire toolbar/chat-panel.js entry point**

```javascript
import './ai-chat-panel.js';

class ChatPanelToolbarInit {
    constructor() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this._init());
        } else {
            this._init();
        }
    }

    _init() {
        const btn = document.querySelector('.ai-chat-toolbar-btn');
        if (!btn) return;

        const panel = document.createElement('ai-chat-panel');
        document.body.appendChild(panel);

        btn.addEventListener('click', () => panel.toggle());

        // Update badge from panel's chat controller
        panel.addEventListener('badge-update', (e) => {
            const badge = btn.querySelector('.ai-chat-badge');
            if (!badge) return;
            const count = e.detail.count;
            badge.textContent = count;
            badge.style.display = count > 0 ? '' : 'none';
        });
    }
}

new ChatPanelToolbarInit();
```

- [ ] **Step 3: Manual verification in DDEV**

1. Log in to TYPO3 backend
2. Verify toolbar button appears (top right)
3. Click toolbar button → panel opens at bottom
4. Send a message → response appears
5. Navigate to Page module → panel stays open
6. Resize panel by dragging top edge
7. Collapse/expand/maximize transitions
8. Close panel, reload page → state restored

- [ ] **Step 4: Commit**

```
feat(nr-mcp-agent): add ai-chat-panel bottom panel component

New floating bottom panel for AI chat, attached to document.body.
Persists across module navigation. Four states: hidden, collapsed,
expanded, maximized with fluid resize. Full conversation management
including pin, archive, search in maximized view.
```

---

## Chunk 4: E2E Tests + Polish

### Task 6: Add Playwright E2E tests for panel

**Files:**
- Create: `packages/nr_mcp_agent/Tests/E2E/chat-panel.spec.js`

- [ ] **Step 1: Write E2E tests**

```javascript
import {test, expect} from '@playwright/test';

test.describe('AI Chat Panel', () => {
    test.beforeEach(async ({page}) => {
        await page.goto('/typo3/');
        // Login if needed
    });

    test('toolbar button opens panel', async ({page}) => {
        const btn = page.locator('.ai-chat-toolbar-btn');
        await expect(btn).toBeVisible();
        await btn.click();
        const panel = page.locator('ai-chat-panel');
        await expect(panel).toHaveAttribute('state', 'expanded');
    });

    test('panel persists across module navigation', async ({page}) => {
        // Open panel
        await page.locator('.ai-chat-toolbar-btn').click();
        // Navigate to different module
        await page.locator('[data-modulemenu-identifier="web_list"]').click();
        // Panel still visible
        const panel = page.locator('ai-chat-panel');
        await expect(panel).toBeVisible();
    });

    test('panel state persists after reload', async ({page}) => {
        await page.locator('.ai-chat-toolbar-btn').click();
        await page.reload();
        const panel = page.locator('ai-chat-panel');
        await expect(panel).toHaveAttribute('state', 'expanded');
    });

    test('escape key collapses panel', async ({page}) => {
        await page.locator('.ai-chat-toolbar-btn').click();
        await page.keyboard.press('Escape');
        const panel = page.locator('ai-chat-panel');
        await expect(panel).toHaveAttribute('state', 'collapsed');
    });

    test('send message and receive response in panel', async ({page}) => {
        await page.locator('.ai-chat-toolbar-btn').click();
        // Create new conversation from panel
        await page.locator('ai-chat-panel .btn-new-conversation').click();
        // Type and send
        const input = page.locator('ai-chat-panel textarea');
        await input.fill('Hello');
        await page.locator('ai-chat-panel .btn-send').click();
        // Wait for response
        await expect(page.locator('ai-chat-panel .message.assistant')).toBeVisible({timeout: 30000});
    });

    test('badge updates with active conversations', async ({page}) => {
        await page.locator('.ai-chat-toolbar-btn').click();
        await page.locator('ai-chat-panel .btn-new-conversation').click();
        const input = page.locator('ai-chat-panel textarea');
        await input.fill('Test');
        await page.locator('ai-chat-panel .btn-send').click();
        // Badge should show during processing
        const badge = page.locator('.ai-chat-badge');
        await expect(badge).not.toHaveCSS('display', 'none');
    });
});
```

- [ ] **Step 2: Run E2E tests**

Run: `npx playwright test Tests/E2E/chat-panel.spec.js`

- [ ] **Step 3: Fix any failures and re-run**

- [ ] **Step 4: Commit**

```
test(nr-mcp-agent): add Playwright E2E tests for chat panel
```

---

### Task 7: Accessibility and final polish

**Files:**
- Modify: `packages/nr_mcp_agent/Resources/Public/JavaScript/ai-chat-panel.js`

- [ ] **Step 1: Add ARIA attributes**

Ensure the panel has:
- `role="complementary"` on the host
- `aria-label="AI Chat"`
- `aria-expanded` reflecting visibility
- Resize handle: `role="separator"`, `aria-orientation="horizontal"`
- Arrow Up/Down keyboard resize on the separator

- [ ] **Step 2: Add focus management**

- Panel open → focus moves to textarea (or first interactive element)
- Panel close/hide → focus returns to toolbar button
- Tab-trapping in maximized state (optional, nice-to-have)

- [ ] **Step 3: Manual accessibility check**

Test with keyboard-only navigation:
1. Tab to toolbar button, press Enter → panel opens, focus in textarea
2. Escape → panel collapses
3. Tab to resize handle, Arrow Up/Down → panel resizes

- [ ] **Step 4: Commit**

```
fix(nr-mcp-agent): add accessibility attributes and focus management to chat panel
```

---

### Task 8: Documentation update

**Files:**
- Modify: `packages/nr_mcp_agent/Documentation/Configuration/Index.rst`
- Modify: `packages/nr_mcp_agent/Documentation/Developer/Architecture.rst`
- Modify: `packages/nr_mcp_agent/README.md`

- [ ] **Step 1: Update Architecture.rst**

Add the panel to the system overview diagram and document the toolbar integration.

- [ ] **Step 2: Update Configuration/Index.rst**

Note that the chat panel appears automatically in the TYPO3 toolbar when a valid `llmTaskUid` is configured.

- [ ] **Step 3: Update README.md**

Add panel to features list.

- [ ] **Step 4: Commit**

```
docs(nr-mcp-agent): document chat panel toolbar integration
```

---

## Summary

| Task | Description | New Files | Modified Files |
|------|-------------|-----------|----------------|
| 1 | Extend getStatus() API | — | ChatApiController.php, test |
| 2 | ChatToolbarItem PHP class | ChatToolbarItem.php, test | — |
| 3 | Extract ChatCoreController | chat-core.js | chat-app.js |
| 4 | Register import map tag | toolbar/chat-panel.js (stub) | JavaScriptModules.php |
| 5 | Build `<ai-chat-panel>` | ai-chat-panel.js | toolbar/chat-panel.js |
| 6 | E2E tests | chat-panel.spec.js | — |
| 7 | Accessibility polish | — | ai-chat-panel.js |
| 8 | Documentation | — | Architecture.rst, Config, README |
