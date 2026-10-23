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

**nr-llm's refusals of a process decision.** When nr-llm refuses a
decision because the run is a guided process (nr-llm PR 1029), the chat
keeps the conversation usable: a run that still waits brings its card or
question back with the chat's own sentence; a run nr-llm stopped because
the configuration requires a second approver leaves the conversation idle,
and ends the process as the × does, with the reason. A process nr-llm
drops before a run, for any reason (ADR-019), ends the same way: its
skill and header go, and the dropped line says why.

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

**What else the process keeps fixed.**

-   The three answers keep their names in every process: *Übernehmen*,
    *Andere Variante*, *Überspringen*; labels per action are not used. A
    tool that proposes an image is to name its variant answer itself
    ("Anderes Bild"); nr-llm has no way to declare it yet, so the chat
    shows *Andere Variante* for every tool.
-   The overview of findings is the skill's own text. The number of points
    in it is the *m* the process reports with ``chat_set_progress``.
-   Where a source is missing, the process invents nothing. The next steps
    are a choice through nr-llm's choice builtin ``ask_choice`` (nr-llm PR
    1029), shown with the reply buttons of ADR-018: the options are the
    call's own, rendered as text, and the button sends its option as the
    answer. Its skip option is worded by the skill ("Ohne <Thema> weiter"),
    so that *Überspringen* keeps its one meaning; the chat adds no wording.
    The same holds for plain choices such as "Los geht's" / "Fertig" after
    the overview. Nothing in the chat is specific to the tool: its schema is
    a choice under ADR-018's contract.
-   The page choice confirms with *Diese Seite prüfen* rather than
    starting on the click in the page tree, so browsing the tree starts
    nothing.
-   Suggested pages come from a deterministic provider, never from the
    model, at most five, each a page the user may edit in that language:
    for the SEO process pages without a meta description, with a title
    shorter than 30 characters or without a social image, open points
    first; for the content process its open points only.
-   Context actions such as "Kürzer" or "Sachlicher formulieren" are not
    part of this release.

**Not adopted, for now.**

-   Site-wide scan tiles, and the widgets "Website prüfen" and "Website
    bearbeiten" (bulk operations): no scanner exists, and bulk writes
    conflict with one write per turn.
-   Markers in the page tree: the tree is core UI, and a dot has no text
    alternative.
-   An analysis checklist with ticking checkboxes: no tool reports progress
    per criterion, and the chat's Markdown strips ``input``. Instead, the
    header shows the label "Analyse läuft" without a count.
-   Internal links set on other pages within one proposal: one
    approval-bound call per turn, and the targets lie outside the subject
    page.
-   "CTA nicht ändern" as a fourth answer on the card: *Überspringen* means
    "jetzt nicht". A "dismiss, record nothing" answer would need its own
    ADR decision.
-   A site selector and "Einstellungen" in the dashboard header.

**Seams.** Where nr-llm does not have what a decision needs, the chat reads
it through one class or interface and falls back:

-   ``StructuredPreview`` reads ``PendingCallView::structuredPreviewArray()``
    (nr-llm PR 1036). Without entries: the preview lines.
-   ``ProcessPinReleaseInterface`` releases the process pin of an ended
    process. ``ChatService`` implements it with nr-llm's
    ``AgentRuntimeInterface::endProcess()`` (ADR-228), as the run's
    initiator, for the conversation's last run. The × and the
    second-approver stop call it, and both also clear the conversation's
    last run, so no later turn names the ended process's run as its
    predecessor. A failing ``endProcess()`` is logged; the chat state is
    cleared either way and the editor sees nothing more.
-   ``SkillInvocationInterface`` (ADR-019) starts a run with an invocation;
    ``NrLlmSkillInvocation`` implements it on nr-llm's ``SkillInvocation``.
    Without it no new start is offered.
-   ``HighlightTargetPolicyInterface`` limits the highlight to the targets a
    run registered from its invocation's subject, once nr-llm has them.

Consequences
============

-   Two conversation columns, ``approval_card`` and ``card_outcomes``: run
    the database analyzer after upgrading.
-   **Needs nr-llm 0.41** for the target, the outcome, the guarded cancel
    (nr-llm PRs 1023, 1024, 1025) and the structured preview (nr-llm PR
    1036).
-   Suggested pages for the choice and *Nächste Seite prüfen* come from the
    dashboard's page suggestions and are not part of the chat yet.
-   The three answers keep one meaning each. A context action, once one is
    built, is a message of its own, which withdraws the waiting proposal.

Amendment: the first run starts with the conversation
=====================================================

**Date:** 2026-10-21

