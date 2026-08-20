..  include:: /Includes.rst.txt

==========
Agent loop
==========

Since version 0.6.3 the backend AI Chat does not run its own tool loop.
``ChatService`` delegates the whole chat turn to nr-llm's ``AgentRuntime``
(nr-llm ADR-101), which drives the model over nr-llm's builtin tool
registry and returns the settled result synchronously.

Processing a turn
=================

``ChatService::processConversation()`` performs the following steps:

1.  If no nr-llm Task is configured (``llmTaskUid`` is ``0``), the
    conversation is set to ``failed`` with a descriptive message.
2.  Resolve the ``LlmConfiguration`` the chat should use from the
    configured Task (``llmTaskUid`` -> ``Task`` -> ``getConfiguration()``).
    A missing Task or Configuration fails loudly rather than silently
    degrading to a no-tools chat.
3.  Set the conversation status to ``processing``.
4.  Build the message transcript: a ``system`` message carrying the
    identity/behaviour contract and the resolved Task/Configuration
    prompts (see Architecture > System prompt priority), followed by the
    stored conversation messages. File attachments are expanded to the
    multimodal wire shape and forwarded as array messages.
5.  Call ``AgentRuntimeInterface::run()`` with an ``AgentRunRequest`` built
    from the configuration, the messages and the initiating backend user
    uid. ``allowedToolNames`` is left at ``null`` so the run is offered the
    whole globally-enabled tool set; nr-llm's own tool gate (RBAC,
    global enable cascade, per-configuration groups) stays authoritative.
    The request carries a ``ToolOptions`` object whose only content is the
    caller source (see below).
6.  Map the returned ``AgentRunResult`` onto the conversation.

Caller-source attribution
=========================

Every run started here is tagged with ``withCallerSource()`` so nr-llm's
Analytics module lists this extension's usage and cost under
``nr_mcp_agent`` instead of grouping it as *Unattributed*. The operation
names the turn:

*   ``chatTurn`` -- a turn on a queued conversation.
*   ``resumeChatTurn`` -- the same turn re-run over an existing transcript
    by ``resumeConversation()``.

The tag is call metadata persisted on nr-llm's telemetry row; it is never
sent to the provider, and the ``ToolOptions`` object carries nothing else,
so no provider option is overridden by it.

The approval continuation (``AgentRuntimeInterface::approve()``) is not
tagged: it takes no options object, and nr-llm keeps the caller source out
of the persisted run state, so those provider calls stay unattributed.

Outcome mapping
===============

``AgentRuntime::run()`` never throws for a run outcome; it returns a
settled ``AgentRunResult``. ``ChatService`` maps it as follows:

*   ``COMPLETED`` -- append the final assistant answer
    (``ToolLoopResult::$finalContent``) and set status ``idle``.
*   any other outcome (``FAILED``, ``GUARDRAIL_BLOCKED``,
    ``AWAITING_APPROVAL``, …) -- set status ``failed`` with a sanitized
    reason taken from ``AgentRunResult::$error`` or derived from the
    outcome. The mapping keeps a default arm because ``AgentRunOutcome``
    gains cases in nr-llm minor releases.

The tools the model can call, their execution, retry/back-off on
transient provider errors, budget enforcement and the iteration cap all
live inside nr-llm now.

Synchronous execution and resume
================================

``AgentRuntime::run()`` is synchronous and drives the entire tool loop in
one call, so a turn never leaves persisted "pending tool calls" in the
conversation. The CLI worker (``ai-chat:process`` / ``ai-chat:worker``)
therefore always calls ``processConversation()``.
``resumeConversation()`` re-runs the turn over the existing transcript
for a resumable conversation (``processing``, ``tool_loop`` or
``failed``), which is used to recover a conversation left ``processing``
by a crashed worker.

MCP servers
===========

External MCP servers are configured in nr-llm (module *MCP Servers*), which
imports their tools into the same registry and agent loop the chat runs on.
The stdio MCP client this extension once shipped was removed in 0.12.0; it
had not been used by the chat turn since 0.11.
