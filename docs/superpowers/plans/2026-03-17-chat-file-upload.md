# Chat File Upload Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add file upload (images + PDFs) to the AI chat, with files stored in TYPO3 FAL and sent as Base64 multimodal content to the LLM.

**Architecture:** Two file flows (local upload → FAL, FAL browser → UID) converge at `sendMessage` with a `fileUid`. Backend reads files at LLM call time and builds multimodal content arrays. Frontend adds a "+" menu with upload and FAL browser options.

**Tech Stack:** PHP 8.4, TYPO3 v13 FAL API, Lit 3.x, Playwright E2E

**Spec:** `docs/superpowers/specs/2026-03-16-chat-file-upload-design.md`

**Prerequisite:** nr-llm PR #115 (multimodal content support) — Tasks 1-5 can be built without it. Task 6 (`buildLlmMessages`) needs it.

---

## File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `Configuration/Backend/AjaxRoutes.php` | Modify | Add `ai_chat_file_upload` route |
| `Classes/Controller/ChatApiController.php` | Modify | Add `fileUpload()` action, extend `sendMessage()` + `getStatus()` |
| `Classes/Service/ChatService.php` | Modify | Add `buildLlmMessages()` + `buildFileContentBlock()` |
| `Resources/Private/Language/locallang_chat.xlf` | Modify | Add attachment.* keys |
| `Resources/Private/Language/de.locallang_chat.xlf` | Modify | Add attachment.* DE translations |
| `Resources/Public/JavaScript/chat-core.js` | Modify | Add file state + attachment counting |
| `Resources/Public/JavaScript/api-client.js` | Modify | Add `_postFormData()` + `uploadFile()` |
| `Resources/Public/JavaScript/ai-chat-panel.js` | Modify | Add "+" menu, file badge, upload flow |
| `Resources/Public/JavaScript/chat-app.js` | Modify | Same "+" menu for full-page module |
| `Tests/Unit/Controller/ChatApiControllerTest.php` | Modify | Upload + sendMessage file tests |
| `Tests/Unit/Service/ChatServiceTest.php` | Modify | buildLlmMessages tests |
| `Build/tests/playwright/specs/chat-file-upload.spec.ts` | Create | E2E tests for upload UI |

---

## Chunk 1: Backend API (no nr-llm dependency)

### Task 1: Add file upload AJAX route + endpoint

**Files:**
- Modify: `packages/nr_mcp_agent/Configuration/Backend/AjaxRoutes.php`
- Modify: `packages/nr_mcp_agent/Classes/Controller/ChatApiController.php`
- Test: `packages/nr_mcp_agent/Tests/Unit/Controller/ChatApiControllerTest.php`

- [ ] **Step 1: Write failing tests**

Add to `ChatApiControllerTest.php`:

```php
#[Test]
public function fileUploadRejectsInvalidMimeType(): void
{
    // Mock request with uploaded file that has wrong MIME type
    // Verify 400 response with 'File type not supported'
}

#[Test]
public function fileUploadRejectsOversizedFile(): void
{
    // Mock request with file exceeding 20MB
    // Verify 400 response with 'File too large'
}
```

Note: Full FAL integration tests need functional test infrastructure. Unit tests focus on validation logic.

- [ ] **Step 2: Run tests — expect FAIL**

Run: `cd packages/nr_mcp_agent && composer exec -- phpunit -c phpunit.xml --filter="fileUpload"`

- [ ] **Step 3: Add route to AjaxRoutes.php**

```php
'ai_chat_file_upload' => [
    'path' => '/ai-chat/file-upload',
    'target' => ChatApiController::class . '::fileUpload',
    'methods' => ['POST'],
],
```

- [ ] **Step 4: Implement `fileUpload()` in ChatApiController**

