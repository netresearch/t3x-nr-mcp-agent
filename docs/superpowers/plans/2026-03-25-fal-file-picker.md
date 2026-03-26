# FAL File Picker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a dropdown to the chat attachment button so users can select existing TYPO3 FAL files as chat attachments instead of uploading a new file.

**Architecture:** A new GET endpoint `fileInfo` resolves any readable FAL file's metadata (name, mimeType, size) by UID. The `sendMessage` action is updated to accept any FAL file the backend user has read permission for (not just uploaded files). On the JS side, a TYPO3 Element Browser popup is opened when the user picks "Aus FAL wählen"; the popup calls back via `window.setFormValueFromBrowseWin` with the FAL UID, which feeds the existing `handleFileSelect()` path — unchanged from that point on.

**Tech Stack:** PHP 8.2, TYPO3 v13, PHPUnit 11 (unit tests via `Build/Scripts/runTests.sh -s unit`), Lit (web components, no automated unit tests — covered by manual acceptance test), Jest (`npm run test:js`). CGL check: `Build/Scripts/runTests.sh -s cgl -n`; CGL fix: `Build/Scripts/runTests.sh -s cgl`.

**Working directory:** All work in the worktree at `/srv/projects/nr-mcp-agent/.worktrees/feature/fal-file-picker/`

---

## File Map

| File | Change |
|------|--------|
| `Configuration/Backend/AjaxRoutes.php` | Add `ai_chat_file_info` route |
| `Classes/Controller/ChatApiController.php` | Add `fileInfo()` action; update `sendMessage()` folder check |
| `Tests/Unit/Controller/ChatApiControllerFileInfoTest.php` | **Create** — unit tests for `fileInfo()` |
| `Tests/Unit/Controller/ChatApiControllerTest.php` | Update 2 existing `sendMessage` tests for the relaxed folder check |
| `Resources/Public/JavaScript/icons.js` | Add `ICON_UPLOAD` |
| `Resources/Public/JavaScript/api-client.js` | Add `getFileInfo()` |
| `Resources/Public/JavaScript/chat-core.js` | Add `_setError()`, update `hostConnected()`, add `_openFalPicker()` + `_onFalFileSelected()` |
| `Resources/Public/JavaScript/ai-chat-panel.js` | Replace paperclip button with dropdown; `ICON_CHEVRON_DOWN` is already exported in `icons.js` |
| `Tests/JavaScript/fal-picker.test.js` | **Create** — Jest tests for `chat-core.js` FAL picker logic |
| `Resources/Private/Language/locallang.xlf` | Add `attachment.fal` translation key |
| `CHANGELOG.md` | Add entry |

---

## Task 1: Backend — `fileInfo` action (TDD)

**Files:**
- Modify: `Configuration/Backend/AjaxRoutes.php`
- Modify: `Classes/Controller/ChatApiController.php`
- Create: `Tests/Unit/Controller/ChatApiControllerFileInfoTest.php`

- [ ] **Step 1: Create the test file with failing tests**

Create `Tests/Unit/Controller/ChatApiControllerFileInfoTest.php`:

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Controller;

use InvalidArgumentException;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Controller\ChatApiController;
use Netresearch\NrMcpAgent\Document\DocumentExtractorInterface;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;

