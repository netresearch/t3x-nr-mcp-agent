.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

================
Console commands
================

The extension provides three Symfony console commands
for background processing and maintenance.

ai-chat:process
================

Process a single chat conversation. Used by the ``exec``
processing strategy -- the ``ChatApiController`` forks
this command for each incoming message.

..  code-block:: bash

    vendor/bin/typo3 ai-chat:process <conversationUid>

**Arguments:**

``conversationUid`` *(required)*
    UID of the conversation to process. The conversation
    must be in ``processing`` status.

**Exit codes:**

``0``
    The turn ran: the conversation ends ``idle``,
    waiting for an approval, or ``failed`` with an
    error message. Also returned, with an info line,
    when there is nothing to do: the conversation is
    not in ``processing`` because another
    ``ai-chat:process`` or a worker claimed it first,
    or because the turn is already over. That case
    leaves the conversation as it is.

``1``
    Failure -- conversation not found, or an error
    escaped the turn.

**Behavior:**

*   Claims the conversation first: one update moves it
    from ``processing`` to ``locked``, the same claim
    ``ai-chat:worker`` makes. A database deadlock on
    that update counts as a lost claim. The row stays
    ``locked`` until the turn ends, so no worker and no
    second ``ai-chat:process`` can take the same turn
    while it runs.
*   Initializes the backend user context for the
    conversation owner.
*   If the conversation has pending tool calls (crash
    recovery), executes them first via
    ``resumeConversation()``.
*   Otherwise, runs the full agent loop via
    ``processConversation()``.

ai-chat:worker
==============

Long-running worker process that polls for conversations
in ``processing`` status and processes them sequentially.
Used by the ``worker`` processing strategy.

..  code-block:: bash

    vendor/bin/typo3 ai-chat:worker [--poll-interval=200]

**Options:**

``--poll-interval`` *(optional, default: 200)*
    Poll interval in milliseconds. How often the worker
    checks for new conversations to process.

**Behavior:**

*   Runs indefinitely (designed for systemd or
    supervisord).
*   Uses ``dequeueForWorker()`` with atomic locking
    to prevent multiple workers from processing the
    same conversation.
*   Each worker identifies itself with a unique ID
    (PID + random bytes).
*   After processing, the backend user context is
    cleared to prevent leaking between conversations.

**Production deployment:**

See :ref:`worker-mode-production-setup` in the
Configuration section for a systemd service example.

ai-chat:cleanup
===============

Maintenance command that handles stuck conversations,
auto-archiving, and deletion of old data. Should be
run periodically (e.g. daily via cron).

..  code-block:: bash

    vendor/bin/typo3 ai-chat:cleanup \
        [--delete-after-days=90]

**Options:**

``--delete-after-days`` *(optional, default: 90)*
    Hard-delete archived conversations older than this
    many days.

**Actions performed:**

1.  **Timeout stuck conversations** --
    Conversations in ``processing``, ``locked``, or
    ``tool_loop`` status for more than 5 minutes are
    set to ``failed`` with a timeout error message.

2.  **Auto-archive inactive conversations** --
    Conversations in ``idle`` status that have been
    inactive longer than the configured
    ``autoArchiveDays`` are archived.

3.  **Delete old archived conversations** --
    Archived conversations older than
    ``--delete-after-days`` are hard-deleted from the
    database.

**Output example:**

..  code-block:: text

    Timed out 2 stuck conversation(s)
    Auto-archived 5 inactive conversation(s)
    Deleted 12 old archived conversation(s)

    Cleanup summary:
      Timed out stuck conversations: 2
      Auto-archived inactive conversations: 5
      Deleted old archived conversations: 12