**Context.** A conversation created with a process skill and a page — from
the dashboard's "Eine Seite verbessern", or by *Zur neuen Seite wechseln* —
showed the header with "Analyse läuft" and ran nothing: the only thing that
dispatched a run was a sent message, and the editor was expected to type
one. The decision above says the header shows "Analyse läuft" until the
first progress report, and the process skills say the editor formulates no
prompt. Separately, a message sent from the chat module carried page 0,
because no page module sits beside it, and the turn replaced the
conversation's page with it; the run then started without a subject and
asked for a page.

**Decision.**

-   **A process about a page starts when the conversation is created.**
    ``POST /ai-chat/conversations/create`` with a page and a process skill,
    where nr-llm can start a run with an invocation, appends the first turn
    and hands the conversation to the worker in the same request. These are
    the conditions of ``tourStart``, so every start the chat offers begins; a
    skill the catalogue does not mark counts as a process there and here.
    The run carries the invocation with the page as its subject (ADR-019),
    as every first run of a process skill does. Without a page the chat asks
    for one, as before; a skill marked as no process waits for the editor's
    message.
-   **The first turn is the page choice's own words.** nr-llm has no start
    without a user message: its runs are built over a transcript, and a
    provider such as Anthropic's refuses a request without one. The turn is
    therefore *Diese Seite prüfen* in the editor's language — the label of
    the page choice's button, which starts a process without a page in the
    same way. It is stored and shown like any message, never hidden, and the
    model receives exactly what the transcript shows. The click on the
    dashboard is the editor's confirmation, so browsing the page tree still
    starts nothing.
-   **The start is a turn.** The limit on running conversations applies;
    over it the start is refused and nothing is created, as every refused
    start (ADR-019).
-   **A process keeps its page.** While a conversation has a process skill
    (or a skill the catalogue does not mark) and a page, a sent or edited
    message updates the module of the view context, never its page or
    language: another page is a new conversation through *Zur neuen Seite
    wechseln*. A process without a page takes the page its page choice
    sends; a conversation without a skill follows the browser as before.

Amendment: a process run has its own round cap
===============================================

**Date:** 2026-10-22

**Context.** nr-llm stops a run after a number of model rounds. The cap is
the caller's to ask for: nr-llm ADR-101 makes ``maxIterations`` on
``AgentRunRequest`` an explicit request, clamped to the runtime's ceiling
(``AgentRuntime::MAX_ITERATIONS``, 20), and keeps the loop's own default of 5
when the caller asks for nothing. The chat asked for nothing, so every tour
turn had 5 rounds. A run that reaches the cap ends with one more call
without tools, whose answer may be empty.

Three tour turns on the demo on 2026-10-22 ran into it. Each used its
five rounds and ended with the extra call, before the step that closes
the turn:

-   SEO tour, first turn: four rounds of reads (the page, its elements,
    the open points, two image searches), the overview with
    ``chat_set_progress`` in round 5. The extra call returned no text; the
    chat stored the empty answer as an empty message, and the tour stood
    at "Punkt 0 von 2" with nothing to click. ``ask_choice`` never ran.
-   Content tour, first turn: four rounds of reads with three to five
    calls each, ``chat_set_progress`` in round 5. The question
    "Los geht's" / "Fertig" came as plain text, not as ``ask_choice``.
-   Content tour, the turn after the editor typed "Los geht's": three
    rounds of reads, ``chat_set_progress`` in round 4,
    ``chat_highlight_element`` in round 5. The proposal never ran, so no
    card appeared.

So a tour turn needs three to four rounds of reads, then the progress,
the highlight where there is one, and the choice or the proposal: six
rounds in all three turns, more when the model reads more. The model
calls the chat's tools one per round even where the skill allows them
together. The one segment measured after an answered choice, up to the
proposal, needed 2.

**Decision.**

-   **A run that starts or continues a process asks for its own cap.** The
    setting ``processRunMaxIterations`` (default 12) goes on every run
    whose conversation has a skill the catalogue marks as a process: the
    first run, which carries the invocation, and every turn that names its
    predecessor. 12 is twice the six rounds each measured turn needed and
    leaves room for a page whose elements are read one by one; it stays
    below nr-llm's ceiling, which clamps any larger value. A run without a process asks for
    nothing, as before.
-   **An empty answer is no message.** A completed run whose final text is
    empty adds no assistant message.
-   **A run stopped at the cap says so.** When nr-llm reports the
    termination reason ``max_iterations``, the chat adds one line, stored
    language-neutral with the notice ``roundLimit`` and shown in the
    reader's language; the model is not sent it. In a running process the
    chat offers *Weiter* beneath it while nothing else was sent and
    nothing waits in the input, a draft or an attachment. Like
    *Diese Seite prüfen*, the button sends its own words as the editor's
    turn, shown like any message, and that turn continues the process from
    its predecessor (ADR-019). A choice the chat offers itself, worded by
    the chat, as *Fertig* and *Andere Seite wählen* are; it is not an
    answer to a choice of the skill.

