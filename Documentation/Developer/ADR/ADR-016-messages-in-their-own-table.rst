..  include:: /Includes.rst.txt

.. _adr-016:

===================================
ADR-016: Messages in Their Own Table
===================================

**Status:** Accepted

**Date:** 2026-09-23

Context
=======

A conversation's transcript was one JSON value in the ``messages`` column
(``mediumtext``) of ``tx_nrmcpagent_conversation`` (ADR-005). Every write of
the conversation wrote the whole value again, a message could not be read or
removed on its own, and the column grew without a natural bound — an image
reference, a long tool answer and forty turns all end up in one field that is
read and rewritten on every turn. Editing a sent message (NEXT-172) is a
truncation of that list. NEXT-172 asked for messages in their own table, with
an upgrade path that keeps existing conversations readable.

Two constraints come from the rest of the design:

-   **The claim is atomic.** Sending a message, recording a decision, retrying
    and editing all claim the conversation with a compare-and-swap on its
    status (``updateIf``), and a worker dequeues what is claimed. With the
    transcript in the same row, the new message and the claim were one
    ``UPDATE``. Split across two tables they must still be one unit: a claim
    that loses must leave no message behind, and a worker must never dequeue a
    conversation whose new message is not there yet.
-   **The model does not change.** ``Conversation`` hands the transcript to
    the service, the controller and the worker as one list, and several
    places depend on that (the turn, file counting, edit, export). A parallel
    change (NEXT-167) was editing the model and the service at the same time.

Decision
========

-   New table ``tx_nrmcpagent_message``: ``conversation``, ``sorting``,
    ``role``, ``payload`` (the message array as JSON — tool calls, attachment
    references and timestamps travel unchanged), ``crdate``; unique on
    ``(conversation, sorting)``.
-   ``ConversationRepository`` is the only place that knows. Loading one
    conversation fills the model's list from the rows; saving writes the
    conversation row and **replaces** the rows in one transaction; in
    ``updateIf`` the transaction wraps the compare-and-swap and rolls back when
    it loses. The ``messages`` column is written empty on every save.
-   Replace, not append: an edit truncates, and a transcript is dozens of rows,
    not thousands. Appending only the new rows is an optimisation for later;
    it needs the repository to know what is stored, which replace-all does not.
-   Rows written before the table existed stay readable: a conversation
    without message rows is read from the column. The first save moves its
    transcript; the upgrade wizard ``nrMcpAgent_migrateMessagesToTable`` moves
    the rest, one conversation per transaction, and keeps existing rows over a
    stale column value.
-   The list endpoints and the poll never read either — they use the
    denormalised ``message_count``, as before.
-   The hard delete in ``ai-chat:cleanup`` removes message rows whose
    conversation no longer exists.

Consequences
============

-   No code outside the repository, the cleanup command and the wizard
    changed; the model, the service and the controller see the same list.
-   Each save costs a delete and one insert per message inside a transaction,
    instead of one ``UPDATE`` of a growing value. For the sizes a chat has
    this is the same order of work; for very long conversations append-only
    writes are the follow-up.
-   Replacing the rows deadlocks under REPEATABLE READ (the MySQL/MariaDB
    default) when two new conversations are written at once: the delete of
    an empty range takes a gap lock, and both inserts wait on the other's.
    Reproduced on MariaDB 11.4 with two sessions. Every transaction of the
    repository is therefore restarted up to three times on a
    ``DeadlockException``, which is the remedy the database itself names;
    the work is a function of the conversation and safe to repeat. A lock
    wait timeout is not retried (it has already waited
    ``innodb_lock_wait_timeout``), and nothing is retried inside a
    caller's transaction, where the deadlock has rolled back more than the
    repository's part.
-   A legacy value that is not a JSON list — such a conversation could not
    be opened before either — is not destroyed by the wizard: it stays in
    the column behind the prefix ``!undecodable:``, which the wizard skips.
-   Both tables must live on the same database connection for the
    transaction to cover them — the TYPO3 default. An installation that maps
    ``tx_nrmcpagent_message`` to another connection loses the atomicity.
-   The ``messages`` column stays in the schema, empty after the wizard; it
    can be dropped in a later release once no installation needs the
    fallback.
-   Queries per message (search, a per-message export, a size limit) are now
    possible without decoding every transcript.