class ChatApiControllerFileInfoTest extends TestCase
{
    private ChatApiController $subject;
    private ResourceFactory $resourceFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);

        $chatService = $this->createMock(ChatCapabilitiesInterface::class);
        $chatService->method('getProviderCapabilities')->willReturn([
            'visionSupported' => false,
            'maxFileSize' => 0,
            'supportedFormats' => [],
        ]);

        $this->resourceFactory = $this->createMock(ResourceFactory::class);

        $extractor = $this->createMock(DocumentExtractorInterface::class);
        $extractor->method('isAvailable')->willReturn(true);
        $extractor->method('getSupportedMimeTypes')->willReturn(['application/pdf']);
        $extractor->method('getSupportedFileExtensions')->willReturn(['pdf']);

        $this->subject = new ChatApiController(
            $this->createMock(ConversationRepository::class),
            $this->createMock(ChatProcessorInterface::class),
            $config,
            $chatService,
            $this->resourceFactory,
            $this->createMock(StorageRepository::class),
            new DocumentExtractorRegistry([$extractor]),
        );

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->user = ['uid' => 1, 'usergroup' => ''];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    private function makeRequest(array $params = []): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn($params);
        return $request;
    }

    #[Test]
    public function fileInfoReturnsMissingFileUidAs400(): void
    {
        $response = $this->subject->fileInfo($this->makeRequest([]));
        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function fileInfoReturnsInvalidFileUidAs400(): void
    {
        $response = $this->subject->fileInfo($this->makeRequest(['fileUid' => 'abc']));
        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function fileInfoReturnsNotFoundWhenResourceFactoryThrows(): void
    {
        $this->resourceFactory
            ->method('getFileObject')
            ->willThrowException(new InvalidArgumentException());

        $response = $this->subject->fileInfo($this->makeRequest(['fileUid' => '99']));
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function fileInfoReturnsForbiddenWhenNoReadPermission(): void
    {
        $file = $this->createMock(File::class);
        $file->method('checkActionPermission')->with('read')->willReturn(false);

        $this->resourceFactory->method('getFileObject')->willReturn($file);

        $response = $this->subject->fileInfo($this->makeRequest(['fileUid' => '42']));
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function fileInfoReturnsUnsupportedTypeAs422(): void
    {
        $file = $this->createMock(File::class);
        $file->method('checkActionPermission')->with('read')->willReturn(true);
        $file->method('getExtension')->willReturn('exe');

        $this->resourceFactory->method('getFileObject')->willReturn($file);

        $response = $this->subject->fileInfo($this->makeRequest(['fileUid' => '42']));
        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function fileInfoReturnsFileMetadataOnSuccess(): void
    {
        $file = $this->createMock(File::class);
        $file->method('checkActionPermission')->with('read')->willReturn(true);
        $file->method('getExtension')->willReturn('pdf');
        $file->method('getUid')->willReturn(42);
        $file->method('getName')->willReturn('report.pdf');
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getSize')->willReturn(102400);

        $this->resourceFactory->method('getFileObject')->willReturn($file);

        $response = $this->subject->fileInfo($this->makeRequest(['fileUid' => '42']));
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(42, $body['fileUid']);
        self::assertSame('report.pdf', $body['name']);
        self::assertSame('application/pdf', $body['mimeType']);
        self::assertSame(102400, $body['size']);
    }
}
```

- [ ] **Step 2: Run the test — verify it fails**

```bash
Build/Scripts/runTests.sh -s unit -- --filter ChatApiControllerFileInfoTest 2>&1 | tail -15
```

Expected: FAIL — `Call to undefined method ChatApiController::fileInfo()`

- [ ] **Step 3: Register the route**

In `Configuration/Backend/AjaxRoutes.php`, add before the closing `];`:

```php
    'ai_chat_file_info' => [
        'path' => '/ai-chat/file-info',
        'target' => ChatApiController::class . '::fileInfo',
        'methods' => ['GET'],
    ],
```

- [ ] **Step 4: Implement `fileInfo()` in `ChatApiController`**

Add the following method after the `fileUpload()` method (the closing `}` of `fileUpload` is at line 325):

```php
    /**
     * GET /ai-chat/file-info?fileUid={uid} – Resolve FAL file metadata by UID.
     */
    public function fileInfo(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        /** @var array<string, string> $params */
        $params = $request->getQueryParams();
        $rawUid = $params['fileUid'] ?? '';

        if ($rawUid === '' || !ctype_digit((string) $rawUid) || (int) $rawUid <= 0) {
            return new JsonResponse(['error' => 'Invalid fileUid'], 400);
        }

        try {
            $file = $this->resourceFactory->getFileObject((int) $rawUid);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'File not found'], 404);
        }

        if (!$file->checkActionPermission('read')) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        if (!in_array($file->getExtension(), $this->documentExtractorRegistry->getAvailableExtensions(), true)) {
            return new JsonResponse(['error' => 'Unsupported file type'], 422);
        }

        return new JsonResponse([
            'fileUid'  => $file->getUid(),
            'name'     => $file->getName(),
            'mimeType' => $file->getMimeType(),
            'size'     => $file->getSize(),
        ]);
    }
