.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

.. _adr-018:

======================================================
ADR-018: A question is answered with reply buttons
======================================================

**Status:** Accepted

**Date:** 2026-10-08

Context
=======

nr-llm lets a tool stop a run to ask the user for typed input (nr-llm
ADR-105): the run is suspended ``WAITING_FOR_INPUT`` with the tool's input
schema, and ``AgentRuntimeInterface::submitInput()`` continues it with the
user's values, bound to the question shown by a digest (nr-llm ADR-150).

The chat did not handle that pause. ``ChatService::applyResult()`` mapped
the ``AWAITING_INPUT`` outcome to ``failed`` with "The assistant run did not
complete (awaiting_input)", and the approval card dropped an input view.

A guided tour — analyse a page, then one point at a time — has two kinds of
interaction. Choices that change nothing (which point to start with, a
summary or the next page) are questions the run asks. A proposed change with
the answers *Übernehmen*, *Andere Variante* and *Überspringen* is a write,
and a write goes through an approval: nr-llm forbids a writing tool from
also asking for input (nr-llm ADR-134), and an approval's continuation
carries no input. The chat needs both: the question answered with buttons,
and the approval card denied with a reason.

Decision
========

**A run that asks parks the conversation.** The new status
``awaiting_input`` is set with the run's uuid, like ``awaiting_approval``.
It is neither active nor resumable: Retry would start a second run and
leave the question unanswered. When the schema states a question (its
``title``, else its ``description``), it is appended as the assistant's
message, so the reader sees what is asked and later turns see what an
answer answered.

**The answer goes through the worker, like a decision.** The request checks
the answer against the question as it stands, records it on the row
(``approval_decision = 'input'``, the digest in ``approval_turn_digest``, the
values in the new column ``pending_input``), appends it to the transcript and
dispatches the worker. The worker calls ``submitInput()``, which drives the
whole continuation. Recording it as a decision of its own kind means
everything that guards a recorded approval guards the answer too: Retry
refuses while it is in flight, and ``reconcile()`` hands the question back if
no worker ever took it.

**A refusal brings the question back.** nr-llm refuses a stale question, an
answer that does not validate, a submitter who may not run the tool, a lost
race, a deactivated configuration and a run that no longer waits, and leaves
the run waiting in each case. The chat restores ``awaiting_input``, takes the
undelivered answer out of the transcript and stores the reason as a code
(``InputHandBackReason``), rendered as a sentence in the reader's language.

**The schema contract the reply buttons are read from**
(``InputPauseForm``):

*   *Choice*: an object schema with one choice property — ``enum`` of
    scalars, or ``oneOf``/``anyOf`` of ``{const, title}`` branches for
    labelled options — and at most one optional free-text property (a
    string without ``enum``, ``oneOf``, ``anyOf`` or ``const``). Each option
    is a button. When the choice is not required, text typed into the chat
    input is submitted as the free-text property. Exactly one of the two is
    ever submitted.
*   *Form*: any other object schema of scalar properties, one field per
    property, submitted together.
*   *Unsupported*: anything else, and a pause nr-llm cannot render. The
    chat says it cannot show the question.

A tool that wants reply buttons — the generic "ask the user to choose" tool
nr-llm will add, for one — has to put its options into the input schema the
run is suspended on.

