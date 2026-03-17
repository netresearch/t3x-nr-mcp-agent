# Chat File Upload

## Problem

Users want to provide documents (PDFs, images, DOC files) to the AI assistant for content generation. Currently, the chat only accepts text input. Users must manually copy-paste content from documents, losing formatting and context.

## Solution

Add file upload capability to the chat. Users attach files via a "+" menu (direct upload or FAL browser). Files are stored in TYPO3 FAL. The backend reads files server-side and sends them as multimodal content to the LLM. This requires a backward-compatible change in nr-llm to support multimodal content in `chatCompletion()`.

## Prerequisites: nr-llm Changes

### Problem in nr-llm

The current `chatCompletion()` signature declares messages as `array<int, array{role: string, content: string}>`. The `content` field is typed as `string`. However, the OpenAI API (and Claude, Gemini) support multimodal content where `content` is an array of content blocks:

```php
// Current: text-only
['role' => 'user', 'content' => 'Describe this']

// Needed: multimodal
['role' => 'user', 'content' => [
    ['type' => 'text', 'text' => 'Generate page text from this PDF'],
    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,...']],
    ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => '...']],
]]
```

Currently, `ClaudeProvider::chatCompletion()` extracts content via `getString($msgArray, 'content')` which returns `''` for array content — multimodal data is silently lost.

### Required nr-llm Changes (backward-compatible)

**1. Update PHPDoc on `ProviderInterface::chatCompletion()`:**

```php
/**
 * @param array<int, array{role: string, content: string|array<int, array<string, mixed>>}> $messages
 * @param array<string, mixed> $options
 */
public function chatCompletion(array $messages, array $options = []): CompletionResponse;
```

No signature change needed — `array $messages` already accepts both. Only the PHPDoc changes. This is fully backward-compatible.

**2. Update each provider to handle `content` as `string|array`:**

- **OpenAiProvider**: Already works — passes `$messages` through verbatim to the API. OpenAI natively supports multimodal content arrays. No code change needed.

- **ClaudeProvider**: Change `getString($msgArray, 'content')` to check type. If array, convert to Claude's native content block format:
  ```php
  $content = $msgArray['content'];
  if (is_array($content)) {
      // Convert OpenAI-style content blocks to Claude format
      $filteredMessages[] = ['role' => $role, 'content' => $this->convertContentBlocks($content)];
  } else {
      $filteredMessages[] = $message;
  }
  ```
  Claude uses `[{type: 'text', text: '...'}, {type: 'image', source: {type: 'base64', ...}}]` — similar but not identical to OpenAI format.

- **GeminiProvider**: Similar conversion to Gemini's `parts` format.

- **MistralProvider, GroqProvider, OpenRouterProvider**: Pass through like OpenAI (they use OpenAI-compatible APIs).

**3. Same change for `ToolCapableInterface::chatCompletionWithTools()`:**

Same PHPDoc update. The tool-loop messages can also contain multimodal content.

**4. No new interface needed.** The existing `VisionCapableInterface::supportsVision()` indicates whether the provider can handle image/document content. If `supportsVision()` is false, multimodal content arrays should not be sent.

**Backward compatibility:** Existing callers that pass `content: string` are unaffected. The change is purely additive — providers that receive string content behave exactly as before. Only when `content` is an array does the new code path activate.

### Provider-specific document formats

Each LLM API has different content types for non-image files:

| Provider | Images | PDFs | DOCX |
|----------|--------|------|------|
| OpenAI | `image_url` with base64 data URL | `file` input type (or image of each page) | Not supported natively |
| Claude | `image` with base64 source | `document` with base64 source | Not supported natively |
| Gemini | `inlineData` with base64 | `inlineData` with PDF mime type | Not supported natively |

DOCX is not natively supported by any major LLM. For DOCX files, server-side text extraction is needed as fallback (e.g., `phpoffice/phpword`). For the MVP, we support only formats the LLM can handle natively: **images and PDFs**.

## nr-mcp-agent Implementation

### Two Distinct File Flows

**Flow 1: Local file upload** — User has a file on their computer.
1. User clicks "Upload file" in "+" menu
2. Frontend sends file to `ai_chat_file_upload` endpoint
3. Backend stores file in FAL folder (`1:/ai-chat/{be_user_uid}/`)
4. Backend returns `{fileUid, name, mimeType, size}`
5. Frontend shows file badge with name, stores `fileUid` in state
6. On send: `fileUid` is included in `sendMessage`

**Flow 2: FAL browser** — File already exists in TYPO3.
1. User clicks "From file list" in "+" menu
2. TYPO3 Element Browser opens (File mode)
3. User selects file → callback returns FAL UID directly
4. Frontend shows file badge, stores `fileUid` in state
5. On send: same `fileUid` in `sendMessage`