```

- [ ] **Step 5: Run the test — verify it passes**

```bash
Build/Scripts/runTests.sh -s unit -- --filter ChatApiControllerFileInfoTest 2>&1 | tail -10
```

Expected: 6 tests, 6 assertions, 0 failures

- [ ] **Step 6: Run full unit suite to check for regressions**

```bash
Build/Scripts/runTests.sh -s unit 2>&1 | tail -5
```

Expected: OK

- [ ] **Step 7: Commit**

```bash
git add Configuration/Backend/AjaxRoutes.php Classes/Controller/ChatApiController.php Tests/Unit/Controller/ChatApiControllerFileInfoTest.php
git commit -m "feat(api): add fileInfo endpoint to resolve FAL file metadata by UID"
```

---

## Task 2: Update `sendMessage` to accept any readable FAL file (TDD)

**Background:** `sendMessage` currently restricts `fileUid` attachments to files in `/ai-chat/{userId}/`. FAL-selected files live elsewhere. Replace the folder path check with TYPO3's native `checkActionPermission('read')`.

**Exact tests to update in `Tests/Unit/Controller/ChatApiControllerTest.php`:**
1. `sendMessageWithFileUidStoresFileMetadata` — currently mocks `getIdentifier()` returning `/ai-chat/1/photo.png`; change the mock to use `checkActionPermission('read')` returning `true` instead
2. `sendMessageRejects404WhenFileDoesNotBelongToUser` — currently mocks `getIdentifier()` returning `/ai-chat/99/stolen.png`; change to mock `checkActionPermission('read')` returning `false`

**Files:**
- Modify: `Classes/Controller/ChatApiController.php`
- Modify: `Tests/Unit/Controller/ChatApiControllerTest.php`

- [ ] **Step 1: Write a new failing test**

In `Tests/Unit/Controller/ChatApiControllerTest.php`, verify `use Psr\Http\Message\StreamInterface;` is already in the imports (it is — check line ~22). Add this test:

```php
#[Test]
public function sendMessageAcceptsFalFileOutsideUploadFolder(): void
{
    $conversation = new Conversation();
    $this->repository->method('findOneByUidAndBeUser')->willReturn($conversation);
    $this->repository->method('countActiveByBeUser')->willReturn(0);

    $file = $this->createMock(File::class);
    $file->method('checkActionPermission')->with('read')->willReturn(true);
    $file->method('getName')->willReturn('document.pdf');
    $file->method('getMimeType')->willReturn('application/pdf');
    $this->resourceFactory->method('getFileObject')->willReturn($file);

    $request = $this->createRequest('POST', '{"conversationUid": 1, "content": "Check this", "fileUid": 42}');
    $response = $this->subject->sendMessage($request);

    self::assertSame(202, $response->getStatusCode());
}
```

- [ ] **Step 2: Run the test — verify it fails**

```bash
Build/Scripts/runTests.sh -s unit -- --filter sendMessageAcceptsFalFileOutsideUploadFolder 2>&1 | tail -10
```

Expected: FAIL — 404 (the folder check rejects a file without a mocked `getIdentifier()`)

- [ ] **Step 3: Update `sendMessage` in `ChatApiController.php`**

Locate the block (around line 196–207):
```php
try {
    $file = $this->resourceFactory->getFileObject($fileUid);
    // Verify that the file belongs to the current user
    $expectedFolder = '/ai-chat/' . $this->getBeUserUid() . '/';
    if (!str_starts_with($file->getIdentifier(), $expectedFolder)) {
        return new JsonResponse(['error' => 'File not found'], 404);
    }
    $fileName = $file->getName();
    $fileMimeType = $file->getMimeType();
} catch (Exception) {
    return new JsonResponse(['error' => 'File not found'], 404);
}
```

Replace with:
```php
try {
    $file = $this->resourceFactory->getFileObject($fileUid);
    if (!$file->checkActionPermission('read')) {
        return new JsonResponse(['error' => 'File not found'], 404);
    }
    $fileName = $file->getName();
    $fileMimeType = $file->getMimeType();
} catch (Exception) {
    return new JsonResponse(['error' => 'File not found'], 404);
}
```

- [ ] **Step 4: Run the new test — verify it passes**

```bash
Build/Scripts/runTests.sh -s unit -- --filter sendMessageAcceptsFalFileOutsideUploadFolder 2>&1 | tail -10
```

Expected: PASS

- [ ] **Step 5: Update the two existing tests**

In `Tests/Unit/Controller/ChatApiControllerTest.php`:

**Test `sendMessageWithFileUidStoresFileMetadata`** (around line 840): Remove `$mockFile->method('getIdentifier')->willReturn('/ai-chat/1/photo.png');` and add `$mockFile->method('checkActionPermission')->with('read')->willReturn(true);`

**Test `sendMessageRejects404WhenFileDoesNotBelongToUser`** (around line 865): Remove `$mockFile->method('getIdentifier')->willReturn('/ai-chat/99/stolen.png');` and add `$mockFile->method('checkActionPermission')->with('read')->willReturn(false);`. Also remove the now-outdated comment "File belongs to a different user".

- [ ] **Step 6: Run all `ChatApiControllerTest` tests**

```bash
Build/Scripts/runTests.sh -s unit -- --filter ChatApiControllerTest 2>&1 | tail -10
```

Expected: all pass, 0 failures

- [ ] **Step 7: Run full unit suite**

```bash
Build/Scripts/runTests.sh -s unit 2>&1 | tail -5
```

Expected: OK

- [ ] **Step 8: Commit**

```bash
git add Classes/Controller/ChatApiController.php Tests/Unit/Controller/ChatApiControllerTest.php
git commit -m "fix(api): accept any readable FAL file in sendMessage, not just uploaded ones"
```

---

## Task 3: Add `ICON_UPLOAD` to `icons.js`

**Files:**
- Modify: `Resources/Public/JavaScript/icons.js`

No separate test — single exported SVG constant, trivial.

- [ ] **Step 1: Add the icon**

In `Resources/Public/JavaScript/icons.js`, add after the last `export const` line:

```js
export const ICON_UPLOAD = (size = 16) => html`<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>`;
```

- [ ] **Step 2: Commit**

```bash
git add Resources/Public/JavaScript/icons.js
git commit -m "feat(ui): add ICON_UPLOAD to icons.js"
```

---

## Task 4: Add `getFileInfo` to `api-client.js`

**Files:**
- Modify: `Resources/Public/JavaScript/api-client.js`

- [ ] **Step 1: Add `getFileInfo` method**

In `Resources/Public/JavaScript/api-client.js`, add after the `uploadFile` method (after the closing `}` of `uploadFile` at line 62):

```js
    /**
     * @param {number} fileUid
     * @returns {Promise<{fileUid: number, name: string, mimeType: string, size: number}>}
     */
    async getFileInfo(fileUid) {
        return this._get('ai_chat_file_info', {fileUid});
    }
