..  include:: /Includes.rst.txt

============
Architecture
============

System overview
===============

::

    Browser (Backend Module)
        |
        | AJAX (poll + send)
        v
    ChatApiController
        |
        | enqueue message
        v
    ConversationRepository  <----->  Database
        |                         (tx_nrmcpagent_conversation)
        |
        v
    ChatProcessor (exec or worker)
        |
        | fork CLI / dequeue
        v
    ProcessChatCommand / ChatWorkerCommand
        |
        v
    ChatService
        |
        |--- resolveProvider()
        |        |
        |        v
        |    Task --> Configuration --> Model (nr-llm DB)
        |        |
        |        v
        |    ProviderAdapterRegistry (nr-llm)
        |        |
        |        v
        |    LLM Provider (OpenAI, Anthropic, ...)
        |
        |--- McpToolProvider
                 |
                 v
            MCP Server (hn/typo3-mcp-server)
                 |
                 v
            TYPO3 Content (pages, tt_content, ...)

The frontend (a Lit web component) communicates with the
backend exclusively through polling. There are no
WebSocket or Server-Sent Events connections.

The AI Chat is accessible in two ways:

*   **Backend module** (Admin Tools > AI Chat) -- Full-page chat
    interface for longer conversations and history management.
*   **Toolbar panel** -- Floating bottom panel triggered by the
    toolbar button. Stays visible across module navigation,
    allowing users to chat while working in the page tree.

Key design decisions
====================

Polling over SSE
----------------

The chat UI uses periodic AJAX polling instead of
Server-Sent Events (SSE) or WebSockets. This was chosen
because:

*   It works reliably behind reverse proxies and load
    balancers without special configuration.
*   TYPO3 backend requests go through the standard
    middleware stack, ensuring authentication and CSRF
    protection.
*   The polling interval is short enough (1-2 seconds)
    to feel responsive.

CLI processing over HTTP
------------------------

Message processing happens in CLI context
(``ai-chat:process`` or ``ai-chat:worker``), not in the
web request. This design:

*   Avoids PHP timeout issues -- LLM calls and MCP tool
    execution can take many seconds.
*   Keeps the web server responsive -- no long-running
    HTTP connections.
*   Allows the worker mode to reuse a single process
    for multiple requests, reducing overhead.

Crash recovery
--------------

The system is designed to handle crashes gracefully:

*   Every state transition is persisted to the database
    immediately.
*   If a CLI process crashes mid-conversation, the
    conversation remains in ``processing``, ``locked``,
    or ``tool_loop`` status.
*   The ``ai-chat:cleanup`` command detects conversations
    stuck for more than 5 minutes and marks them as
    ``failed``.
*   Users see a clear error message and can retry.

Domain model
============

Conversation
------------

The central entity. Stored in
``tx_nrmcpagent_conversation``.

**Fields:**

``be_user``
    UID of the owning backend user.

``title``
    Auto-generated title from the first message.

``messages``
    JSON-encoded array of all messages (user, assistant,
    tool calls, tool results). Stored as ``mediumtext``.

    User messages with file attachments contain additional fields:

    .. code-block:: json

        {
            "role": "user",
            "content": "What is in this image?",
            "fileUid": 42,
            "fileName": "photo.jpg",
            "fileMimeType": "image/jpeg"
        }

    The ``fileUid`` is a TYPO3 FAL UID. ``ChatService::buildLlmMessages()``
    reads the file and converts it to a multimodal content array before
    passing messages to the LLM.

``message_count``
    Denormalized count for display without decoding.

``status``
    Current processing state (see below).

``current_request_id``
    Identifier for the active processing request. Used
    for worker dequeue locking.

``system_prompt``
    Optional custom system prompt override (per conversation).

System prompt priority
----------------------

The system prompt sent to the LLM is resolved in this order:

1.  **Conversation-level prompt** -- If a conversation has a
    custom ``system_prompt`` set, it takes highest priority.
2.  **nr-llm Configuration + Task prompts** -- The
    ``system_prompt`` from the nr-llm Configuration record
    and the ``prompt_template`` from the Task record are
    combined (separated by a blank line). Configure these
    in the TYPO3 backend to provide tool usage instructions
    or persona definitions.
3.  **Locale-based fallback** -- If nothing is configured,
    a default prompt is used based on the backend user's
    language setting (German or English).

Provider resolution
-------------------

``ChatService`` resolves the LLM provider through the nr-llm
database chain:

1.  Read the Task record (by ``llmTaskUid`` from extension
    configuration).
2.  Follow ``Task → Configuration → Model`` via foreign keys.
3.  Use ``ProviderAdapterRegistry::createAdapterFromModel()``
    to create a fully configured provider instance (with API
    key from nr-vault).

The Configuration's ``system_prompt`` and the Task's
``prompt_template`` are fetched in the same query and used
by ``buildSystemPrompt()``.

``archived``
    Whether the conversation is archived.

``pinned``
    Whether the conversation is pinned (prevents
    auto-archiving).

``error_message``
    Last error message (sanitized, no API keys).

ConversationStatus
------------------

The conversation lifecycle is modeled as a state enum:

``idle``
    Ready for new user input. This is the resting state.

``processing``
    A CLI process is actively calling the LLM.

``locked``
    Reserved by a worker process for dequeue.

``tool_loop``
    The LLM requested tool calls; the system is
    executing MCP tools and will call the LLM again.

``failed``
    An error occurred. The user can retry by sending
    a new message.

State transitions::

    idle --> processing --> idle          (success)
    idle --> processing --> tool_loop --> processing
                                             (tool iteration)
    idle --> processing --> failed        (error)
    * --> failed                         (cleanup timeout)

File attachment flow
====================

::

    User selects file (upload or FAL browser)
        |
        | POST /ai-chat/file-upload (multipart/form-data)
        v
    ChatApiController::fileUpload()
        | validates MIME type + size (max 20 MB)
        v
    FAL storage: fileadmin/ai-chat/<be_user_uid>/
        | returns fileUid
        v
    Frontend stores {fileUid, name, mimeType} as pendingFile

    User sends message
        |
        | POST /ai-chat/conversations/send {content, fileUid}
        v
    ChatApiController::sendMessage()
        | validates file limit (max 5 per conversation)
        | reads FAL metadata (fileName, fileMimeType)
        | stores message with fileUid in conversation JSON
        v
    ChatService::processConversation()
        |
        v
    ChatService::buildLlmMessages()
        | reads file from FAL (getForLocalProcessing)
        | base64-encodes content
        | builds multimodal content array:
        |   images  → {type: image_url, image_url: {url: data:...}}
        |   PDFs    → {type: document, source: {type: base64, ...}}
        v
    LLM Provider (multimodal chatCompletion call)
