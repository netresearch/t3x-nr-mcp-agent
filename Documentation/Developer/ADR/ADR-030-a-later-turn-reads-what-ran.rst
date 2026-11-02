.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

.. _adr-030:

=====================================
ADR-030: A later turn reads what ran
=====================================

**Status:** Accepted

**Date:** 2026-11-02

**Amends:** :ref:`adr-017` (the note beside a flagged answer) and
:ref:`adr-025` (the page of a turn sent without one).

Context
=======

On the demo an SEO tour saved six fields of a page: the page title, the
OpenGraph image, title and description, and the X title and description. Each
was a card the editor approved; the record history shows every write. Three
days later the editor asked in the same conversation "was ist hier passiert?".
The answer was that nothing had been checked or changed, that the earlier
answers had been misleading and that the tour had not taken place.

The model had not seen the writes. A turn is a run of its own, and a new run
gets the transcript's text and nothing else: the tool calls of earlier runs,
their results, and what became of their cards are not part of it. The tour
was one run that waited on each card and continued after it, so while it ran
the model had all of it; once it ended, none of it reached a later turn. The
run of the question sent six messages — the system prompt, three user
messages and two assistant messages that joined the tour's own text — and no
tool round. The outcome lines the reader saw ("Seitentitel gespeichert") are
the chat's own rendering of ``card_outcomes`` and never went to the model.

What the model did see was its own proposals ("Ich schlage … vor") and the
rule of ADR-017: claim a change only on a successful write result, otherwise
say plainly that nothing was saved. Applied to a transcript without results,
the rule produced the denial. Time played no part: a question one minute after
the tour ends reaches the model the same way. Neither did the versions between
the tour and the question; the transcript has carried text only since the
chat stored the text beside tool calls.

The page was a second, separate gap. Ending the tour clears the skill
(ADR-023), and a conversation without a skill follows the browser (ADR-025).
The question came from where no page is shown, so the turn stored page 0 and
the model was told that no page is selected.

Decision
========

**The chat keeps a record of every run segment and gives it to every later
turn.** When a run segment returns — a turn, a continuation after an approval
or an answer, or one that failed — the chat stores in ``run_record``:

-   how the run ended (completed, waiting, failed, …; ``unknown`` when the
    turn failed before nr-llm returned its steps);
-   the tools that ran, with the number of calls and of failed calls, leaving
    out the tools that only drive the chat's display or ask the editor, and
    the calls of a turn whose card was not approved: nr-llm records those as tool
    steps with an error result before the continuation's first model round,
    and listed as failed they read as failed attempts to save (amendment of
    2026-11-02; two of five answers to "was ist hier passiert?" after three
    *Behalten* answers on the demo said so), and the calls nr-llm held back
    while the run waited (see the amendment below);
-   the records written, as ``table:uid``, and whether nr-llm reports the
    write complete, partial or unconfirmed;
-   the calls still waiting for a decision;
-   the decision taken on a card, where no card outcome already says it — a
    plain denial records none there;
-   a card or question the editor left undecided, by writing a new message
    or ending the tour: otherwise its record would go on saying that it
    waits. The note says that nothing from it was saved in the chat. The
    run of an ordinary chat is not cancelled and may still be decided in AI
    Tasks; the note says that such a later decision is not recorded here. An
    edit records nothing: it cuts the waiting turn away.

Each entry also notes how many card outcomes existed before it, so notes at
the same transcript position keep the order in which they happened. The row
keeps the newest 200 entries.

**An edited message takes its turns' record with it.** Editing a message cuts
the transcript after it; the run records and card outcomes of the cut turns
go too. Kept, they would be read as the record of the turns that take their
place.

The record is built from the run's structured steps, never from the model's
words, and only from what nr-llm's privacy filter keeps at every level: tool
names, error flags, write targets and completeness. Tool arguments and results
are not read, so no stored value reaches a later prompt through it. Card
outcomes add the field names a card named. Every identifier is reduced to the
characters identifiers use.

**The record goes to the turn it belongs to, as the chat's words.** Each turn
builds notes from the record and the card outcomes, labelled "[Chat record —
written by the chat from the run, not by the assistant: …]", and places each
in the assistant message of the segment it describes; a segment without an
answer gets an assistant message of its own between the two user messages
around it. The model is called on a user turn, so a note that would open or
close the transcript — the record of a failed segment, read again by a retry —
goes into that user message instead, labelled the same way. The notes are never stored in the transcript. At most 40 notes and
6000 characters are sent. Over that budget, notes of steps in which nothing
ran, nothing was written and nothing was decided are left out first, then the
oldest; the first note then says that older notes exist and cannot be read in
this turn, rather than letting the gap read as "nothing happened".