```

- [ ] **Step 2: Commit**

```bash
git add Resources/Public/JavaScript/api-client.js
git commit -m "feat(api-client): add getFileInfo method for FAL file metadata"
```

---

## Task 5: FAL picker logic in `chat-core.js` (TDD)

**Files:**
- Modify: `Resources/Public/JavaScript/chat-core.js`
- Create: `Tests/JavaScript/fal-picker.test.js`

**Note on `_setError`:** The spec incorrectly lists `this._setError(message)` as an "existing property" of ChatCore — it does not exist in `chat-core.js`. Confirm it is absent (search for `_setError`) and add it as described below.

- [ ] **Step 1: Write failing Jest tests**

Create `Tests/JavaScript/fal-picker.test.js`:

```js
/**
 * Tests for the FAL file picker logic in ChatCoreController.
 *
 * Covers _onFalFileSelected and _openFalPicker guard/error paths.
 * Window globals are manipulated directly on the global object.
 *
 * @jest-environment node
 */

import {ChatCoreController} from '../../Resources/Public/JavaScript/chat-core.js';

jest.mock('@typo3/core/lit-helper.js', () => ({lll: (k) => k}));

function makeHost() {
    return {
        addController: jest.fn(),
        requestUpdate: jest.fn(),
        addEventListener: jest.fn(),
        removeEventListener: jest.fn(),
    };
}

