# Conversation Tab Rename Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let users rename a conversation by double-clicking its title in the tab bar — an inline input appears, Enter/blur saves via API.

**Architecture:** Three-layer change: (1) a new `updateTitle()` repository method + `renameConversation()` controller action + Ajax route on the backend, (2) a `renameConversation()` method on the API client, (3) inline-edit UX in the tab bar rendered by `ai-chat-panel.js`, wired through `handleRename()` in `chat-core.js`.

**Tech Stack:** PHP 8.2+, TYPO3 13/14 Ajax routes, Lit 3 (html template literals), vanilla JS double-click / `contenteditable` input pattern.

---

## File Map

| File | Change |
|------|--------|
| `Classes/Domain/Repository/ConversationRepository.php` | Add `updateTitle(int $uid, string $title, int $beUserUid): void` |
| `Classes/Controller/ChatApiController.php` | Add `renameConversation(ServerRequestInterface): ResponseInterface` |
| `Configuration/Backend/AjaxRoutes.php` | Add `ai_chat_conversation_rename` route |
| `Resources/Public/JavaScript/api-client.js` | Add `renameConversation(uid, title)` |
| `Resources/Public/JavaScript/chat-core.js` | Add `handleRename(uid, title)`, update local state |
| `Resources/Public/JavaScript/ai-chat-panel.js` | Inline-edit UX in `_renderConvTabs()` |
| `Tests/Unit/Controller/ChatApiControllerTest.php` | Tests for `renameConversation` action |

---

### Task 1: Repository method `updateTitle`

**Files:**
- Modify: `Classes/Domain/Repository/ConversationRepository.php:142-149` (after `updatePinned`)

- [ ] **Step 1: Write the failing test**

Open `Tests/Unit/Controller/ChatApiControllerTest.php`. Add after the `togglePin` tests:

```php
#[Test]
public function renameConversationCallsUpdateTitleWithCorrectArguments(): void
{
    $conversation = Conversation::fromRow([
        'uid' => 7,
        'be_user' => 1,
    ]);
    $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
    $this->repository->expects(self::once())
        ->method('updateTitle')
        ->with(7, 'My new title', 1);

    $request = $this->createRequest('POST', '{"conversationUid": 7, "title": "My new title"}');
    $response = $this->subject->renameConversation($request);

    self::assertSame(200, $response->getStatusCode());
    $data = json_decode((string) $response->getBody(), true);
    self::assertSame('My new title', $data['title']);
}

#[Test]
public function renameConversationReturns404WhenConversationNotFound(): void
{
    $this->repository->method('findOneByUidAndBeUser')->willReturn(null);

    $request = $this->createRequest('POST', '{"conversationUid": 99, "title": "X"}');
    $response = $this->subject->renameConversation($request);

    self::assertSame(404, $response->getStatusCode());
}

#[Test]
public function renameConversationReturns400WhenTitleIsEmpty(): void
{
    $conversation = Conversation::fromRow(['uid' => 7, 'be_user' => 1]);
    $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);

    $request = $this->createRequest('POST', '{"conversationUid": 7, "title": "   "}');
    $response = $this->subject->renameConversation($request);

    self::assertSame(400, $response->getStatusCode());
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
Build/Scripts/runTests.sh -s unit 2>&1 | grep -A3 "renameConversation"
```

Expected: 3 failures — `renameConversation` method does not exist.

- [ ] **Step 3: Add `updateTitle` to repository**

In `Classes/Domain/Repository/ConversationRepository.php`, add after `updatePinned()`:

```php
/**
 * Lightweight title update — avoids reading/writing the full messages blob.
 */
public function updateTitle(int $uid, string $title, int $beUserUid): void
{
    $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
    $conn->update(self::TABLE, [
        'title'  => $title,
        'tstamp' => time(),
    ], ['uid' => $uid, 'be_user' => $beUserUid]);
}
```

- [ ] **Step 4: Add `renameConversation` action to controller**

In `Classes/Controller/ChatApiController.php`, add after `togglePin()`:

```php
/**
 * POST /ai-chat/conversations/rename
 */
public function renameConversation(ServerRequestInterface $request): ResponseInterface
{
    $accessDenied = $this->checkAccess();
    if ($accessDenied !== null) {
        return $accessDenied;
    }

    // Parse body once — PSR-7 streams are one-shot; passing $body to
    // findConversationOrFail avoids reading the stream a second time.
    $body = $this->parseBody($request);
    $conversation = $this->findConversationOrFail($request, $body);
    if ($conversation instanceof ResponseInterface) {
        return $conversation;
    }

    $title = trim((string) ($body['title'] ?? ''));
    if ($title === '') {
        return new JsonResponse(['error' => 'Title must not be empty'], 400);
    }

    $this->repository->updateTitle($conversation->getUid(), $title, $this->getBeUserUid());

    return new JsonResponse(['title' => $title]);
}
```

- [ ] **Step 5: Add Ajax route**

In `Configuration/Backend/AjaxRoutes.php`, add after the `ai_chat_conversation_pin` entry:

```php
'ai_chat_conversation_rename' => [
    'path'    => '/ai-chat/conversations/rename',
    'target'  => ChatApiController::class . '::renameConversation',
    'methods' => ['POST'],
],
```

- [ ] **Step 6: Run tests to verify they pass**

```bash
Build/Scripts/runTests.sh -s unit 2>&1 | tail -5
```

Expected: `SUCCESS`

- [ ] **Step 7: Commit**

```bash
git add Classes/Domain/Repository/ConversationRepository.php \
        Classes/Controller/ChatApiController.php \
        Configuration/Backend/AjaxRoutes.php \
        Tests/Unit/Controller/ChatApiControllerTest.php
git commit -m "feat(api): add rename conversation endpoint"
```

---