Both flows converge at `sendMessage` with just a `fileUid`. The upload endpoint only exists to get local files into FAL.

### "+" Menu

A dropdown menu next to the chat input textarea:

- **Upload file** — native file picker, `accept=".pdf,.png,.jpg,.jpeg,.webp"`
- **From file list** — TYPO3 Element Browser (File mode)

Active when `supportsVision()` is true (from `getStatus()` endpoint). Grayed out with tooltip when not supported.

**MVP scope:** PDF + Images. No DOCX (no LLM supports it natively).

### Limits

- Maximum file size: from `VisionCapableInterface::getMaxImageSize()` (provider-defined)
- Maximum files per conversation: 5
- Allowed formats: from `VisionCapableInterface::getSupportedImageFormats()` + `['pdf']`
- 1 file per message

### API Changes

#### New endpoint: `ai_chat_file_upload`

```
POST /ajax/ai-chat/file-upload
Content-Type: multipart/form-data
Body: file (binary)

Response: {fileUid: 123, name: "document.pdf", mimeType: "application/pdf", size: 1048576}
```

Stores the file in FAL at `1:/ai-chat/{be_user_uid}/`. Validates size and MIME type. Returns FAL file UID.

Security: Folder must have a `.htaccess` rule denying direct HTTP access. Per-user subfolder prevents information disclosure.

Frontend needs a new `ApiClient._postFormData()` method for multipart uploads (existing `_post()` sends JSON). CSRF protection via the TYPO3 AJAX URL token (same as all other endpoints).

#### Modified endpoint: `ai_chat_conversation_send`

```
POST /ajax/ai-chat/send
Body: {conversationUid: 1, content: "Generate page text from this", fileUid: 123}
```

`fileUid` is optional integer. When present, the backend:
1. Loads file from FAL via `ResourceFactory::getFileObject($fileUid)`
2. Validates file exists and is accessible
3. Checks conversation has not exceeded 5 file attachments
4. Stores message with `fileUid` + `fileName` metadata in conversation JSON

The Base64 encoding and multimodal array building happens later in `ChatService` at LLM call time — not at message storage time.

#### Modified endpoint: `ai_chat_status`

Add to response:
```json
{
    "visionSupported": true,
    "maxFileSize": 20971520,
    "supportedFormats": ["png", "jpg", "jpeg", "webp", "pdf"]
}
```

Values from `VisionCapableInterface::supportsVision()`, `getMaxImageSize()`, `getSupportedImageFormats()` + `['pdf']`.

### Backend: Multimodal Message Building

In `ChatService`, when building messages for the LLM call, detect `fileUid` in stored messages and build multimodal content:

```php
private function buildLlmMessages(array $messages): array
{
    $result = [];
    foreach ($messages as $msg) {
        if (isset($msg['fileUid'])) {
            $file = $this->resourceFactory->getFileObject((int)$msg['fileUid']);
            $base64 = base64_encode(file_get_contents($file->getForLocalProcessing()));
            $mimeType = $file->getMimeType();

            $result[] = [
                'role' => $msg['role'],
                'content' => [
                    ['type' => 'text', 'text' => $msg['content'] ?? ''],
                    $this->buildFileContentBlock($mimeType, $base64),
                ],
            ];
        } else {
            $result[] = $msg;
        }
    }
    return $result;
}

private function buildFileContentBlock(string $mimeType, string $base64): array
{
    if (str_starts_with($mimeType, 'image/')) {
        return ['type' => 'image_url', 'image_url' => [
            'url' => 'data:' . $mimeType . ';base64,' . $base64,
        ]];
    }
    // PDF — use document content type
    return ['type' => 'document', 'source' => [
        'type' => 'base64',
        'media_type' => $mimeType,
        'data' => $base64,
    ]];
}
```

Note: The `document` content type is provider-specific. The nr-llm provider implementations handle the conversion from this intermediate format to the provider's native format (see nr-llm Changes above).

### Missing file handling

When a FAL file is deleted after being attached to a conversation, the `buildLlmMessages` method must handle this gracefully:

```php
try {
    $file = $this->resourceFactory->getFileObject((int)$msg['fileUid']);
} catch (\Exception) {
    // File deleted — include text-only with note
    $result[] = ['role' => $msg['role'], 'content' => $msg['content'] . "\n\n[Attached file '" . ($msg['fileName'] ?? 'unknown') . "' is no longer available]"];
    continue;
}
```

### Message Persistence

Messages with files are stored in the conversation JSON as:

```json
{
    "role": "user",
    "content": "Generate page text from this document",
    "fileUid": 123,
    "fileName": "company-brochure.pdf",
    "fileMimeType": "application/pdf"
}
```