```php
public function fileUpload(ServerRequestInterface $request): ResponseInterface
{
    $accessDenied = $this->checkAccess();
    if ($accessDenied !== null) {
        return $accessDenied;
    }

    $uploadedFiles = $request->getUploadedFiles();
    $file = $uploadedFiles['file'] ?? null;

    if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
        return new JsonResponse(['error' => 'No file uploaded'], 400);
    }

    $allowedMimeTypes = [
        'application/pdf',
        'image/png', 'image/jpeg', 'image/webp',
    ];

    $mimeType = $file->getClientMediaType();
    if (!in_array($mimeType, $allowedMimeTypes, true)) {
        return new JsonResponse(['error' => 'File type not supported'], 400);
    }

    $maxSize = 20 * 1024 * 1024; // 20MB
    if ($file->getSize() > $maxSize) {
        return new JsonResponse(['error' => 'File too large (max 20MB)'], 400);
    }

    // Store in FAL
    $beUserUid = $this->getBeUserUid();
    $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
    $storage = $resourceFactory->getDefaultStorage();
    $targetFolder = $this->getOrCreateUploadFolder($storage, $beUserUid);

    $tempPath = $file->getStream()->getMetadata('uri');
    $falFile = $storage->addFile(
        $tempPath,
        $targetFolder,
        $file->getClientFilename(),
    );

    return new JsonResponse([
        'fileUid' => $falFile->getUid(),
        'name' => $falFile->getName(),
        'mimeType' => $falFile->getMimeType(),
        'size' => $falFile->getSize(),
    ]);
}

private function getOrCreateUploadFolder(
    ResourceStorage $storage,
    int $beUserUid,
): Folder {
    $basePath = 'ai-chat/' . $beUserUid;
    if (!$storage->hasFolder($basePath)) {
        return $storage->createFolder($basePath);
    }
    return $storage->getFolder($basePath);
}
```

- [ ] **Step 5: Add `.htaccess` deny rule**

Create `packages/nr_mcp_agent/Resources/Private/htaccess-ai-chat`:
```
# Deny direct access to uploaded chat files
Require all denied
```

Document in README that this must be deployed to `fileadmin/ai-chat/`.

- [ ] **Step 6: Run tests — expect PASS**

- [ ] **Step 7: Run full test suite**

Run: `cd packages/nr_mcp_agent && composer exec -- phpunit -c phpunit.xml`

- [ ] **Step 8: Commit**

```
feat(nr-mcp-agent): add file upload endpoint with FAL storage
```

---

### Task 2: Extend `sendMessage` with `fileUid` support

**Files:**
- Modify: `packages/nr_mcp_agent/Classes/Controller/ChatApiController.php`
- Modify: `packages/nr_mcp_agent/Classes/Domain/Model/Conversation.php`
- Test: `packages/nr_mcp_agent/Tests/Unit/Controller/ChatApiControllerTest.php`

- [ ] **Step 1: Write failing tests**

```php
#[Test]
public function sendMessageWithFileUidStoresFileMetadata(): void
{
    // Send message with fileUid, verify the stored message contains fileUid + fileName
}

#[Test]
public function sendMessageRejectsWhenFileLimitExceeded(): void
{
    // Conversation already has 5 messages with fileUid
    // Send another with fileUid — verify 400 response
}
```

- [ ] **Step 2: Implement**

In `sendMessage()`, after content validation:

```php
$fileUid = isset($body['fileUid']) ? (int) $body['fileUid'] : null;
$fileName = null;
$fileMimeType = null;

if ($fileUid !== null) {
    // Validate file exists
    try {
        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        $file = $resourceFactory->getFileObject($fileUid);
        $fileName = $file->getName();
        $fileMimeType = $file->getMimeType();
    } catch (\Exception) {
        return new JsonResponse(['error' => 'File not found'], 404);
    }

    // Check conversation file limit (max 5)
    $existingFileCount = $this->countFilesInConversation($conversation);
    if ($existingFileCount >= 5) {
        return new JsonResponse(['error' => 'Maximum 5 files per conversation reached'], 400);
    }
}
```

Add method to count files:

```php
private function countFilesInConversation(Conversation $conversation): int
{
    $messages = $conversation->getDecodedMessages();
    return count(array_filter($messages, fn(array $msg) => isset($msg['fileUid'])));
}
```