### Task 2: API client + chat-core wiring

**Files:**
- Modify: `Resources/Public/JavaScript/api-client.js`
- Modify: `Resources/Public/JavaScript/chat-core.js`

- [ ] **Step 1: Add `renameConversation` to API client**

In `Resources/Public/JavaScript/api-client.js`, add after `archiveConversation()`:

```js
async renameConversation(conversationUid, title) {
    return this._post('ai_chat_conversation_rename', {conversationUid, title});
}
```

- [ ] **Step 2: Add `handleRename` to chat-core**

In `Resources/Public/JavaScript/chat-core.js`, add after `handleArchive()`:

```js
async handleRename(uid, title) {
    const trimmed = title.trim();
    if (!trimmed) return;
    try {
        await this._api.renameConversation(uid, trimmed);
        this.conversations = this.conversations.map(c =>
            c.uid === uid ? {...c, title: trimmed} : c,
        );
        this.host.requestUpdate();
    } catch (e) {
        this.errorMessage = e.message;
        this.host.requestUpdate();
    }
}
```

- [ ] **Step 3: Verify syntax**

```bash
node --check Resources/Public/JavaScript/api-client.js && \
node --check Resources/Public/JavaScript/chat-core.js && echo ok
```

Expected: `ok`

- [ ] **Step 4: Commit**

```bash
git add Resources/Public/JavaScript/api-client.js \
        Resources/Public/JavaScript/chat-core.js
git commit -m "feat(ui): wire rename through api-client and chat-core"
```

---

### Task 3: Inline-edit UX in tab bar

**Files:**
- Modify: `Resources/Public/JavaScript/ai-chat-panel.js`

The pattern: double-click on `.tab-title` → replace the span with a focused `<input>` → Enter or blur → call `handleRename()` → re-render restores the span.

- [ ] **Step 1: Add `_renamingUid` as reactive state property**

In `ai-chat-panel.js`, find `static properties = {` near the top of the `AiChatPanel` class and add `_renamingUid` to it:

```js
static properties = {
    // ... existing entries ...
    _renamingUid: {state: true},
};
```

This makes Lit track changes automatically — no manual `requestUpdate()` needed when assigning `this._renamingUid`.

- [ ] **Step 2: Replace `_renderConvTabs` with inline-edit version**

Replace the existing `_renderConvTabs()` method with:

```js
_renderConvTabs() {
    if (this.chat.conversations.length === 0) return nothing;
    return html`
        <div class="conv-tabs" role="tablist" aria-label="${lll('conversations.title')}">
            ${this.chat.conversations.map(c => {
                const isActive = c.uid === this.chat.activeUid;
                const isRenaming = this._renamingUid === c.uid;
                const icon = STATUS_ICONS[c.status] ?? '';
                const title = c.title || lll('conversations.newConversation');
                return html`
                    <button class="conv-tab ${isActive ? 'active' : ''}"
                            role="tab"
                            aria-selected="${isActive}"
                            title="${title} (${c.status})"
                            @click=${() => this.chat.selectConversation(c.uid)}>
                        <span class="tab-icon status-${c.status}">${icon}</span>
                        ${isRenaming ? html`
                            <input class="tab-rename-input"
                                   .value=${title}
                                   @click=${(e) => e.stopPropagation()}
                                   @keydown=${(e) => {
                                       if (e.key === 'Enter') { e.preventDefault(); this._commitRename(c.uid, e.target.value); }
                                       if (e.key === 'Escape') { e.stopPropagation(); this._renamingUid = null; this.requestUpdate(); }
                                   }}
                                   @blur=${(e) => this._commitRename(c.uid, e.target.value)}
                                   ${ref((el) => el && requestAnimationFrame(() => { el.select(); }))}
                            />
                        ` : html`
                            <span class="tab-title"
                                  @dblclick=${(e) => { e.stopPropagation(); this._renamingUid = c.uid; this.requestUpdate(); }}>
                                ${title}
                            </span>
                        `}
                        <span class="tab-close"
                              title="${lll('conversations.archive')}"
                              @click=${(e) => { e.stopPropagation(); this.chat.handleArchive(c.uid); }}>✕</span>
                    </button>
                `;
            })}
        </div>
    `;
}

_commitRename(uid, value) {
    this._renamingUid = null;
    this.chat.handleRename(uid, value);  // no-op if empty (chat-core trims and guards)
    this.requestUpdate();
}
```

- [ ] **Step 3: Add CSS for the inline input**

In the `<style>` block, after the `.conv-tab .tab-close:hover` rule, add:

```css
.conv-tab .tab-rename-input {
    width: 90px;
    padding: 1px 4px;
    font-size: 12px;
    border: 1px solid var(--typo3-primary, #0078d4);
    border-radius: 3px;
    outline: none;
    background: var(--typo3-surface-container-lowest, #fff);
    color: var(--typo3-text-color, #333);
}
```

- [ ] **Step 4: Import `ref` from Lit**

Check whether `ref` is already imported:

```bash
grep "from 'lit/directives/ref" Resources/Public/JavaScript/ai-chat-panel.js
```

If not present, add at the top of the file:

```js
import {ref} from 'lit/directives/ref.js';
```

Do **not** import `createRef` — the callback form of `ref` used in the template does not need it.

- [ ] **Step 5: Verify syntax**

```bash
node --check Resources/Public/JavaScript/ai-chat-panel.js && echo ok
```

Expected: `ok`

- [ ] **Step 6: Run unit tests**

```bash
Build/Scripts/runTests.sh -s unit 2>&1 | tail -5
```

Expected: `SUCCESS`

- [ ] **Step 7: Commit**

```bash
git add Resources/Public/JavaScript/ai-chat-panel.js
git commit -m "feat(ui): double-click tab title to rename conversation inline"
```
