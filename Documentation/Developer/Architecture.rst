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
        | resolve Task --> Configuration (nr-llm DB)
        | build system prompt + transcript
        v
    nr-llm AgentRuntime::run(configuration, messages, beUserUid)
        |
        |--- LLM Provider (OpenAI, Anthropic, ...)
        |
        |--- nr-llm ToolRegistry (builtin backend tools)
                 |
                 v
            Logs, exceptions, system status, records,
            page content, ... (RBAC + tool gate enforced)

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

*   Avoids PHP timeout issues -- the LLM calls and tool
    execution in the agent run can take many seconds.
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
    Optional custom system prompt override (per conversation),
    set by the user through ``/ai-chat/conversations/system-prompt``.

``activity``
    JSON list of step summaries of the current turn --
    ``{"kind": "llm"|"tool"|"approval", "round", "ms", "tool",
    "error", "approved"}``, at most 100. Written column-only by
    ``RunActivityRecorder``, which is the ``onStep`` callback of
    ``AgentRuntime::run()`` and ``approve()``; never part of
    ``Conversation::toRow()``, so the turn's final full-row write
    cannot put back the list it started with. Returned by
    ``getMessages`` on both the full and the fast poll path.
    Tool arguments and results are not stored here; nr-llm's run
    record keeps them.

``view_context``
    JSON ``{"pageId": int, "module": string}``: the page the
    module frame showed and the open module when the user last
    sent or edited a message. Kept on the row, not on the
    message, because stored messages go to the provider as they
    are. The page is re-checked against the user's permissions
    when the prompt is built (``UserContextPrompt``).

System prompt priority
----------------------

The system prompt is composed in this order:

1.  **Identity / behaviour contract** -- Always prepended. A
    fixed block establishes that the assistant is the
    Netresearch TYPO3 Backend AI Chat, steers it to use its
    tools instead of asking the user to paste data, forbids
    it from claiming to be ChatGPT/OpenAI, and defers the
    answer language to the user context (step 5). This holds
    regardless of how the Task/Configuration prompt is set.
2.  **nr-llm Configuration + Task prompts** -- The
    ``system_prompt`` from the nr-llm Configuration record
    and the ``prompt_template`` from the Task record,
    combined (separated by a blank line). Configure these in
    the TYPO3 backend to provide tool usage instructions or
    persona definitions. Always included.
3.  **Site-language context** -- appended in every case.
4.  **User context** (``UserContextPrompt``) -- appended in every
    case: the answer language (the backend user's ``lang``; a
    language the message explicitly asks for wins), the open
    module and the selected page with uid and title, the page
    only if the user may show it.
5.  **Conversation-level prompt** -- If a conversation has a
    custom ``system_prompt`` set, it comes last, between
    ``<user_instructions>`` markers (which are stripped from the
    text itself), and the model is told to follow it only where
    it does not contradict the rest of the prompt: the
    administrator's instructions and the language rules stay in
    force (NEXT-172).

Configuration resolution
-------------------------

``ChatService`` resolves the ``LlmConfiguration`` the chat
runs against, and the prompts, through nr-llm:

1.  Load the Task record via nr-llm's ``TaskRepository`` (by
    ``ExtensionConfiguration::getLlmTaskUid()``: the Task of the
    first pair in ``groupTaskMapping``, in the order written,
    whose group the user belongs to, else ``llmTaskUid`` --
    ADR-015).
2.  Take ``Task::getConfiguration()`` as the ``LlmConfiguration``
    passed to ``AgentRuntime::run()``. A missing Task or
    Configuration fails the turn loudly.
3.  The Configuration's ``system_prompt`` and the Task's
    ``prompt_template`` feed ``buildSystemPrompt()``.

A provider adapter is still created from the Configuration's
model (via ``ProviderAdapterRegistry``) — but only to expand
file attachments and report supported formats; the chat turn
itself runs inside nr-llm's ``AgentRuntime``.

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
    Legacy transitional state. The tool loop now runs
    synchronously inside nr-llm's ``AgentRuntime`` within a
    single ``processing`` turn, so the chat no longer parks a
    conversation here; the state is retained for backward
    compatibility.

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
        | for each file attachment:
        |   images  → base64 data URI (provider must be VisionCapable)
        |   documents (PDF/DOCX/XLSX/TXT):
        |     if provider implements DocumentCapableInterface
        |       → sent as binary (base64-encoded document block)
        |     else
        |       → DocumentExtractorRegistry::extract() → plain-text block
        v
    nr-llm AgentRuntime (multimodal messages forwarded to the provider)

``ChatService::getProviderCapabilities()`` queries the active provider for
its supported formats. It calls ``VisionCapableInterface::getSupportedImageFormats()``
for image formats and, if the provider also implements
``DocumentCapableInterface``, appends ``getSupportedDocumentFormats()``
(e.g. ``['pdf']``). The frontend receives this list via ``GET /ai-chat/status``
and uses it to set the file picker's ``accept`` attribute dynamically —
ensuring users can only select file types the current provider can process.

Component map
=============

.. list-table::
   :header-rows: 1
   :widths: 25 40 35

   * - Component
     - Responsibility
     - Key files
   * - **Backend Module**
     - Chat UI (Admin Tools > AI Chat)
     - ``Classes/Controller/``, ``Resources/Private/Templates/``
   * - **Floating Panel**
     - Toolbar chat widget, persistent across navigation
     - ``Resources/Public/JavaScript/`` (Lit)
   * - **Agent Loop**
     - LLM call → tool use → reply; owned by nr-llm's ``AgentRuntime``
     - ``Classes/Service/ChatService.php``
   * - **Conversation Store**
     - Persists messages, pins, auto-archive
     - ``Classes/Domain/Repository/``
   * - **CLI Commands**
     - ``ai-chat:process`` (exec), ``ai-chat:worker`` (long-running)
     - ``Classes/Command/``
   * - **Access Control**
     - Group-based access, concurrency caps, length limits
     - ``Classes/Controller/ChatApiController.php`` (``checkAccess()``),
       ``Classes/Configuration/ExtensionConfiguration.php``

Dependency rules
================

Enforced via `PHPAt <https://github.com/carlosas/phpat>`_ — runs automatically
with PHPStan:

-   ``Domain`` MUST NOT depend on ``Controller`` or ``Command``
-   ``Controller`` may depend on ``Domain`` and ``Service``
-   ``Service`` may depend on ``Domain``; MUST NOT depend on ``Controller``
-   ``Controller`` MUST NOT depend on ``Command`` — background processing is
    reached through ``ChatProcessorInterface``, never by invoking a CLI
    command class
-   ``Document`` MUST NOT depend on ``ChatService`` or ``Controller``
-   ``Service`` MUST NOT depend on ``ConnectionPool`` — repositories own
    database access
-   ``Tests`` may depend on anything

Architecture tests: ``Tests/Architecture/LayerDependencyTest.php`` and
``Tests/Architecture/DocumentExtractorArchitectureTest.php``