Extend `appendMessage` to accept file metadata or add a new method `appendMessageWithFile`:

```php
// In sendMessage(), replace the simple appendMessage:
if ($fileUid !== null) {
    $messages = $conversation->getDecodedMessages();
    $messages[] = [
        'role' => 'user',
        'content' => $content,
        'fileUid' => $fileUid,
        'fileName' => $fileName,
        'fileMimeType' => $fileMimeType,
    ];
    $conversation->setMessages($messages);
    $conversation->setMessageCount(count($messages));
} else {
    $conversation->appendMessage(MessageRole::User, $content);
}
```

- [ ] **Step 3: Run tests — expect PASS**

- [ ] **Step 4: Commit**

```
feat(nr-mcp-agent): extend sendMessage with fileUid support
```

---

### Task 3: Extend `getStatus` with vision capabilities

**Files:**
- Modify: `packages/nr_mcp_agent/Classes/Controller/ChatApiController.php`
- Modify: `packages/nr_mcp_agent/Classes/Service/ChatService.php`
- Test: `packages/nr_mcp_agent/Tests/Unit/Controller/ChatApiControllerTest.php`

- [ ] **Step 1: Write failing test**

```php
#[Test]
public function getStatusIncludesVisionCapabilities(): void
{
    // Mock resolveProvider returning a VisionCapableInterface mock
    // Verify response includes visionSupported, maxFileSize, supportedFormats
}
```

- [ ] **Step 2: Implement**

The `getStatus()` endpoint needs to know if the provider supports vision. This requires resolving the provider — but `resolveProvider()` is in `ChatService`. Add a method to `ChatService` that returns capabilities:

```php
// ChatService
public function getProviderCapabilities(): array
{
    try {
        $provider = $this->resolveProvider();
        if ($provider instanceof VisionCapableInterface && $provider->supportsVision()) {
            return [
                'visionSupported' => true,
                'maxFileSize' => $provider->getMaxImageSize(),
                'supportedFormats' => array_merge(
                    $provider->getSupportedImageFormats(),
                    ['pdf'],
                ),
            ];
        }
    } catch (\Throwable) {
        // Provider resolution failed — no vision support
    }

    return [
        'visionSupported' => false,
        'maxFileSize' => 0,
        'supportedFormats' => [],
    ];
}
```

In `ChatApiController::getStatus()`, add to the response:

```php
$capabilities = $this->chatService->getProviderCapabilities();

return new JsonResponse([
    'available' => $taskUid > 0,
    'mcpEnabled' => $mcpEnabled,
    'activeConversationCount' => $this->repository->countActiveByBeUser($this->getBeUserUid()),
    'issues' => $issues,
    ...$capabilities,
]);
```

Note: `ChatApiController` needs `ChatService` injected. Currently it only has `ChatProcessorInterface`. Add `ChatService` to the constructor.

- [ ] **Step 3: Run tests — expect PASS**

- [ ] **Step 4: Commit**

```
feat(nr-mcp-agent): add vision capabilities to getStatus response
```

---

## Chunk 2: Multimodal Message Building (needs nr-llm PR #115)

### Task 4: Add `buildLlmMessages()` to ChatService

**Files:**
- Modify: `packages/nr_mcp_agent/Classes/Service/ChatService.php`
- Test: `packages/nr_mcp_agent/Tests/Unit/Service/ChatServiceTest.php`

- [ ] **Step 1: Write failing tests**

```php
#[Test]
public function buildLlmMessagesConvertsImageFileToMultimodal(): void
{
    // Message with fileUid pointing to a PNG file
    // Verify output has content array with text + image_url blocks
}

#[Test]
public function buildLlmMessagesConvertsPdfToDocumentBlock(): void
{
    // Message with fileUid pointing to a PDF
    // Verify output has content array with text + document blocks
}

#[Test]
public function buildLlmMessagesHandlesMissingFile(): void
{
    // Message with fileUid but file deleted from FAL
    // Verify output has text-only message with "[file no longer available]" note
}

#[Test]
public function buildLlmMessagesPassesThroughRegularMessages(): void
{
    // Messages without fileUid are passed through unchanged
}
```