function makeController(host) {
    const ctrl = new ChatCoreController(host);
    ctrl._abortController = new AbortController();
    ctrl._api = {
        getStatus: jest.fn().mockResolvedValue({
            available: true, issues: [], visionSupported: false,
            maxFileSize: 0, supportedFormats: ['pdf', 'docx'],
        }),
        getFileInfo: jest.fn(),
        listConversations: jest.fn().mockResolvedValue({conversations: []}),
    };
    ctrl.supportedFormats = ['pdf', 'docx'];
    return ctrl;
}

// ── _onFalFileSelected ─────────────────────────────────────────────────────

describe('_onFalFileSelected', () => {
    test('calls getFileInfo then handleFileSelect on success', async () => {
        const host = makeHost();
        const ctrl = makeController(host);
        ctrl.handleFileSelect = jest.fn();
        ctrl._api.getFileInfo.mockResolvedValue({
            fileUid: 42, name: 'doc.pdf', mimeType: 'application/pdf', size: 1024,
        });

        await ctrl._onFalFileSelected(42);

        expect(ctrl._api.getFileInfo).toHaveBeenCalledWith(42);
        expect(ctrl.handleFileSelect).toHaveBeenCalledWith(42, 'doc.pdf', 'application/pdf');
    });

    test('calls _setError when getFileInfo rejects', async () => {
        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._api.getFileInfo.mockRejectedValue(new Error('File not found'));

        await ctrl._onFalFileSelected(99);

        expect(ctrl.issues).toContain('File not found');
    });
});

// ── _openFalPicker ─────────────────────────────────────────────────────────

