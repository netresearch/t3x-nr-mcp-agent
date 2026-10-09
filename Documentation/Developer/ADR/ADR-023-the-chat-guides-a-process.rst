.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

.. _adr-023:

=============================================
ADR-023: The chat guides a process
=============================================

**Status:** Proposed

**Date:** 2026-10-10

Context
=======

A guided process walks an editor through a page one point at a time
(ADR-020): a skill run that proposes one write per point, decided on the
chat's card with *Übernehmen*, *Andere Variante* or *Überspringen*
(ADR-018), with its open points kept beyond the conversation (ADR-022).
nr-llm ADR-214 fixes what the runtime guarantees: one pending write per
turn in a process, the card as the only place a process write is decided,
and nothing the model writes as the source of what the chat states about a
record.

What the editor sees around that was still the generic chat: a technical
approval card, the model's own words for what happened, a header with the
process's label, a frame around an element without a name, and no way to
end, restart or move the process. This ADR fixes the chat's side.

The rule throughout: every word the chat shows about a proposal, its
outcome, the page or the process comes from the server — the card, the
run's result, the conversation, the schema's labels — never from the
model's text.

Decision
========

**The proposal is the card.** A card of a write in a process run with one
pending call renders in the message flow as a quoted block: the change's
name, *Betroffen* (nr-llm's structured target with the schema's labels),
and, where nr-llm structures the preview, *Aktuell*, *Vorschlag* and the
length against the configured range. The three answers sit directly under
it; *Übernehmen* is the approve answer's label. Without a structured
preview the block shows nr-llm's preview lines as they are; nothing is
parsed from them. The structured values are raw — stored rich text carries
its HTML, the proposed value is model text — and the chat binds them as
text only.

**The chat states each outcome.** When a card is decided, the conversation
keeps the card's target and, once the run's result is in, the outcome
(``ApprovedCallReading``, nr-llm ADR-214 item 9): *applied* when the write
states ``COMPLETE`` and no hook failed after it; *check* when a hook failed
after the write, the write states no completeness, or a run that was
cancelled, lost its lease or failed has no write step; *not applied* for an
error, a partial write, or no write step on a run that did not abort; and
*skipped*. The chat writes its own status line from it inside the message
list, and once the process reports completion a summary grouped into
applied, to check and skipped. The model may add a sentence; it never
reports the outcome.

**The header names the page.** In a conversation about a page with a skill
the header reads "<Seite> · <Sprache> · Punkt n von m", the page by its
title for a user who may show it, the language by its name, and "Analyse
läuft" before the first progress report. Short of space, only the page name
is shortened.

**The highlight is labelled.** The page module's frame gets a badge with a
fixed label from the page module's own language file, never from the
message, and the floating panel announces the marked element in a status
region. Which highlight a conversation keeps goes through
``HighlightTargetPolicyInterface``; today an element on the conversation's
page.

**The process can be ended.** A × beside the progress ends it through
``POST /ai-chat/conversations/end-tour``: a run waiting on a proposal or a
question is withdrawn with nr-llm's guarded cancel, and the conversation's
skill and guided state are cleared. Refused while a turn or a decision is
carried out; when the guarded cancel loses, the row goes back to what it
was. Nothing is written to a record, and applied changes, the outcomes
shown and the open points stay. The page module's mark is removed through
a clear message, the second shape of the highlight contract. Removing the
skill above the input during a process takes the same path.

**Starting anew is the chat's, behind one gate.** A process without a page
asks for one; at its end, *Andere Seite wählen* starts it in a new
conversation; when the page module shows another page, *Zur neuen Seite
wechseln* ends it and starts it there. Each of these is offered only where
the poll's ``tourStart`` says a run can be started with an invocation
(``SkillInvocationInterface``): nr-llm skips process skills on the forced
path, so a new start without an invocation would run without its process.
*Fertig*, the link to the dashboard where it is installed, and *Bei
„<Seite>“ bleiben* do not depend on it. The page is the one the page module
beside the floating panel shows, read from its URL while it matters.

**Seams.** Where nr-llm does not have what a decision needs, the chat reads
it through one class or interface and falls back:

-   ``StructuredPreview`` reads ``PendingCallView::structuredPreviewArray()``
    (nr-llm PR 1036). Without it: the preview lines.
-   ``ProcessPinReleaseInterface`` releases the process pin of an ended
    process. nr-llm has no call for an aborted process yet; nothing
    implements it, and ending works without it.
-   ``SkillInvocationInterface`` (ADR-019) starts a run with an invocation.
    Nothing implements it yet; without it no new start is offered.
-   ``HighlightTargetPolicyInterface`` limits the highlight to the targets a
    run registered from its invocation's subject, once nr-llm has them.

Consequences
============

-   Two conversation columns, ``approval_card`` and ``card_outcomes``: run
    the database analyzer after upgrading.
-   **Needs nr-llm 0.41** for the target, the outcome and the guarded cancel
    (nr-llm PRs 1023, 1024, 1025), and nr-llm PR 1036 for the structured
    preview. On nr-llm 0.39 the block shows no target and the preview
    lines, an approved write reads as *check* because no write states its
    completeness, and a waiting run is withdrawn by reading its status and
    cancelling it, with the race between the two that the guarded cancel
    closes.
-   Until nr-llm can start a run with an invocation, the page choice,
    *Andere Seite wählen* and the page switch stay hidden, and a process
    skill on nr-llm 0.41 runs without its process.
-   Suggested pages for the choice and *Nächste Seite prüfen* come from the
    dashboard's page suggestions and are not part of the chat yet.
-   The three answers keep one meaning each: a context action ("Kürzer")
    is a message of its own, which withdraws the waiting proposal.