- [ ] **Step 2: Implement `buildLlmMessages()` and `buildFileContentBlock()`**

Add `ResourceFactory` to `ChatService` constructor (DI).

```php
private function buildLlmMessages(array $messages): array
{
    $result = [];
    foreach ($messages as $msg) {
        if (!isset($msg['fileUid'])) {
            $result[] = $msg;
            continue;
        }

        try {
            $file = $this->resourceFactory->getFileObject((int) $msg['fileUid']);
            $base64 = base64_encode(file_get_contents($file->getForLocalProcessing()));
            $mimeType = $file->getMimeType();

            $result[] = [
                'role' => $msg['role'],
                'content' => [
                    ['type' => 'text', 'text' => $msg['content'] ?? ''],
                    $this->buildFileContentBlock($mimeType, $base64),
                ],
            ];
        } catch (\Exception) {
            $fileName = $msg['fileName'] ?? 'unknown';
            $result[] = [
                'role' => $msg['role'],
                'content' => ($msg['content'] ?? '') . "\n\n[Attached file '" . $fileName . "' is no longer available]",
            ];
        }
    }
    return $result;
}

private function buildFileContentBlock(string $mimeType, string $base64): array
{
    if (str_starts_with($mimeType, 'image/')) {
        return [
            'type' => 'image_url',
            'image_url' => ['url' => 'data:' . $mimeType . ';base64,' . $base64],
        ];
    }
    return [
        'type' => 'document',
        'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => $base64],
    ];
}
```

- [ ] **Step 3: Integrate into `runSimpleChat()` and `runAgentLoop()`**

Replace `$messages = $conversation->getDecodedMessages()` with:
```php
$messages = $this->buildLlmMessages($conversation->getDecodedMessages());
```

- [ ] **Step 4: Run tests — expect PASS**

- [ ] **Step 5: Run full test suite**

- [ ] **Step 6: Commit**

```
feat(nr-mcp-agent): build multimodal content arrays from file attachments
```

---

## Chunk 3: Frontend

### Task 5: Add `uploadFile()` and `_postFormData()` to ApiClient

**Files:**
- Modify: `packages/nr_mcp_agent/Resources/Public/JavaScript/api-client.js`

- [ ] **Step 1: Add `_postFormData()` method**

```javascript
async _postFormData(routeName, formData) {
    const res = await fetch(this._url(routeName), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Accept': 'application/json'},
        body: formData,
        signal: this._signal,
    });
    return this._handleResponse(res);
}
```

Note: No `Content-Type` header — browser sets `multipart/form-data` boundary automatically.

- [ ] **Step 2: Add `uploadFile()` method**

```javascript
/** @returns {Promise<{fileUid: number, name: string, mimeType: string, size: number}>} */
async uploadFile(file) {
    const formData = new FormData();
    formData.append('file', file);
    return this._postFormData('ai_chat_file_upload', formData);
}
```

- [ ] **Step 3: Extend `sendMessage()` signature**

```javascript
async sendMessage(conversationUid, content, fileUid = null) {
    const body = {conversationUid, content};
    if (fileUid !== null) {
        body.fileUid = fileUid;
    }
    return this._post('ai_chat_conversation_send', body);
}
```

- [ ] **Step 4: Commit**

```
feat(nr-mcp-agent): add file upload and multipart support to ApiClient
```

---

### Task 6: Add file state to ChatCoreController

**Files:**
- Modify: `packages/nr_mcp_agent/Resources/Public/JavaScript/chat-core.js`

- [ ] **Step 1: Add file state properties**

```javascript
// In ChatCoreController
pendingFile = null;      // {fileUid, name, mimeType} or null
visionSupported = false;
maxFileSize = 0;
supportedFormats = [];
```

- [ ] **Step 2: Parse vision capabilities in `init()`**

```javascript
async init() {
    const statusData = await this._api.getStatus();
    this.available = statusData.available;
    this.issues = statusData.issues || [];
    this.visionSupported = statusData.visionSupported || false;
    this.maxFileSize = statusData.maxFileSize || 0;
    this.supportedFormats = statusData.supportedFormats || [];
    // ...
}
```