describe('_openFalPicker', () => {
    let origOpen;

    beforeEach(() => {
        origOpen = global.open;
        delete global.setFormValueFromBrowseWin;
    });

    afterEach(() => {
        global.open = origOpen;
        delete global.setFormValueFromBrowseWin;
    });

    test('shows error and does not open popup when ajaxUrls file-browser key is missing', () => {
        // Simulate TYPO3 global without the file-browser key
        global.top = {TYPO3: {settings: {ajaxUrls: {}}}};
        global.open = jest.fn();

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._openFalPicker();

        expect(global.open).not.toHaveBeenCalled();
        expect(ctrl.issues.length).toBeGreaterThan(0);
    });

    test('returns early without opening second popup when callback already registered', () => {
        global.setFormValueFromBrowseWin = jest.fn(); // picker already open
        global.open = jest.fn();

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._openFalPicker();

        expect(global.open).not.toHaveBeenCalled();
    });

    test('shows error and cleans up callback when window.open returns null (popup blocked)', () => {
        global.top = {TYPO3: {settings: {ajaxUrls: {'file-browser': '/typo3/record/browse?mode=file'}}}};
        global.open = jest.fn().mockReturnValue(null);

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._openFalPicker();

        expect(ctrl.issues.length).toBeGreaterThan(0);
        expect(global.setFormValueFromBrowseWin).toBeUndefined();
    });

    test('opens popup with correct URL including bparams when ajaxUrl available', () => {
        global.top = {TYPO3: {settings: {ajaxUrls: {'file-browser': '/typo3/record/browse?mode=file'}}}};
        const popup = {closed: false};
        global.open = jest.fn().mockReturnValue(popup);

        const host = makeHost();
        const ctrl = makeController(host);
        ctrl._openFalPicker();

        expect(global.open).toHaveBeenCalledWith(
            expect.stringContaining('bparams='),
            'typo3FileBrowser',
            expect.any(String),
        );
        expect(typeof global.setFormValueFromBrowseWin).toBe('function');
    });
});
```

- [ ] **Step 2: Run Jest — verify tests fail**

```bash
npm run test:js -- Tests/JavaScript/fal-picker.test.js 2>&1 | tail -20
```

Expected: FAIL — `ctrl._openFalPicker is not a function`

- [ ] **Step 3: Add `_setError` to `chat-core.js`**

In `Resources/Public/JavaScript/chat-core.js`, add after `hostDisconnected()`:

```js
    /** @param {string} message */
    _setError(message) {
        this.issues = [message];
        this.host.requestUpdate();
    }
```

- [ ] **Step 4: Update `hostConnected` to add the event listener**

Find the existing `hostConnected()` method and add the `addEventListener` call after `this._api = ...` and **before** `this.init()` (so the signal is set before the listener is registered):

```js
    hostConnected() {
        this._abortController = new AbortController();
        this._api = new ApiClient(this._abortController.signal);
        this.host.addEventListener(
            'nr-mcp-open-fal-picker',
            () => this._openFalPicker(),
            {signal: this._abortController.signal},
        );
        this.init();
    }
```

- [ ] **Step 5: Add `_openFalPicker` method**

Add after `handleFileUpload` (around line 345):

```js
    _openFalPicker() {
        // Guard: picker already open
        if (typeof window.setFormValueFromBrowseWin === 'function') {
            return;
        }

        // TYPO3 registers the file browser URL in ajaxUrls under 'file-browser'
        const ajaxUrl = top.TYPO3?.settings?.ajaxUrls?.['file-browser'];
        if (!ajaxUrl) {
            this._setError('FAL-Picker ist nicht verfügbar');
            return;
        }

        const extensions = this.supportedFormats.join(',');
        // bparams format: fieldName|irreConfig|allowedTables|allowedExtensions
        // First three segments empty = not bound to any FormEngine field
        const bparams = encodeURIComponent('|||' + extensions);
        const url = ajaxUrl + '&bparams=' + bparams;

        window.setFormValueFromBrowseWin = (_fieldName, value, _label) => {
            delete window.setFormValueFromBrowseWin;
            if (value) {
                this._onFalFileSelected(parseInt(value, 10));
            }
        };

        const popup = window.open(url, 'typo3FileBrowser', 'height=600,width=900,status=0,menubar=0,scrollbars=1');
        if (!popup) {
            delete window.setFormValueFromBrowseWin;
            this._setError('Popup wurde blockiert. Bitte Popup-Blocker deaktivieren.');
        }
    }
```

- [ ] **Step 6: Add `_onFalFileSelected` method**

Add immediately after `_openFalPicker`:

```js
    /** @param {number} fileUid */
    async _onFalFileSelected(fileUid) {
        try {
            const result = await this._api.getFileInfo(fileUid);
            this.handleFileSelect(result.fileUid, result.name, result.mimeType);
        } catch (e) {
            this._setError(e.message);
        }
    }
