.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

.. _adr-022:

=============================================
ADR-022: Open points of guided processes
=============================================

**Status:** Proposed

**Date:** 2026-10-09

Context
=======

A guided process proposes one change at a time on an approval card with
three answers: *Übernehmen*, *Andere Variante* and *Überspringen* (ADR-018).
A skipped proposal is a finding the editor did not want now, not one that is
wrong. nr-llm ADR-214 (item 9, "Open points persist in nr_mcp_agent") has
the chat keep such findings beyond the conversation and offer them again the
next time the process runs on the record, and it fixes the rules:

-   Recorded server-side in exactly one case: the editor answers a card with
    *Überspringen*. No tool records one, because a model call cannot
    guarantee that the record follows the card decision.
-   Keyed by the process skill, the subject record and the target record and
    fields of the pending write, as nr-llm's ``PendingCallView::$pendingTarget``
    names them. The preview's prose is never parsed.
-   Closed only by an applied write to the same record and field: a card the
    chat approved whose call came back with its write step stating
    ``COMPLETE`` and no hook failure after the write.

The parts of the key come from three changes: the card's answers and the
denial reason (ADR-018), the conversation's skill and page (ADR-019), and
the chat's tools (ADR-020).

Decision
========

**Recording.** The worker reads the card before it hands the decision to
nr-llm, from the view whose turn digest is the decision's, so the key is
what the card showed. Once ``approve()`` has accepted a denial with the
reason ``skip``, ``OpenPointTracker`` stores one row per field of the target
in ``tx_nrmcpagent_open_point``: process skill uid, subject record, target
table, uid and field. A write that names a record but no field (a move, a
delete) is one row with an empty field. Nothing is recorded when:

-   the reason is ``variant`` (a new proposal follows), or the denial has
    none;
-   the pending call names no target — it creates its record, or its tool
    does not say — or the card holds more than one call;
-   the conversation has no known skill uid or page;
-   nr-llm refuses the decision and hands the card back, or the
    continuation fails;
-   a proposal is withdrawn by a new message, or the run is cancelled.

A skip that reaches the worker always comes from a card that offered it:
the request turns a reason sent for any other card into a plain denial
(ADR-018).

**The subject is the conversation's page.** nr-llm ADR-214 names the
invocation's subject record; nr-llm has no invocation API yet, so the chat
uses the page the conversation is about (ADR-019).

**Closing.** After an approved decision, ``AppliedWrite`` reads the result
nr-llm returned for it: the first tool step is the approved call, and the
write step that follows it must state ``WriteCompleteness::COMPLETE`` with
``hookFailedAfterWrite`` not set. Then every open point on the card's target
record and fields is closed, whichever process recorded it, by deleting the
row: the table holds what is open. A partial write, a hook failure after
the write, a failed call, a call without a write step and a write that
states no completeness close nothing.

**Offered again.** A conversation with a skill and a page gets the open
points of that skill on that page in its system prompt, as record
identities and field names (at most 20), and the read tool
``chat_list_open_points`` lists a page's open points for a user who may show
the page, leaving out points on tables the user may not read. Neither holds
a value or the proposal's text.

**Site-wide, for the dashboard.** ``OpenPointVisibility::visibleForCurrentUser()``
lists the open points the current backend user may see across the site,
newest first, the limit counted after the visibility rule. Each
``VisibleOpenPoint`` carries the page, the target record's language, the
skill's uid and nr-llm's stored identifier, the target and the date, and a
sentence in the user's backend language, built from the TCA labels and the
page title ("<Field> is missing on “<Page>”" for an empty field, "<Field>
open on “<Page>”" otherwise; the templates are the labels
``openPoint.missing`` and ``openPoint.open``), never model text. The visibility rule also leaves out a point on a field the
user's groups may not edit (``exclude`` without ``non_exclude_fields``),
for every reader.

**Process runs.** ``SkillProcessRunDetector`` implements
``ProcessRunDetectorInterface`` (ADR-018): a conversation with a skill runs
a guided process unless nr-llm marks the skill as plain
(``Skill::isProcess()``). With it, a write in a skill conversation gets the
three answers, and a new message withdraws a waiting run of that
conversation (ADR-018).

Consequences
============

-   New table ``tx_nrmcpagent_open_point``: run the database analyzer after
    upgrading.
-   **Needs nr-llm 0.41.** The card's target (``pendingTarget``, nr-llm PR
    1024) and the write's completeness (``writeCompleteness``,
    ``hookFailedAfterWrite``, nr-llm PR 1023) are read where the installed
    nr-llm has them. On an earlier nr-llm a card has no target and no write
    states its completeness, so nothing is recorded or closed.
-   A finding lost to a withdrawn proposal, a refused decision or a failed
    continuation is found again the next time the process runs on the
    record (nr-llm ADR-214).
-   An open point stays when its target record is deleted outside the chat;
    it is closed only by an applied write.
-   The subject becomes the invocation's subject record once nr-llm can
    start a run with an invocation; the key does not change.