No Base64 in persistence — only the FAL reference. Files are read and encoded at LLM call time.

### Frontend: "+" Menu

Added to both `ai-chat-panel.js` and `chat-app.js`:

```html
<div class="attachment-menu">
    <button class="btn-icon btn-attachment" @click=${toggleMenu}
            ?disabled=${!visionSupported}
            title="${!visionSupported ? tooltip : ''}">+</button>
    <div class="attachment-dropdown" ?hidden=${!menuOpen}>
        <button @click=${uploadFile}>📎 ${lll('attachment.upload')}</button>
        <button @click=${openFalBrowser}>📁 ${lll('attachment.fromFal')}</button>
    </div>
</div>
```

File selected → file badge above input (filename + remove button) → on send, `fileUid` included.

### FAL Browser Integration

The TYPO3 Element Browser is opened via `@typo3/backend/modal.js` in file selection mode. The callback delivers the selected `sys_file` UID. Implementation uses TYPO3's `BroadcastService` or `window.postMessage` for the callback from the modal to the panel's shadow DOM.

### Display in Chat

User messages with attachments show a file badge:
```
┌─────────────────────────────┐
│ 📄 company-brochure.pdf     │
│ Generate page text from this │
└─────────────────────────────┘
```

Icon: 📄 for PDF, 🖼️ for images.

### Localization

New keys in `locallang_chat.xlf` (EN + DE):

- `attachment.upload` — "Upload file" / "Datei hochladen"
- `attachment.fromFal` — "From file list" / "Aus Dateiliste"
- `attachment.notSupported` — "Your LLM model does not support file attachments" / "Ihr LLM-Modell unterstützt keine Dateianhänge"
- `attachment.tooLarge` — "File too large (max %d MB)" / "Datei zu groß (max. %d MB)"
- `attachment.limitReached` — "Maximum 5 files per conversation reached" / "Maximum 5 Dateien pro Chat erreicht"
- `attachment.invalidType` — "File type not supported" / "Dateityp nicht unterstützt"
- `attachment.remove` — "Remove attachment" / "Anhang entfernen"
- `attachment.uploading` — "Uploading..." / "Wird hochgeladen..."
- `attachment.fileMissing` — "File no longer available" / "Datei nicht mehr verfügbar"

### Security

- **FAL storage:** Files stored in `1:/ai-chat/{be_user_uid}/` with `.htaccess` deny rule
- **MIME validation:** Server-side check against allowlist (not just file extension)
- **Size validation:** Server-side check against provider's `getMaxImageSize()`
- **CSRF:** Upload endpoint uses TYPO3 AJAX URL token mechanism
- **Access:** Upload endpoint requires authenticated BE user, same `checkAccess()` as other endpoints
- **No Base64 over HTTP:** Files uploaded as multipart, encoded server-side only

## Testing

### Unit Tests (PHP)

- ChatApiController: `fileUpload` stores file in FAL, returns UID
- ChatApiController: `fileUpload` rejects oversized files (400)
- ChatApiController: `fileUpload` rejects invalid MIME types (400)
- ChatApiController: `sendMessage` with `fileUid` stores file metadata in message
- ChatApiController: `sendMessage` rejects when 5-file limit exceeded
- ChatApiController: `getStatus` includes `visionSupported` and format fields
- ChatService: `buildLlmMessages` creates correct multimodal content array for image
- ChatService: `buildLlmMessages` creates correct document content block for PDF
- ChatService: `buildLlmMessages` handles missing FAL file gracefully

### E2E Tests (Playwright)

- "+" button visible when vision supported
- "+" button disabled with tooltip when not supported
- File upload: select file → badge appears → send → message with attachment shown
- File badge remove button clears attachment
- FAL browser opens on "From file list" click

## Implementation Order

1. **nr-llm:** Update PHPDoc, make providers handle `content: string|array` (backward-compatible)
2. **nr-mcp-agent:** Add FAL upload endpoint + security
3. **nr-mcp-agent:** Extend `sendMessage` with `fileUid` support
4. **nr-mcp-agent:** Add `buildLlmMessages()` multimodal message building in ChatService
5. **nr-mcp-agent:** Extend `getStatus()` with vision info
6. **nr-mcp-agent:** Frontend "+" menu, upload, FAL browser integration
7. **nr-mcp-agent:** Tests + localization

## Future Enhancements

- DOCX support via server-side text extraction (`phpoffice/phpword`)
- `DocumentCapableInterface` in nr-llm for dynamic format detection
- Drag-and-drop onto chat input
- Image thumbnail preview in chat messages
- Automatic cleanup of orphaned upload files
