.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

.. _adr-034:

==================================================
ADR-034: The chat lists what an assignment changed
==================================================

**Status:** Accepted

**Date:** 2026-11-13

**Amends:** :ref:`adr-030` (the run record keeps the run's uuid) and
:ref:`adr-017` (the chat can show what the runs did, not only say it).

Context
=======

An assignment can write more than one record: a page copy takes its content
along, a translation writes overlays, and a hook of the installation can write
anything. The chat showed the editor one card per decision and, afterwards,
the model's text. The only user-facing summary of what was saved existed for
guided processes (``card_outcomes``, ADR-023); an ordinary chat with several
tool calls, or a run that was resumed after an approval, had none. The run
record of ADR-030 listed every write of a segment, but identifiers only and
only for the next prompt.

nr-llm now records, per tool call, the records the DataHandler wrote
(nr-llm ADR-292, run step ``tool_changes``): table, uid, action, workspace,
live uid, language, whether it is the record the tool named, the disable flag
and the time and group restrictions the row carries. It says honestly when it
does not cover a call (``not_captured``) and counts what it left out
(``changesOmitted``). The pending call it hands to the card carries workspace
and language as values, and a call refused by an invocation rule as such
(nr-llm ADR-293).

Two things were missing here. The conversation did not know its runs: it kept
the uuid of the last run and of the run awaiting approval, and its run
records did not name the run. And the approval card still found the workspace
by cutting nr-llm's prose line apart.

Decision
========

**The run record keeps the run's uuid.** Each entry gets ``run`` beside
``outcome`` (additive, in the existing JSON column, no schema change).
``Conversation::getRunUuids()`` lists the distinct uuids, oldest first; a
run resumed after an approval has several entries and one uuid. Entries from
before have no ``run``; the change view then uses the writes they list. The
uuid is not part of what a later prompt reads: ``RunRecordNotes`` reads the
fields it names. The limits of ADR-030 apply: the row keeps the newest 200
entries, and editing a message removes the entries of the turns it cuts, so
an assignment that was cut loses its changes from this view; the records
themselves stay in TYPO3.

**A list per conversation, read from the runs' events.**
``ConversationChangeReader`` reads, for each run of the conversation that the
conversation's owner may read (``AGENT_READ``, as for a pending run), the
events through ``AgentRuntimeInterface::events()`` and decodes the
``tool_changes`` events with ``ObservedChangeSet::tryFromEventPayload()``.
A record several calls changed is listed once: the later observation states
the state now, a record created earlier stays created, a delete wins, and
having been the tool's target once is kept. A call whose tool step failed
shows its list with a failure marker: the records it wrote before it failed
are the ones to check. A run without any ``tool_changes`` event, recorded
before nr-llm wrote them, is read through its ``tool_write`` events instead:
the record each tool named, shown without action, workspace or dependent
records and marked as such. A run with ``tool_changes`` events is never also
read through ``tool_write``, because both name the tool's target. Runs the
actor may not read, or that are gone, are counted and said so.

**Every fact is its own badge, and the list never says complete.** The view
states action, workspace (the one the row is stored in), language, hidden
(``hidden=false`` is "not hidden" and no word for public), the restrictions
the row carries, whether the record is the step's target or was changed
along with it, and a failure after the write, each as a separate badge. Under
the list a note always says what it covers: the changes TYPO3 reported for the
steps of the assignment, and that a change made another way does not appear.
Further notes appear where nr-llm reports a capture that is not complete,
where entries were left out (nr-llm's limit of 50 per call and this list's
limit of 100, counted together), where records are withheld from the viewer,
where runs predate the capture and where runs could not be read.

**The viewer's permission decides per record, here.** nr-llm names table and
uid of records the acting user may not be allowed to read, and the events of a
run are readable by whoever may read the run (nr-llm ADR-292). The chat
therefore shows a record only to a viewer who may select its table, to whom
its page is shown (page permissions and mounts, as the page module checks
them), who may access its language and the workspace its row is stored in, and
only while the row exists. Everything else is withheld whole - no table, no uid,
no title - and only counted ("n weitere Datensätze werden nicht angezeigt").
A deleted row is checked like any other; a row that is gone for good cannot be
checked and is withheld. The title of a workspace the viewer may not use is
withheld too; its id is not.

**Links where core offers one.** The record title links to the backend edit
form when the viewer may modify the table; "Änderungen ansehen" opens the
record history (``record_history``) for a record that was not deleted. The
workspaces module has no URL for one workspace: it shows the workspace the
viewer works in. The link to it appears only when that is the workspace the
record is in. A link opens in the backend's content frame when there is one
(``ContentContainer.setUrl``) and follows normally otherwise, so the chat keeps
its state.

**Delivery: a flag with the poll, the list when opened.** The messages
response carries ``changesAvailable`` (a run that wrote a record or had a tool
fail, which may have written first; or an old entry that lists a write), so a
conversation that only read shows nothing. The list is its own request
(``GET /ai-chat/conversations/changes``), made when the reader opens the
"Änderungen dieses Auftrags" disclosure and again when a poll reports a change
while it is open, so a poll never pays for reading events. The disclosure is a
native ``details`` element: reachable and operable by keyboard, announced as
expandable, in both surfaces and with the chat's theme tokens (light and dark).

**The approval card takes workspace and language from nr-llm's fields.**
``ApprovalCallPresenter`` no longer cuts nr-llm's workspace line out of the
preview lines. It words the chat's own sentence from ``workspaceId`` and
``workspaceTitle``, and says that the title is not visible to the reader when
nr-llm withheld it. The language comes from ``PendingCallView::$language``:
none is stated where the tool takes no target language, which is not the
default language. The prose line stays among the preview lines for the cards
that show them. Constraint: nr-llm with these fields (the constraint in
``composer.json`` names the release that has them).

**A call refused by a rule is shown refused.** The card marks a call with
``refusedByRule`` as one that will not be carried out; its preview lines are
the rule's explanation. The approve button names the calls that are carried
out; when every call is refused, the card offers no approval and says so
(``approveBlocked: allRefused``). nr-llm's decision is the one it takes when
the call executes; the card never promises an execution.

**The draft-workspace requirement is read from the run's configuration.**
nr-llm's card view carries no ``require_draft_workspace`` (``WaitingRunView``
has the configuration's label only). ``requiresDraftWorkspace()`` reads
``LlmConfiguration::getRequireDraftWorkspace()`` from the run's own
configuration, as ``requiresSecondApprover()`` does for the second-approver
setting, and the card says that the configuration requires a draft workspace
beside a call that would write live. It does not parse anything and is false
where the run or the configuration cannot be read; nr-llm refuses the write
itself in that case.

Consequences
============

-   The conversation table does not change. Entries written by this version
    carry one more key; older entries decode as before.
-   Opening the list reads the events of every run of the conversation once
    per opening and per reported change. A long conversation reads many runs;
    the list is capped at 100 records.
-   A record changed through a path the DataHandler does not report is not
    listed, and the view says that it cannot show it. For runs before nr-llm
    wrote ``tool_changes``, only the tool's own record is known.
-   The change view is read-only. It cannot undo a change; the record history
    link is core's.
-   The card's workspace sentence is the chat's wording, not nr-llm's; the
    two can differ in the preview lines of a plain card, which still show
    nr-llm's line.
-   Requires the nr-llm release that contains nr-llm ADR-292 and ADR-293
    (``tool_changes`` events, ``PendingCallView`` ``workspaceId``,
    ``workspaceTitle``, ``language`` and ``refusedByRule``).

Not decided here
================

-   Several cards of one turn (the single pending call that ``cardSnapshot()``
    handles) is unchanged; the change view does not depend on card capture.
-   A hint before the first message that the configuration requires a draft
    workspace and the editor works live. Without a pending call the chat has no
    run, and so no run configuration to read; resolving the configuration of
    the chat's Task for that hint is a separate decision.