```

- [ ] **Step 7: Run Jest — verify all tests pass**

```bash
npm run test:js -- Tests/JavaScript/fal-picker.test.js 2>&1 | tail -20
```

Expected: all tests pass

- [ ] **Step 8: Run full JS test suite**

```bash
npm run test:js 2>&1 | tail -10
```

Expected: all passing

- [ ] **Step 9: Commit**

```bash
git add Resources/Public/JavaScript/chat-core.js Tests/JavaScript/fal-picker.test.js
git commit -m "feat(chat-core): add FAL picker logic — _openFalPicker and _onFalFileSelected"
```

---

## Task 6: Dropdown UI in `ai-chat-panel.js`

**Files:**
- Modify: `Resources/Public/JavaScript/ai-chat-panel.js`
- Modify: `Resources/Private/Language/locallang.xlf`

Note: `ICON_CHEVRON_DOWN` is already exported in `icons.js` — confirm by checking `icons.js` for `export const ICON_CHEVRON_DOWN` before modifying the import.

**Note on spec JS unit tests for the panel:** The spec lists two "JS unit" tests — "Dropdown renders both entries (upload + FAL)" and "Click on FAL entry dispatches `nr-mcp-open-fal-picker` CustomEvent" — that apply to this task. These are deferred to the manual acceptance test in Step 7 below: the panel is a Lit web component using shadow DOM that is not trivially testable in Jest without a custom Lit test harness. The behaviour is fully covered by Step 7.

- [ ] **Step 1: Update the `icons.js` import**

Find the import line for `icons.js` in `ai-chat-panel.js`. Add `ICON_UPLOAD` and verify `ICON_CHEVRON_DOWN` is already imported. The import should include at minimum:

```js
import {ICON_PAPERCLIP, ICON_SEND, ICON_CHEVRON_DOWN, ICON_UPLOAD, /* ...other existing icons... */} from './icons.js';
```

- [ ] **Step 2: Add dropdown open/close state**

In the panel Lit element class, add a reactive private state property. Locate the `static properties` block and add:

```js
_attachMenuOpen: {type: Boolean, state: true},
```

Also add a class field initializer: `_attachMenuOpen = false;`

- [ ] **Step 3: Add click-away listener**

In the panel class, add `connectedCallback` and `disconnectedCallback`. If they already exist, add to them. Otherwise add:

```js
connectedCallback() {
    super.connectedCallback();
    this._closeAttachMenu = () => { this._attachMenuOpen = false; };
    document.addEventListener('click', this._closeAttachMenu);
}

disconnectedCallback() {
    super.disconnectedCallback();
    document.removeEventListener('click', this._closeAttachMenu);
}
```

- [ ] **Step 4: Replace `_renderAttachmentMenu()`**

Find the existing `_renderAttachmentMenu()` method (around line 1173) and replace it entirely with:

```js
_renderAttachmentMenu() {
    if (!this.chat.visionSupported) return nothing;
    const canAttach = this.chat.canAttachFile();

    return html`
        <div class="attach-menu-wrap" style="position:relative">
            <button class="btn-icon"
                    ?disabled=${!canAttach}
                    title="${!canAttach ? lll('attachment.limitReached') : lll('attachment.upload')}"
                    aria-label="${lll('attachment.upload')}"
                    aria-expanded="${String(this._attachMenuOpen)}"
                    aria-haspopup="true"
                    @click=${(e) => { e.stopPropagation(); this._attachMenuOpen = !this._attachMenuOpen; }}>
                ${ICON_PAPERCLIP(14)}${ICON_CHEVRON_DOWN(10)}
            </button>

            ${this._attachMenuOpen ? html`
                <ul class="attach-menu"
                    role="menu"
                    @click=${(e) => e.stopPropagation()}>
                    <li role="menuitem"
                        @click=${() => { this._attachMenuOpen = false; this.renderRoot.querySelector('input[type="file"]')?.click(); }}>
                        ${ICON_UPLOAD(14)}
                        ${lll('attachment.upload')}
                    </li>
                    <li role="menuitem"
                        @click=${() => { this._attachMenuOpen = false; this.dispatchEvent(new CustomEvent('nr-mcp-open-fal-picker', {bubbles: true, composed: true})); }}>
                        <typo3-icon identifier="apps-filetree-folder-opened" size="small"></typo3-icon>
                        ${lll('attachment.fal')}
                    </li>
                </ul>
            ` : nothing}
        </div>

        <input type="file"
               accept="${(this.chat.supportedFormats || []).map(f => '.' + f).join(',') || '*'}"
               style="display:none"
               @change=${this._handleFileSelected}>
    `;
}
```

- [ ] **Step 5: Add dropdown CSS**

Locate the CSS template literal (the `css\`` block) in `ai-chat-panel.js` and add:

