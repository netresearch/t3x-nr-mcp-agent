# FAL File Picker — Design Spec

## Goal

Allow users to select existing TYPO3 FAL files in the chat instead of uploading them, using the native TYPO3 Element Browser.

## Context

The chat panel currently supports file attachment via upload: a local file is POST-ed to `ai_chat_file_upload`, stored in FAL, and the returned `fileUid` is passed to `handleFileSelect()`. From `handleFileSelect()` onwards the file is processed identically regardless of how it entered FAL.

This feature adds a second entry point: selecting an already-stored FAL file. The processing pipeline (extract → LLM) remains completely unchanged.

---

## Architecture

### Data Flow

```
User clicks [📎 ▾] → dropdown opens
  │
  ├─ "Datei hochladen"  → existing file-input (unchanged)
  │
  └─ "Aus FAL wählen"   → TYPO3 Element Browser popup
                                │  allowedExtensions = supportedFormats (e.g. pdf,docx,xlsx,txt)
                                │
                         User selects file → JS callback receives FAL UID
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
- Existing `ICON_PAPERCLIP` button becomes a split/dropdown trigger
- New icon `ICON_UPLOAD` (Lucide `upload`) for the upload entry
- FAL entry uses `<typo3-icon identifier="apps-filetree-folder-opened" size="small">` — TYPO3 native, no custom SVG needed
- Dropdown is a simple positioned `<ul>` rendered via Lit, visible on click, closed on outside-click or selection
- Both contexts (floating panel, full module) use the same component — no conditional rendering

#### 2. JS: Element Browser integration

- **File:** `Resources/Public/JavaScript/chat-core.js`
- New method `_openFalPicker()` opens the TYPO3 Element Browser via:
  ```js
  import('@typo3/backend/element-browser.js')
  ```
  with `allowedExtensions` derived from `this._capabilities.extractionFormats`
- Element Browser calls back via `setFormValueFromBrowseWin` or TYPO3's standard file-browser callback mechanism
- Callback invokes `_onFalFileSelected(fileUid)` → calls `fileInfo` endpoint → calls `handleFileSelect()`

#### 3. Backend: `fileInfoAction`

- **File:** `Classes/Controller/ChatApiController.php`
- Route: `ai_chat_file_info` (GET, `fileUid` param)
- Resolves via `ResourceFactory::getFileObject($fileUid)`
- Returns `{fileUid, name, mimeType, size}`
- Error cases:
  - `fileUid` missing or non-numeric → 400
  - File not found in FAL → 404
  - File extension not in supported list → 422

---

## Security

- `fileInfoAction` checks that the resolved file's extension is in `DocumentExtractorRegistry::getAvailableExtensions()` — prevents referencing arbitrary FAL files of unsupported types
- No file content is returned by `fileInfoAction`, only metadata
- Existing `ChatService` permission model (TYPO3 backend context, authenticated editor) applies unchanged

---

## Error Handling

| Situation | Behaviour |
|-----------|-----------|
| User closes Element Browser without selection | No callback, no action |
| `fileInfo` returns 404 | Toast error (same style as upload errors) |
| `fileInfo` returns 422 (unsupported type) | Toast: "Dieser Dateityp wird nicht unterstützt" |
| Element Browser unavailable | Button renders but shows toast on click |

---

## Testing

| Layer | What |
|-------|------|
| Unit | `ChatApiController::fileInfoAction()` — happy path, 400/404/422 error cases |
| Unit | Extension validation in `fileInfoAction` uses `DocumentExtractorRegistry` |
| JS unit | Dropdown renders with both entries |
| JS unit | `_onFalFileSelected()` calls `fileInfo` then `handleFileSelect()` |
| Architecture | `fileInfoAction` only accesses FAL via `ResourceFactory` (no direct filesystem) |

---

## Out of Scope

- Browsing/searching FAL outside the TYPO3 Element Browser
- Multiple file selection (single file per message, same as upload)
- File management (rename, delete) within the picker
- Frontend-only File Browser (iFrame embed)