**Consequences.**

-   A tour turn can cost up to 12 rounds instead of 5. The forced final
    call of a stopped run comes on top, as before.
-   nr-llm's ``approve()`` and ``submitInput()`` take no cap, and its
    resume passes none on: a segment after *Übernehmen* or after a choice
    gets the loop's default of 5, whatever the run asked for. One measured
    segment of that kind needed 2. Resumed segments get the run's cap
    once nr-llm keeps it across a resume.

Amendment: a step left open offers to go on
============================================

**Date:** 2026-10-23

**Context.** A step of a process ends with a choice (``ask_choice``), a
proposal (a writing tool, which waits for its decision) or the end of the
process (``chat_set_progress`` with ``completed``). Each of them leaves the
run waiting or the process completed. The model does not always do so.
On the demo on 2026-10-23, with the round cap of the previous amendment in
place, three runs ended their step otherwise:

-   Run 953235de (content tour, first turn) read the page in three rounds
    and wrote the overview in the fourth, then ended without
    ``chat_set_progress`` and without ``ask_choice``. Two further first
    turns on the same page ended with the choice.
-   After *Überspringen* the same tour wrote "Als Nächstes geht es um den
    Nutzenabschnitt." and ended without the proposal.
-   Run b2a7165a (content tour, last point) set the progress to 3 of 3
    after the last choice and ended without ``completed``, so the chat
    showed no summary.

In each case the chat showed text, or nothing, and no control to answer:
the editor had to guess that a message would make the process go on.

**Decision.** A completed run of a conversation whose skill the catalogue
marks as a process, whose progress is not completed and which did not
stop at the cap, gets one line, stored language-neutral with the notice
``stepOpen`` and shown in the reader's language; the model is not sent it.
The chat offers *Weiter* beneath it under the same conditions as beneath
the line about the cap.

The chat does not infer the end of the process from a progress of n of n:
a model that counts the last point before its proposal would end the
process with the proposal still unmade. *Weiter* lets the model finish
instead.

**Consequences.**

-   A step that legitimately ends with text, such as a short answer to the
    editor's question or the wait for information the editor said they
    would add, gets the line too. The chat cannot tell the two cases
    apart, so the line says only what holds in both: the assistant waits
    for the editor, who can reply or continue with *Weiter*. It does not
    say that a step was cut short, which would read as a fault where the
    wait is intended.


Amendment of 2026-10-23: the approval card is a system step
============================================================

**Context.** The card of a pending write sat inside the status line of
the chat: centred, small, italic and blue like "Not carried out", and
worded in the first person of a message. Editors read it as something the
assistant said, although it is not part of the conversation: the model
orders a write, the system checks the backend setting, and only because
that setting requires approval does it pause and show the card. Without
the setting the write is stored directly and no card appears.

**Decision.** The chat has two kinds of content. Chat messages are
written by the model. The approval card is rendered mechanically from the
pending write call and shows what is stored where. The card stays
recognisably different from a message, and a system status line does not
speak in the assistant's first person.

-   The card is a frame of its own, outside ``.message`` and
    ``.message-row``: full width of the message column, left edge of the
    column, no avatar, no bubble shape. A header band carries an icon and
    "Freigabe erforderlich" / "Approval required"; the first line below
    it reads "Folgende Änderung wartet auf deine Freigabe". Text is
    regular, not italic. The frame keeps ``tabindex="-1"`` and the
    ``status-notice`` class, which focus returns to after a decision.
-   The heading names the field when the change touches exactly one:
    "„Titel“ ändern", with the structured preview's label where nr-llm
    sends one, else the schema's. The approve button of a plain card keeps
    the action label. Otherwise the heading stays as before.
-   "Betroffen" reads "Diese Seite · <fields>" when the target is the page
    the conversation is about; any other record keeps table and uid.
-   The technical details (tool name, arguments, technical line) are for
    administrators. The response carries ``showTechnicalDetails``; for
    anyone else the three values are empty in the response, not only
    hidden by the card.
-   The status line of a variant decision reads "Vorschlag nicht
    übernommen. Eine andere Variante wird erstellt." The button keeps
    "Andere Variante", which is also the transcript line the model reads.
-   The answers of the card and the tour (``btn-answer``) and message text
    are 14px; the compact ``btn-sm`` of the header stays at 12px.
-   The skill chip reads "Aktuelle Aufgabe:" and the skill's human title
    (``SkillLabels``), else its name.