```css
.attach-menu-wrap { position: relative; }
.attach-menu {
    position: absolute;
    bottom: calc(100% + 4px);
    left: 0;
    background: var(--typo3-component-background-color, #fff);
    border: 1px solid var(--typo3-component-border-color, #ccc);
    border-radius: 4px;
    padding: 4px 0;
    margin: 0;
    list-style: none;
    white-space: nowrap;
    z-index: 100;
    box-shadow: 0 2px 8px rgba(0,0,0,.15);
}
.attach-menu li {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    cursor: pointer;
    font-size: 13px;
}
.attach-menu li:hover {
    background: var(--typo3-component-hover-background-color, #f0f0f0);
}
```

- [ ] **Step 6: Add translation key**

In `Resources/Private/Language/locallang.xlf`, add inside the `<body>` block:

```xml
<trans-unit id="attachment.fal">
    <source>Aus FAL wählen</source>
</trans-unit>
```

- [ ] **Step 7: Manual acceptance test**

1. Open TYPO3 backend → navigate to the chat module (both floating panel and full module)
2. Confirm the paperclip shows a chevron-down arrow next to it
3. Click it → dropdown opens with two entries: "Datei hochladen" (with upload icon) and "Aus FAL wählen" (with TYPO3 folder icon)
4. Click outside → dropdown closes
5. Click "Datei hochladen" → file picker dialog opens (existing behaviour)
6. Click "Aus FAL wählen" → TYPO3 Element Browser opens filtered to supported extensions (pdf, docx, etc.)
7. Select a PDF → file badge appears in the composer with the file name
8. Send the message → LLM receives the document content

- [ ] **Step 8: Commit**

```bash
git add Resources/Public/JavaScript/ai-chat-panel.js Resources/Private/Language/locallang.xlf
git commit -m "feat(ui): replace paperclip button with upload/FAL dropdown"
```

---

## Task 7: PHPStan + CGL + CHANGELOG

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Run PHPStan**

```bash
Build/Scripts/runTests.sh -s phpstan 2>&1 | tail -10
```

Fix any issues found before continuing.

- [ ] **Step 2: Run CGL check**

```bash
Build/Scripts/runTests.sh -s cgl -n 2>&1 | tail -5
```

(`-n` = dry-run / check only; no `-n` = actually fix)

If issues: `Build/Scripts/runTests.sh -s cgl 2>&1 | tail -5` to fix them.

- [ ] **Step 3: Update CHANGELOG.md**

Add at the top under `## [Unreleased]` (or create the section if absent):

```markdown
### Added
- FAL file picker: users can now select existing TYPO3 FAL files as chat attachments via the TYPO3 Element Browser, in addition to uploading new files
- New backend endpoint `GET /ai-chat/file-info` resolves FAL file metadata (name, MIME type, size) by UID

### Changed
- Chat `sendMessage` endpoint now accepts any FAL file the backend user has read permission for, not only files previously uploaded via the chat upload endpoint
```

- [ ] **Step 4: Final unit test run**

```bash
Build/Scripts/runTests.sh -s unit 2>&1 | tail -5
```

Expected: OK

- [ ] **Step 5: Final JS test run**

```bash
npm run test:js 2>&1 | tail -5
```

Expected: all passing

- [ ] **Step 6: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs: update CHANGELOG for FAL file picker feature"
```