- [ ] **Step 3: Extend `handleSend()` to include file**

```javascript
async handleSend() {
    const content = this.inputValue.trim();
    if (!content || this.sending || this.isProcessing()) return;
    // ... existing validation ...

    const fileUid = this.pendingFile?.fileUid || null;

    await this._api.sendMessage(this.activeUid, content, fileUid);

    // Optimistic: add user message with file info
    const msg = {role: 'user', content};
    if (this.pendingFile) {
        msg.fileUid = this.pendingFile.fileUid;
        msg.fileName = this.pendingFile.name;
        msg.fileMimeType = this.pendingFile.mimeType;
    }
    this.messages = [...this.messages, msg];
    this.pendingFile = null;
    // ... rest unchanged
}
```

- [ ] **Step 4: Add `handleFileUpload()` and `handleFileSelect()`**

```javascript
async handleFileUpload(file) {
    if (file.size > this.maxFileSize) {
        this.errorMessage = lll('attachment.tooLarge', Math.round(this.maxFileSize / 1024 / 1024));
        this.host.requestUpdate();
        return;
    }
    try {
        const result = await this._api.uploadFile(file);
        this.pendingFile = result;
        this.host.requestUpdate();
    } catch (e) {
        this.errorMessage = e.message;
        this.host.requestUpdate();
    }
}

handleFileSelect(fileUid, name, mimeType) {
    this.pendingFile = {fileUid, name, mimeType};
    this.host.requestUpdate();
}

clearPendingFile() {
    this.pendingFile = null;
    this.host.requestUpdate();
}
```

- [ ] **Step 5: Add file count check**

```javascript
canAttachFile() {
    if (!this.visionSupported) return false;
    const fileCount = this.messages.filter(m => m.fileUid).length;
    return fileCount < 5;
}
```

- [ ] **Step 6: Commit**

```
feat(nr-mcp-agent): add file state management to ChatCoreController
```

---

### Task 7: Add "+" menu and file badge to panel + module

**Files:**
- Modify: `packages/nr_mcp_agent/Resources/Public/JavaScript/ai-chat-panel.js`
- Modify: `packages/nr_mcp_agent/Resources/Public/JavaScript/chat-app.js`

- [ ] **Step 1: Add CSS for attachment menu and file badge**

```css
.attachment-menu { position: relative; }
.attachment-dropdown {
    position: absolute; bottom: 100%; left: 0;
    background: var(--typo3-surface-container-lowest, #fff);
    border: 1px solid var(--typo3-list-border-color, #ccc);
    border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.12);
    padding: 4px; min-width: 180px; z-index: 10;
}
.attachment-dropdown button {
    display: block; width: 100%; text-align: left;
    padding: 8px 12px; border: none; background: none;
    cursor: pointer; font-size: 13px; border-radius: 4px;
}
.attachment-dropdown button:hover { background: var(--typo3-state-hover, rgba(0,0,0,0.04)); }
.file-badge {
    display: flex; align-items: center; gap: 6px;
    padding: 4px 8px; margin: 4px 12px;
    background: var(--typo3-surface-container-low, #f5f5f5);
    border: 1px solid var(--typo3-list-border-color, #ccc);
    border-radius: 6px; font-size: 12px;
}
.file-badge .remove { cursor: pointer; opacity: 0.5; }
.file-badge .remove:hover { opacity: 1; }
```

- [ ] **Step 2: Add "+" menu render method**

