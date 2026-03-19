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
    *(Screenshot placeholder -- will be added later.)*

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
depending on the LLM provider and whether MCP tools
are invoked.

If MCP is enabled, the assistant may execute multiple
tool calls (e.g. reading page content, then creating a
record) before responding. Each tool call iteration is
visible in the conversation.

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

When the active LLM provider supports file attachments, a **+** button
appears to the left of the input field.

1.  Click **+** to open the attachment menu.
2.  Select **Upload file** to open a file picker and choose a file from
    your computer.
3.  The selected file is uploaded immediately and shown as a badge above
    the input field (file name and size).
4.  Type your message and send — the file is included in the request.

To remove a pending attachment before sending, click the **×** on the
file badge.

**Supported file types** depend on the active provider:

*   All vision-capable providers (Claude, Gemini, GPT-4o, etc.) accept
    images: PNG, JPEG, WebP.
*   PDF support is available for Claude and Gemini only. The file picker
    automatically shows only the formats the current provider supports.

**Limits:**

*   Maximum 5 files per conversation.
*   Maximum file size: 20 MB per file.

If a file is not accepted (wrong type, too large, or upload error), an
error message is shown above the input.

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
