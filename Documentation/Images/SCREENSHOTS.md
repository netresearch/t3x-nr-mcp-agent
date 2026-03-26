# Screenshot Guide

This file documents which screenshots are needed, what they should show,
and where they are used in the documentation.

## General guidelines

- Browser window: **1440px** wide (or wider), standard zoom
- Format: **PNG**
- Max width: **1200px** (scale down if needed; keep aspect ratio)
- No browser chrome (address bar, tabs) in the screenshot — crop to the TYPO3 backend only
- Use the TYPO3 default backend theme

---

## Required screenshots

### `ChatModule.png`

**Used in:** Introduction/Index.rst, Usage/Index.rst

**What to show:**
- Full-page AI Chat backend module (Admin Tools > AI Chat)
- A conversation open in the main area with at least 2–3 message exchanges
- The conversation sidebar visible on the left with 2–3 conversations listed
- Ideally a response that contains some Markdown (a short list or bold text)

**How to take it:**
- Open the AI Chat module
- Have a conversation with a few messages visible
- Take a full-page screenshot of the module area (without the outer browser frame)

---

### `MarkdownResponse.png`

**Used in:** Usage/Index.rst

**What to show:**
- A single AI response rendered with rich Markdown:
  - At least one heading (`##`)
  - A short bullet list
  - Ideally a short inline code snippet or code block

**How to take it:**
- Send a message like: "List three tips for writing good page titles in TYPO3. Use a heading and bullet points."
- Wait for the response
- Crop to the response bubble only (not the full module)

---

### `FileAttachmentBadge.png`

**Used in:** Usage/Index.rst

**What to show:**
- The input area at the bottom of the chat
- The **+** button on the left side of the input field
- A file badge visible above the input (e.g. "report.pdf — 142 KB")

**How to take it:**
- Click **+** > Upload file > select a small PDF or DOCX
- After upload, the badge appears above the input field
- Crop to the bottom input area (roughly the bottom 120px of the chat UI)

---

### `ToolbarButton.png`

**Used in:** Usage/Index.rst

**What to show:**
- The TYPO3 backend top toolbar (right side)
- The chat icon button visible among the other toolbar icons (search, bookmarks, user)
- Ideally with a badge showing "1" active conversation

**How to take it:**
- Make sure a conversation is active (processing)
- Crop tightly to the toolbar area (right ~300px of the header bar)

---

### `ChatPanel.png`

**Used in:** Usage/Index.rst

**What to show:**
- The floating bottom panel in **expanded** state
- The panel overlaying the TYPO3 backend (e.g. page tree visible behind it)
- At least one conversation message visible in the panel
- The resize handle at the top of the panel visible

**How to take it:**
- Navigate to a regular TYPO3 module (e.g. Web > Page)
- Click the toolbar chat button to open the panel
- Drag it to a comfortable height (~40% of screen)
- Take a full-screen screenshot of the backend, then crop to show both the
  page tree/module behind and the panel in the foreground