```javascript
_renderAttachmentMenu() {
    if (!this.chat.visionSupported) {
        return html`
            <button class="btn-icon" disabled
                    title="${lll('attachment.notSupported')}">+</button>
        `;
    }
    return html`
        <div class="attachment-menu">
            <button class="btn-icon" @click=${() => this._attachMenuOpen = !this._attachMenuOpen}
                    ?disabled=${!this.chat.canAttachFile()}
                    title="${!this.chat.canAttachFile() ? lll('attachment.limitReached') : ''}">+</button>
            ${this._attachMenuOpen ? html`
                <div class="attachment-dropdown">
                    <button @click=${this._handleUploadClick}>📎 ${lll('attachment.upload')}</button>
                    <button @click=${this._handleFalBrowserClick}>📁 ${lll('attachment.fromFal')}</button>
                </div>
            ` : nothing}
        </div>
        <input type="file" accept=".pdf,.png,.jpg,.jpeg,.webp"
               style="display:none" @change=${this._handleFileSelected}>
    `;
}
```

- [ ] **Step 3: Add file badge render**

```javascript
_renderFileBadge() {
    if (!this.chat.pendingFile) return nothing;
    const icon = this.chat.pendingFile.mimeType?.startsWith('image/') ? '🖼️' : '📄';
    return html`
        <div class="file-badge">
            <span>${icon} ${this.chat.pendingFile.name}</span>
            <span class="remove" @click=${() => this.chat.clearPendingFile()}
                  title="${lll('attachment.remove')}">&times;</span>
        </div>
    `;
}
```

- [ ] **Step 4: Add event handlers**

```javascript
_handleUploadClick() {
    this._attachMenuOpen = false;
    this.renderRoot.querySelector('input[type="file"]').click();
}

async _handleFileSelected(e) {
    const file = e.target.files[0];
    if (!file) return;
    e.target.value = ''; // Reset for re-selection
    await this.chat.handleFileUpload(file);
}

_handleFalBrowserClick() {
    this._attachMenuOpen = false;
    // Open TYPO3 Element Browser in file mode
    top.TYPO3.Modal.advanced({
        type: top.TYPO3.Modal.types.iframe,
        content: top.TYPO3.settings.FormEngine.browserUrl
            + '&mode=file&bparams=|||allowed=' + this.chat.supportedFormats.join(','),
        size: top.TYPO3.Modal.sizes.large,
    });
}
```

- [ ] **Step 5: Integrate into input area render**

In `_renderInput()`, add "+" menu before textarea and file badge above input:

```javascript
_renderInput() {
    return html`
        ${this._renderFileBadge()}
        <div class="panel-input">
            ${this._renderAttachmentMenu()}
            <textarea ...></textarea>
            <button ...>Send</button>
        </div>
    `;
}
```

- [ ] **Step 6: Add file icon in message display**

In `_renderMessage()`, for user messages with `fileUid`:

```javascript
if (role === 'user' && msg.fileUid) {
    const icon = msg.fileMimeType?.startsWith('image/') ? '🖼️' : '📄';
    return html`
        <div class="message user">
            <div class="file-badge" style="margin-bottom:4px;">
                ${icon} ${msg.fileName || 'File'}
            </div>
            ${this.chat.renderMessageContent(msg)}
        </div>
    `;
}
```

- [ ] **Step 7: Apply same changes to `chat-app.js`**

Copy the "+" menu, file badge, and event handlers to the full-page module component.

- [ ] **Step 8: Commit**

```
feat(nr-mcp-agent): add file upload UI with "+" menu and file badge
```

---

## Chunk 4: Localization + Tests + Polish

### Task 8: Add localization keys

**Files:**
- Modify: `packages/nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf`
- Modify: `packages/nr_mcp_agent/Resources/Private/Language/de.locallang_chat.xlf`

- [ ] **Step 1: Add EN keys**

```xml
<!-- Attachments -->
<trans-unit id="attachment.upload" resname="attachment.upload">
    <source>Upload file</source>
</trans-unit>
<trans-unit id="attachment.fromFal" resname="attachment.fromFal">
    <source>From file list</source>
</trans-unit>
<trans-unit id="attachment.notSupported" resname="attachment.notSupported">
    <source>Your LLM model does not support file attachments</source>
</trans-unit>
<trans-unit id="attachment.tooLarge" resname="attachment.tooLarge">
    <source>File too large (max %d MB)</source>
</trans-unit>
<trans-unit id="attachment.limitReached" resname="attachment.limitReached">
    <source>Maximum 5 files per conversation reached</source>
</trans-unit>
<trans-unit id="attachment.invalidType" resname="attachment.invalidType">
    <source>File type not supported</source>
</trans-unit>
<trans-unit id="attachment.remove" resname="attachment.remove">
    <source>Remove attachment</source>
</trans-unit>
<trans-unit id="attachment.uploading" resname="attachment.uploading">
    <source>Uploading...</source>
</trans-unit>
<trans-unit id="attachment.fileMissing" resname="attachment.fileMissing">
    <source>File no longer available</source>
</trans-unit>
```

