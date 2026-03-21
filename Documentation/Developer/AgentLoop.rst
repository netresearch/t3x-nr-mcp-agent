..  include:: /Includes.rst.txt

==========
Agent loop
==========

``ChatService`` has two processing paths that are selected
automatically based on whether MCP tools are available.

Simple chat (no tools)
======================

When MCP is disabled or the MCP server provides no tools,
``runSimpleChat()`` is used. This is a fast path that:

1.  Resolves the LLM provider via the nr-llm DB chain.
2.  Builds the system prompt (see Architecture > System
    prompt priority).
3.  Prepends the system prompt as a ``system`` message.
4.  Calls ``ProviderInterface::chatCompletion()``.
5.  Appends the response and sets status to ``idle``.

Agent loop (with tools)
=======================

When MCP is enabled and tools are available,
``runAgentLoop()`` is used. It orchestrates the
interaction between the LLM and MCP tools.

Flow
----

::

    1. Set status to "processing"
    2. Resolve provider and build system prompt
    3. LOOP (max 20 iterations):
       a. Send messages + tools to LLM (with retry)
       b. IF response has tool calls:
          - Save assistant message with tool_calls
          - Set status to "tool_loop"
          - Execute each tool via McpToolProvider
          - Append tool results to messages
          - Save and CONTINUE loop
       c. ELSE (plain text response):
          - Append assistant message
          - Set status to "idle"
          - RETURN
    4. If max iterations reached:
       - Set status to "failed"
       - Set error "Max tool iterations reached"

LLM retry logic
================

The LLM call includes automatic retry for transient
errors:

*   **Max retries:** 2 (3 total attempts)
*   **Retry delay:** 3 seconds, increasing linearly
    (3s, 6s)
*   **Retried errors:** HTTP 429, HTTP 503, messages
    containing "rate" or "overloaded"
*   **Non-transient errors:** Thrown immediately without
    retry

Crash recovery
==============

The agent loop persists state after every significant
operation. This table shows what happens if the process
crashes at each point:

.. list-table:: Crash recovery behavior
   :header-rows: 1
   :widths: 30 20 50

   *  -  Crash point
      -  Status in DB
      -  Recovery
   *  -  Before LLM call
      -  ``processing``
      -  Cleanup marks as ``failed`` after 5 min.
         User retries.
   *  -  During LLM call
      -  ``processing``
      -  Same as above.
   *  -  After tool_calls saved
      -  ``tool_loop``
      -  On retry, ``resumeConversation()`` detects
         pending tool calls and executes them first.
   *  -  During tool execution
      -  ``tool_loop``
      -  Same as above. Tools are re-executed.
   *  -  After tool results saved
      -  ``tool_loop``
      -  Loop continues normally on retry.

MCP tool provider
=================

``McpToolProviderInterface`` abstracts the MCP server
connection. The default implementation
(``McpToolProvider``) manages the MCP server as a
subprocess:

1.  **connect()** -- Starts the MCP server process and
    performs the MCP initialization handshake.
2.  **getToolDefinitions()** -- Retrieves available tools
    from the MCP server (``tools/list``).
3.  **executeTool()** -- Calls a specific tool with
    arguments (``tools/call``).
4.  **disconnect()** -- Shuts down the MCP server
    process.

When MCP is disabled in the extension configuration,
the tool provider returns no tools and ChatService
uses the simple chat path instead of the agent loop.
