# FAL File Picker — Design Spec

## Goal

Allow users to select existing TYPO3 FAL files in the chat instead of uploading them, using the native TYPO3 Element Browser popup.

## Context

The chat panel currently supports file attachment via upload: a local file is POST-ed to `ai_chat_file_upload`, stored in FAL, and the returned `fileUid` is passed to `handleFileSelect()`. From `handleFileSelect()` onwards the file is processed identically regardless of how it entered FAL.

This feature adds a second entry point: selecting an already-stored FAL file. The processing pipeline (extract → LLM) remains completely unchanged.

**Existing properties on `ChatCore` used by this feature:**
- `this.supportedFormats: string[]` — e.g. `['pdf', 'docx', 'xlsx', 'txt']`, populated from `_api.getStatus()`. Already used by the file-input `accept` attribute.
- `this.handleFileSelect(fileUid, name, mimeType)` — sets `this.pendingFile`; no upload involved.
- `this._setError(message)` — displays an error toast.

**Existing registry method used by this feature:**
- `DocumentExtractorRegistry::getAvailableExtensions(): string[]` — returns lowercase file extensions for all available extractors (e.g. `['txt', 'pdf', 'docx', 'xlsx']`). Already used by `ChatService`.

---

## Architecture

### Data Flow

```
User clicks [📎 ▾] → dropdown opens
  │
  ├─ "Datei hochladen"  → existing file-input (unchanged)
  │
  └─ "Aus FAL wählen"   → panel dispatches CustomEvent('nr-mcp-open-fal-picker')
                                │
                         ChatCore listens, calls _openFalPicker()
                                │  registers window.setFormValueFromBrowseWin
                                │  opens TYPO3 file browser popup
                                │  allowedExtensions = this.supportedFormats.join(',')
                                │
                         User selects file → Element Browser calls
                         opener.setFormValueFromBrowseWin(fieldName, fileUid, label)
                                │
                         GET ai_chat_file_info?fileUid=X
                                │  resolves name, mimeType, size via ResourceFactory
                                │
                         handleFileSelect(fileUid, name, mimeType)
                                │  (identical path to upload from here on)
                                │
                         User sends message → POST ai_chat with fileUid
                                │
                         ChatService::buildFileContentBlock()
                         → ResourceFactory::getFileObject(fileUid)
                         → DocumentExtractorRegistry::extract($path)
                         → content block for LLM
```

### Components

#### 1. UI: Dropdown on paperclip button

- **Files:** `Resources/Public/JavaScript/ai-chat-panel.js`, `icons.js`
- Existing `ICON_PAPERCLIP` button becomes a dropdown trigger with `ICON_CHEVRON_DOWN` (already in `icons.js`)
- New icon `ICON_UPLOAD` (Lucide `upload`) added to `icons.js` in the existing style (`fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"`)
- FAL entry uses `<typo3-icon identifier="apps-filetree-folder-opened" size="small">` (TYPO3 native web component, available in all TYPO3 v12/v13 backend contexts)
- Dropdown is a positioned `<ul>` rendered via Lit, visible on click, closed on outside-click (`document.addEventListener('click', ...)`) or selection
- Both contexts (`<nr-mcp-ai-chat-panel>` floating panel, `<nr-mcp-chat-app>` full module) render the same dropdown component — no conditional rendering needed
- Selecting "Aus FAL wählen" dispatches `new CustomEvent('nr-mcp-open-fal-picker', {bubbles: true, composed: true})` on the panel element

#### 2. JS: Element Browser integration