- [ ] **Step 2: Add DE translations**

```xml
<trans-unit id="attachment.upload"><source>Upload file</source><target>Datei hochladen</target></trans-unit>
<trans-unit id="attachment.fromFal"><source>From file list</source><target>Aus Dateiliste</target></trans-unit>
<trans-unit id="attachment.notSupported"><source>...</source><target>Ihr LLM-Modell unterstützt keine Dateianhänge</target></trans-unit>
<trans-unit id="attachment.tooLarge"><source>...</source><target>Datei zu groß (max. %d MB)</target></trans-unit>
<trans-unit id="attachment.limitReached"><source>...</source><target>Maximum 5 Dateien pro Chat erreicht</target></trans-unit>
<trans-unit id="attachment.invalidType"><source>...</source><target>Dateityp nicht unterstützt</target></trans-unit>
<trans-unit id="attachment.remove"><source>...</source><target>Anhang entfernen</target></trans-unit>
<trans-unit id="attachment.uploading"><source>...</source><target>Wird hochgeladen...</target></trans-unit>
<trans-unit id="attachment.fileMissing"><source>...</source><target>Datei nicht mehr verfügbar</target></trans-unit>
```

- [ ] **Step 3: Commit**

```
feat(nr-mcp-agent): add attachment localization keys (EN + DE)
```

---

### Task 9: E2E Tests

**Files:**
- Create: `packages/nr_mcp_agent/Build/tests/playwright/specs/chat-file-upload.spec.ts`

- [ ] **Step 1: Write E2E tests**

```typescript
test.describe('Chat File Upload', () => {
    test('"+" button visible when vision supported', async ({ page }) => {
        // Open panel, verify "+" button exists next to input
    });

    test('"+" button disabled with tooltip when not supported', async ({ page }) => {
        // If provider doesn't support vision, button should be disabled
    });

    test('file upload shows badge', async ({ page }) => {
        // Click "+", select "Upload file", pick a file
        // Verify file badge appears with filename
    });

    test('file badge remove clears attachment', async ({ page }) => {
        // After selecting file, click X on badge
        // Verify badge disappears
    });
});
```

- [ ] **Step 2: Commit**

```
test(nr-mcp-agent): add E2E tests for file upload UI
```

---

### Task 10: Documentation update

**Files:**
- Modify: `packages/nr_mcp_agent/Documentation/Configuration/Index.rst`
- Modify: `packages/nr_mcp_agent/Documentation/Developer/Architecture.rst`

- [ ] **Step 1: Add file upload section to docs**

Document the file upload feature, supported formats, limits, FAL storage location, and security considerations.

- [ ] **Step 2: Commit**

```
docs(nr-mcp-agent): document file upload feature
```

---

## Summary

| Task | Description | nr-llm needed? |
|------|-------------|----------------|
| 1 | FAL upload endpoint | No |
| 2 | sendMessage with fileUid | No |
| 3 | getStatus with vision capabilities | No* |
| 4 | buildLlmMessages multimodal | **Yes** |
| 5 | ApiClient uploadFile | No |
| 6 | ChatCoreController file state | No |
| 7 | Frontend "+" menu + file badge | No |
| 8 | Localization | No |
| 9 | E2E tests | No |
| 10 | Documentation | No |

*Task 3 needs `VisionCapableInterface` which is already in nr-llm — no PR #115 dependency.

**Tasks 1-3, 5-9 can start immediately.** Task 4 waits for nr-llm PR #115 merge.
