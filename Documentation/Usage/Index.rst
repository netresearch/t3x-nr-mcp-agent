..  include:: /Includes.rst.txt

=====
Usage
=====

Opening the AI Chat module
==========================

Navigate to **Admin Tools > AI Chat** in the TYPO3
backend. The module is available to all backend users
who have access to the Admin Tools section (unless
restricted via the ``allowedGroups`` setting).

..  figure:: /Images/ChatModule.png
    :alt: AI Chat backend module
    :class: with-shadow

    The AI Chat module in the TYPO3 backend.

Sending messages
================

1.  Type your message in the input field at the bottom
    of the chat area.
2.  Press **Enter** or click the send button.
3.  The message is sent to the server and processing
    begins in the background.
4.  The interface polls for updates and displays the
    AI response when ready.

While the assistant is processing, you will see a loading
indicator. Processing typically takes a few seconds,
depending on the LLM provider and whether tools are
invoked.

The assistant may execute several tool calls (e.g. reading
page content, then creating a record) before responding.
Each tool call iteration is visible in the conversation.
Which tools it has comes from nr-llm — its builtin set plus
any MCP server registered under **AI > Operation > MCP
Servers**; this extension registers none of its own.

..  figure:: /Images/MarkdownResponse.png
    :alt: AI response rendered as Markdown
    :class: with-shadow

    AI responses are rendered as rich Markdown — headings,
    lists, code blocks, and tables.

Conversation management
=======================

The sidebar shows your conversation history. Each
conversation has a title that is auto-generated from
the first message.

Starting a new conversation
---------------------------

Click the **New conversation** button to start a fresh
chat. The previous conversation remains in the sidebar
for later access.

Resuming a conversation
-----------------------

Click any conversation in the sidebar to resume it.
The full message history is loaded, and you can continue
where you left off.

Pinning conversations
---------------------

Pin important conversations to prevent them from being
auto-archived. Pinned conversations appear at the top
of the sidebar list.

Archiving conversations
-----------------------

Archive conversations you no longer need actively.
Archived conversations are hidden from the default
sidebar view but can still be accessed.

Conversations are also auto-archived after a
configurable period of inactivity (default: 30 days).

Attaching files
===============

A **+** button appears to the left of the input field whenever file
attachments are available.

1.  Click **+** to open the attachment menu.
2.  Select **Upload file** to open a file picker and choose a file from
    your computer.
3.  The selected file is uploaded immediately and shown as a badge above
    the input field (file name and size).
4.  Type your message and send — the file is included in the request.

To remove a pending attachment before sending, click the **×** on the
file badge.

..  figure:: /Images/FileAttachmentBadge.png
    :alt: File attachment badge above the chat input
    :class: with-shadow

    A selected file is shown as a badge above the input field.

**Supported file types:**

The following document formats are always available. Text is extracted
server-side before sending to the LLM:

*   PDF (``application/pdf``)
*   DOCX (``application/vnd.openxmlformats-officedocument.wordprocessingml.document``)
*   TXT (``text/plain``)
*   XLSX (``application/vnd.openxmlformats-officedocument.spreadsheetml.sheet``) --
    requires ``phpoffice/phpspreadsheet`` to be installed

Vision-capable providers (Claude, Gemini, GPT-4o, etc.) additionally
accept images:

*   PNG, JPEG, WebP

When the provider natively supports a document format (e.g. Claude
natively handles PDFs), the file is sent as-is instead of being
extracted. The file picker automatically restricts to the formats
supported by the active provider.

**Limits:**

*   Maximum 5 files per conversation.
*   Maximum file size: 20 MB per file.

If a file is not accepted (wrong type, too large, or upload error), an
error message is shown above the input. A file the logged-in user may not
store in the attachment folder is refused as well — the file mounts and
permissions that apply in the file module apply here too.

**What happens to an attached file:** it is not a throwaway copy. The
upload puts it straight into the file storage — ``fileadmin/ai-chat/``
by default, see :confval:`attachmentFolder` — where it is indexed like
any other file, and the assistant is told its file uid and path. So you
can ask it to reference the attachment from a content element in the same
breath as you attach it, without uploading the file a second time through
the file module, and you can ask it to describe the image for the
alternative text. Which of those it can actually carry out depends on the
file tools enabled in nr-llm, and every write waits for your approval.
Placing a file in a *different* folder afterwards is not something the
assistant can do — there is no tool for moving a file — so choose the
attachment folder to suit where those files belong.

Nothing is overwritten: a name already taken produces ``photo_01.jpg``,
and re-attaching a file that is byte-identical to the one already there
reuses it instead of making a copy.

Floating chat panel
===================

A chat button in the TYPO3 toolbar (top right, next to the search and
user menu) opens a floating bottom panel. The panel stays visible across
all module navigation -- you can chat with the AI while working in the
page tree, list module, or any other backend module.

..  figure:: /Images/ToolbarButton.png
    :alt: Chat toolbar button in the TYPO3 backend header
    :class: with-shadow

    The chat button in the TYPO3 toolbar. The badge shows the number of
    active (processing) conversations.

The panel has four states:

*   **Hidden** -- Only the toolbar button is visible.
*   **Collapsed** -- A minimal bar at the bottom showing the active
    conversation title.
*   **Expanded** -- Resizable panel with the full chat interface.
*   **Maximized** -- Full-height with conversation sidebar.

..  figure:: /Images/ChatPanel.png
    :alt: Floating chat panel in expanded state
    :class: with-shadow

    The floating panel in expanded state, overlaying the TYPO3 backend.
    Drag the top edge to resize.

Panel height and state are stored in ``localStorage`` per user.

Error handling
==============

If a conversation fails (e.g. due to an LLM provider
error or timeout), an error message is displayed. You
can retry by sending a new message in the same
conversation -- the system will attempt to resume
processing.

Stuck conversations (processing for more than 5 minutes)
are automatically marked as failed by the cleanup
command.