- **File:** `Resources/Public/JavaScript/chat-core.js`
- `ChatCore` listens for `nr-mcp-open-fal-picker` on `this` (the host element) in `connectedCallback()`
- `_openFalPicker()`:
  1. Guard: if `window.setFormValueFromBrowseWin` is already set, a picker is already open — return immediately (no second popup)
  2. Registers callback on the current `window` (the window that will call `window.open`, so `opener === window` from the popup's perspective):
     ```js
     window.setFormValueFromBrowseWin = (fieldName, value, _label) => {
         delete window.setFormValueFromBrowseWin;
         if (value) this._onFalFileSelected(parseInt(value, 10));
     };
     ```
  3. Builds picker URL using TYPO3's `ajaxUrls` registry (key `'file-browser'`, available in TYPO3 v12+ backend):
     ```js
     const ajaxUrl = top.TYPO3?.settings?.ajaxUrls?.['file-browser'];
     if (!ajaxUrl) {
         this._setError('FAL-Picker ist nicht verfügbar');
         return;
     }
     const bparams = encodeURIComponent('|||' + this.supportedFormats.join(','));
     // bparams format: fieldName|irreConfig|allowedTables|allowedExtensions
     // first 3 segments empty = not bound to any form field
     const url = ajaxUrl + '&bparams=' + bparams;
     ```
  4. Opens popup:
     ```js
     const popup = window.open(url, 'typo3FileBrowser', 'height=600,width=900,status=0,menubar=0,scrollbars=1');
     if (!popup) {
         delete window.setFormValueFromBrowseWin;
         this._setError('Popup wurde blockiert. Bitte Popup-Blocker deaktivieren.');
     }
     ```
- `_onFalFileSelected(fileUid: number)`:
  1. Calls `await this._api.getFileInfo(fileUid)`
  2. On success: calls `this.handleFileSelect(result.fileUid, result.name, result.mimeType)`
  3. On error: calls `this._setError(message)`

#### 3. API Client: `getFileInfo`

- **File:** `Resources/Public/JavaScript/api-client.js`
- New method: `getFileInfo(fileUid)` — GET `ai_chat_file_info?fileUid={fileUid}`
- Returns `Promise<{fileUid: number, name: string, mimeType: string, size: number}>`
- On non-2xx: throws `Error` with the JSON `message` field (same pattern as existing `uploadFile`)
- `size` is in bytes; used for display in the pending file indicator (future: already returned by `uploadFile` for consistency)

#### 4. Backend: `fileInfoAction`

- **File:** `Classes/Controller/ChatApiController.php`
- **Route registration:** Add to `Configuration/Backend/AjaxRoutes.php` (same file as all existing routes):
  ```php
  'ai_chat_file_info' => [
      'path' => '/ai-chat/file-info',
      'target' => ChatApiController::class . '::fileInfo',
      'methods' => ['GET'],
  ],
  ```
- HTTP method: GET — no CSRF token required (GET is idempotent; TYPO3 backend AJAX routes for GET do not require CSRF)
- Unauthenticated requests are rejected by TYPO3 backend middleware before the action is reached (same as all other routes in this file) — no explicit 401 handling in the action
- Response `Content-Type`: `application/json`
- Response envelope (camelCase keys, `size` in bytes):
  ```json
  {"fileUid": 42, "name": "document.pdf", "mimeType": "application/pdf", "size": 102400}
  ```
- Implementation:
  1. Read `fileUid` from `$request->getQueryParams()['fileUid']`; validate it is a non-empty positive integer → 400 `{"message": "Invalid fileUid"}`
  2. `$file = $resourceFactory->getFileObject((int)$fileUid)` — if throws `\InvalidArgumentException` → 404 `{"message": "File not found"}`
  3. Check FAL permission: `$file->checkActionPermission('read')` → if false → 403 `{"message": "Access denied"}`
  4. Check supported extension: `in_array($file->getExtension(), $registry->getAvailableExtensions(), true)` → if false → 422 `{"message": "Unsupported file type"}`
  5. Return 200 JSON with envelope above

---

## Security

- `fileInfoAction` explicitly calls `$file->checkActionPermission('read')` to enforce TYPO3 FAL storage-level permissions for the authenticated backend user
- Only metadata is returned — no file content
- Extension whitelist enforced server-side via `DocumentExtractorRegistry::getAvailableExtensions()` (second line of defence after the client-side `allowedExtensions` filter in the Element Browser)
- Existing `ChatService` permission model (TYPO3 backend context, authenticated editor) applies unchanged for the actual LLM processing

---

## Error Handling

| Situation | Behaviour |
|-----------|-----------|
| User closes Element Browser without selecting | `setFormValueFromBrowseWin` is never called; `window.setFormValueFromBrowseWin` remains on `window` until page unload or next picker open (overwritten by guard check — guard prevents second open, so stale callback is harmless) |
| `top.TYPO3.settings.ajaxUrls['file-browser']` undefined | `_setError('FAL-Picker ist nicht verfügbar')`, no popup |
| `window.open` returns null (popup blocked) | Cleanup: `delete window.setFormValueFromBrowseWin`; `_setError('Popup wurde blockiert. Bitte Popup-Blocker deaktivieren.')` |
| Picker already open (guard) | `_openFalPicker()` returns immediately, no second popup |
| `fileInfo` 400 | `_setError('Ungültige Datei-ID')` |
| `fileInfo` 403 | `_setError('Keine Leseberechtigung für diese Datei')` |
| `fileInfo` 404 | `_setError('Datei nicht gefunden')` |
| `fileInfo` 422 | `_setError('Dieser Dateityp wird nicht unterstützt')` |
| `fileInfo` network error | `_setError('Fehler beim Laden der Datei-Informationen')` |
| `pendingFile` already set | Selection replaces `pendingFile` (same behaviour as upload) |

---

## Testing

| Layer | What |
|-------|------|
| Unit | `ChatApiController::fileInfo()` — happy path returns correct JSON envelope |
| Unit | `fileInfo()` — 400 on missing/non-integer `fileUid` |
| Unit | `fileInfo()` — 404 when `ResourceFactory::getFileObject` throws |
| Unit | `fileInfo()` — 403 when `checkActionPermission('read')` returns false |
| Unit | `fileInfo()` — 422 when extension not in `getAvailableExtensions()` |
| JS unit | Dropdown renders both entries (upload + FAL) |
| JS unit | Click on FAL entry dispatches `nr-mcp-open-fal-picker` CustomEvent |
| JS unit | `_openFalPicker()` shows error toast when `ajaxUrls['file-browser']` undefined |
| JS unit | `_openFalPicker()` shows error toast and cleans up callback when `window.open` returns null |
| JS unit | `_openFalPicker()` returns early (no second popup) when `setFormValueFromBrowseWin` already set |
| JS unit | `_onFalFileSelected(42)` calls `_api.getFileInfo(42)` then `handleFileSelect()` |
| Architecture | `fileInfo` action only accesses FAL via `ResourceFactory` (no direct filesystem) |
| Manual — happy path | Click `[📎 ▾]` → "Aus FAL wählen" → Element Browser opens filtered to supported extensions → select PDF → file indicator appears in composer with filename → send message → LLM receives document content |
| Manual — error path | Manually call `GET /ai-chat/file-info?fileUid=99999` (non-existent) → verify 404 JSON response; also verify 422 by calling with a file UID for an unsupported type |
| Manual — both contexts | Verify dropdown and FAL picker work in floating panel AND full chat module |

---

## Out of Scope

- Browsing/searching FAL outside the TYPO3 Element Browser
- Multiple file selection per message (single file, same as upload)
- File management (rename, delete) within the picker
- Frontend-only File Browser (iFrame embed)
- Non-TYPO3-backend contexts (frontend plugins)