**Typed text without a free-text property is a message of its own.** It
leaves the question unanswered and starts a new turn, as a message leaves a
pending approval behind; the reply options say so ("A message of your own
ends the question"). Refusing the message instead would break the promise
that free text is always possible.

**A write is never a question.** "Übernehmen / Andere Variante /
Überspringen" for a proposed change is not one input pause: nr-llm forbids a
tool that writes from also asking for input (nr-llm ADR-134), and an
approval's continuation carries no input. The change goes through the
approval card, where the approve button applies it. In a process run, and
only when a pending call writes (nr-llm ADR-214, item 9), the card's denial
is two buttons, *Andere Variante* and *Überspringen*; every other card keeps
*Abbrechen*. Whether a call writes is nr-llm's own resolution by tool name
(``ToolEffectResolver``), in which an unknown tool counts as a write.
Whether the conversation is a process run is asked through
``ProcessRunDetectorInterface``; nothing implements it yet, so until a
conversation carries the skill it runs, every card is a plain one. The
server decides which answers a card has, and a reason sent for a card that
does not offer it is a plain denial. The reason (``variant`` or ``skip``,
``DenyReason``) is recorded with the decision and its label goes into the
transcript as the reader's message. ``ApprovalDecisionFactory``
hands the reason to nr-llm as soon as nr-llm's ``ApprovalDecision`` takes a
string argument named ``denialReason`` (or ``reason``); until then the
denial is a plain one, and the model learns the reason from the transcript
on the next turn. Input pauses with reply buttons stay for choices that
write nothing.

**The option check is the chat's.** nr-llm validates a submission by
structure only (nr-llm ADR-105). Membership in the offered options, and the
type of a form field, are checked before the answer is recorded; otherwise a
crafted request could put any text into a field the user was only offered
buttons for.

**An answer is not gated like an approval.** ``mayDecideApprovals()`` guards
who may release a write fence. An input-requiring tool cannot declare a write
(nr-llm ADR-105, ADR-134); the owner may act on their own run; and nr-llm
checks that the submitter may run the tool the question belongs to (nr-llm
ADR-150). nr-llm calls its own submit entry point admin-gated as the
injection mitigation for submitted values. In the chat the submitter is the
conversation's owner, who can already send the model any text as a message,
so the answer adds no new way in.

**A card decided elsewhere is closed, and a card left behind is cancelled.**
Two changes to the approval card's life, both from nr-llm ADR-214, item 9:

*   ``ChatService::reconcile()`` also looks at a conversation parked on a
    card. When its run was released or denied in the Agent Runs inbox and has
    finished there, the card is closed with the note ADR-017 introduced for
    "weiter" — the records the run wrote, read from its ``tool_write``
    events — and the conversation is idle. The poll and every new message
    run this first, so a message after a release in the inbox continues from
    what the run wrote instead of abandoning a decision already taken.
*   A new message while the card waits cancels the run behind it
    (``AgentRuntimeInterface::cancel()``) instead of only dropping the
    reference. Left waiting, the run could still be released in the inbox and
    write after the conversation had moved on. When the run was decided
    elsewhere between the read and the cancel, the message is refused and
    the card closed by the next reconcile. A "weiter" while the card waits
    stays what ADR-017 made it: a hint to decide on the card, not a new turn.

Consequences
============

*   For a write in a process run, the approval card's *Abbrechen* is
    replaced by *Andere Variante* and *Überspringen*; new column
    ``approval_deny_reason``.
*   nr-llm's ``cancel()`` cancels a run in any non-terminal state. The chat
    reads the status first and cancels only a waiting run, but a release in
    the inbox in the moment between the read and the cancel would be
    cancelled while it runs. A cancel that only applies to a waiting run is
    an open question for nr-llm.
*   nr-llm ADR-214 records the released run as the predecessor of the next
    turn. nr-llm has no predecessor parameter yet, so the chat stores none;
    the note in the transcript carries what the next turn needs to know.
*   New status ``awaiting_input``, new column ``pending_input``, new route
    ``ai_chat_conversation_input`` (``POST /ai-chat/conversations/input``).
    Run the database analyzer after upgrading.
*   The poll's fast path answers the first poll after a question with the
    full response, as it does for an approval, so the buttons do not wait
    for a reload.
*   Both chat surfaces render the answers from one module
    (``chat-reply-options.js``): real buttons in a labelled group, disabled
    while an answer travels, above the input; focus stays where it is.
*   A run that asks two questions in one turn is refused by nr-llm on the
    second (nr-llm ADR-105); the chat shows whatever the run is suspended on.