**The note beside a flagged answer is scoped to its step (amends ADR-017).**
It said "Nothing it describes as done was saved". An answer that sums up what
earlier steps saved gets the ``nothingSaved`` notice too, and the note then
told the model that those saves never happened. It now says that a change the
answer describes as done *in this step* was not saved, and that what earlier
steps saved is in the chat records.

**A turn sent without a page keeps the conversation's last page apart from the
selection (amends ADR-025).** ``view_context`` keeps ``lastPageId`` when a turn
comes with page 0. It is no selected page: the skill invocation, the
highlight, the list and everything else that acts on the selected page read
``pageId`` as before. Only the prompt uses it: instead of "No page is selected", the model is told
that no page is selected now, which page was last selected in the
conversation, that the transcript and the chat records say which pages earlier
steps worked on, and to ask before new work on a page the user does not refer
to. The page is named only when the user may see it, as the selected page is.

**No new system-prompt rule.** The record is the fact; whether the model also
needs a rule to answer questions about past actions from it is measured on the
demo after the release, and a rule is added only if the measurement shows it
is needed.

Consequences
============

-   The conversation table gains the column ``run_record``. Rows from before
    have none; their card outcomes still reach the model as notes, so a tour
    finished before the upgrade is reported from its cards.
-   Every turn carries its notes; a tour of eight segments and six cards adds
    about 2,500 characters. Conversations longer than the budget lose their
    oldest notes, and the model is told so.
-   A conversation about a missing alt text (ADR-026) replaces the transcript
    with its own and gets no notes: its write is confirmed in the transcript
    already.
-   The record says what ran and what was written, not what the values were.
    "Which title was saved?" is answered by reading the record, as before.


Amendment of 2026-11-02: calls nr-llm refused
=============================================

**Context.** nr-llm records every call it refuses as a tool step with an
error result, like a tool that ran and failed. The chat's activity list showed
"fehlgeschlagen" under "Von dir abgelehnt" after every *Behalten*, and the run
record counted calls nr-llm held back as failed tools. nr-llm 0.41.7 to 0.43.4
refuse calls in these places (``ToolLoopService``):

..  list-table::
    :header-rows: 1

    *   -   Refusal
        -   Reading
        -   Shown to the editor
    *   -   A declined card's calls, and a ``load_skill`` of the declined turn
        -   decision
        -   the answer (*Behalten*, *Überspringen*, *Andere Variante*, or
            "abgelehnt"), no tool entry
    *   -   An approved call whose tool is no longer offered (switched off,
            admin-only)
        -   failure (``not_permitted``)
        -   "nicht ausgeführt: nicht mehr freigegeben", and to ask an
            administrator to allow it again
    *   -   After an approval: a question (``ask_choice``) the model asked in
            the same turn, outside a guided process
        -   not run (``held``)
        -   "noch nicht ausgeführt"; the model asks it again
    *   -   After an answer: the question's own tool, no longer offered
        -   failure (``not_permitted``)
        -   as for the approved call: the answer was not taken
    *   -   After an answer: another call no longer offered, a second call
            that needs input, a write without an approval
        -   not run (``held``)
        -   "noch nicht ausgeführt"
    *   -   Beside a proposal or a question: a second write, a call that
            needs input, a remote call
        -   not run (``held``)
        -   "noch nicht ausgeführt"
    *   -   A call the invocation policy refuses (not permitted, unknown,
            denied by a rule), in any segment
        -   failure
        -   "fehlgeschlagen", as before: nr-llm times these like a call that
            ran

**Decision.** ``RefusedCalls`` reads one segment's steps for both the
activity list and the run record, so the two cannot disagree. It reads
structure, never the result text: where the step stands (a continuation opens
with the waiting turn's calls, before its first model round), its duration
(nr-llm records the refusals of a waiting turn's calls with exactly 0.0 ms; a
call that ran is timed) and whether the tool asks the editor something
(``ask_choice``, nr-llm's only such tool: after an answer nr-llm runs every call
of the question's tool with the answer and refuses one only when the tool is no
longer offered).
A declined card's calls get no entry in the list and no place in the record;
a call held back is listed as not run and left out of the record; an approved
call nr-llm no longer permits is a failure in both, with its reason in the
list. An answered question nr-llm no longer permits is a failure in the list;
the record leaves ``ask_choice`` out, as it always did. A declined card's entry
names the answer the editor gave.

**Consequences.**

-   The reading depends on nr-llm recording refusals with a duration of 0.0.
    A refusal recorded with a measured duration would be shown as failed
    again, which is the state before this amendment.
-   After an answer, another call whose tool was switched off while the
    question waited reads "noch nicht ausgeführt", not "nicht mehr
    freigegeben": nothing in the step separates it from the other refusals of
    that path. nr-llm would have to type the refusal for the chat to tell them
    apart.
-   A tool of another extension that asks the editor something is read like
    any other tool until it is added to ``RefusedCalls::QUESTION_TOOLS``.
-   Activity rows written before the upgrade keep their entries.

