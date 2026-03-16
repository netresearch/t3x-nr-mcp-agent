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
        |--- LlmServiceManager (nr-llm)
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

``message_count``
    Denormalized count for display without decoding.

``status``
    Current processing state (see below).

``current_request_id``
    Identifier for the active processing request. Used
    for worker dequeue locking.

``system_prompt``
    Optional custom system prompt override.

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
