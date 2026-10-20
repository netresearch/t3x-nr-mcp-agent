.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

.. _adr-019:

==========================================================
ADR-019: A conversation starts about a page, with a skill
==========================================================

**Status:** Accepted

**Date:** 2026-10-08

Context
=======

A guided process — optimise the SEO of one page, improve its content — is a
skill, not an extension (nr-llm ADR-214). The chat has to start such a process
for one page in one language, and an editor has to be able to pick a process
in a running conversation. The conversation stored only where the user was in
the backend (``view_context``: page and module), ``createConversation()``
took no input, and the module URL read only ``&conversation=<uid>``. nr-llm
has no API yet to invoke a skill by identifier per run; ADR-214 describes one
("explicit invocation") that is being designed.

Decision
========

**A conversation can start with a page, its language and a skill.**
``POST /ai-chat/conversations/create`` takes ``pageUid``, ``languageUid`` and
``skill``; the module URL takes the same as ``&pageUid=``, ``&languageUid=``
and ``&skill=`` and replaces them with ``&conversation=<uid>`` once the
conversation exists, so a reload opens it instead of starting another. The
checks are the user's own: the page must be one they may show
(``BackendUtility::readPageAccess()`` with ``PAGE_SHOW``), the language one
of the page's site that they may edit (``checkLanguageAccess()``; a page
outside every site has language 0 only), the skill one of the catalogue.
A refused start creates nothing.

**The language is part of the view context.** ``view_context`` gains
``languageId``, kept only with a page. The browser sends it where the module
URL carries it; the page module usually keeps it in its module data, so a turn
on the same page without a language keeps the one the conversation started
with. The system prompt names it next to the page, when the user may edit
that language.

**The skill is stored on the conversation and passed to every run** through
``SkillCatalogueInterface``, the one place that knows how nr-llm takes a skill
per run. The catalogue is the enabled skills attached to the chat's
configuration or Task (the Task the user's groups map to, ADR-015). A process
skill goes out as an invocation (below); any other skill goes as a forced
skill (``RunAugmentation``), which nr-llm composes like any skill — as fenced
reference data, under its trust and data-class ceilings — and deduplicates
against the attached ones. Where nr-llm lacks the classes and members this
needs, the catalogue is empty and every run goes without a skill; an
identifier is still stored, so nothing has to be repeated once nr-llm can
take it. The column is written on its own, like the instructions, so a turn
that settles later cannot put an old skill back.

**"/" in the input picks the skill.** A ``/`` with no space after it opens
the catalogue as a list on the input (ARIA combobox on the textarea: arrow
keys, Enter or Tab to pick, Escape to close). Picking sets the
conversation's skill (``POST /ai-chat/conversations/skill``) and sends no
message; the skill is shown above the input with a button to remove it.

**An invocation, with the page as its subject.** nr-llm ADR-214 passes a
process skill as an invocation — the skill's uid and a subject record —
rather than as a forced skill on every turn, and skips process skills on
the forced path. ``NrLlmSkillInvocation`` (behind
``SkillInvocationInterface``) builds nr-llm's ``SkillInvocation``: the
skill's uid from the catalogue and the conversation's page as the subject
record (``pages``, uid, the page's language; 0 when the chat does not know
it). Only a skill the catalogue marks as a process is invoked; any other
skill goes as a forced skill on every turn, as before. The skill's uid is
also kept beside the identifier (column ``skill_uid``, 0 when the catalogue
did not know it).

**The invocation goes out once; later turns name their predecessor.** nr-llm
ADR-228 derives a running process from the run before: a turn names that run
(``predecessorRunUuid``) and carries no invocation. The conversation stores
its last run in ``last_run_uuid`` after every run nr-llm recorded — completed,
failed, refused, stopped or waiting — and, unlike ``approval_run_uuid``,
keeps it through every status. A run nr-llm could not record has no uuid and
leaves the previous one. The invocation goes out on the conversation's first
run and on the turn after a process skill is picked in the "/" list
(``skill_start_pending``, set by the pick and cleared once a recorded run
carried the invocation); that turn is a new start and names no predecessor.
A turn whose skill has left the catalogue still names its predecessor, so
nr-llm can report that it dropped the process.

**A dropped process is said once.** When nr-llm drops the process — its skill
was detached from the configuration, the turn runs on another configuration,
the run it continues from is gone or not the user's, or too many turns in a
row could not continue it — it records a
``dropped`` step with the reason, which the run result carries on every
outcome. The chat adds one line before the answer, stored language-neutral
with the notice ``processDropped`` and the reason; the reader sees a sentence
for that reason in their own language (``detached``,
``configuration_changed``, ``predecessor_gone``, ``refused_too_often``; a
generic one for a reason
the chat does not know), and the model is not sent the line. nr-llm records no process for such a run, so the next turn
continues none. ``DroppedSource`` and ``DroppedSourceReason`` are marked
internal in nr-llm; the chat reads them until nr-llm declares them API.

**No skill on a four-eyes configuration.** nr-llm ADR-214 decides a guided
process only on the chat card; where the configuration requires a second
approver (nr-llm ADR-172), the run's owner cannot release their own write
there. A skill is therefore refused at the start and when it is picked
(409, ``error.skillSecondApprover``) when the chat Task's configuration
requires a second approver. nr-llm marks process skills
(``Skill::isProcess()``, nr-llm ADR-214), and only those are refused. A skill picked before the
configuration was switched to four-eyes keeps running.

Consequences
============

*   New columns ``skill_identifier``, ``skill_uid``, ``skill_start_pending``
    and ``last_run_uuid``, new routes ``ai_chat_skills`` and
    ``ai_chat_conversation_skill``. Run the database analyzer after
    upgrading.
*   A process skill goes out as nr-llm's ``SkillInvocation`` and is
    continued through ``predecessorRunUuid``; nr-llm decides from its
    approval which version runs (nr-llm ADR-214, ADR-228). Any other skill
    reaches the model as a forced skill, as reference data rather than
    instructions.
*   A skill picked while a turn runs is refused, as the instructions are.
*   The chat requires nr-llm 0.41, which carries ``SkillInvocation``,
    ``SubjectRecord`` and ``predecessorRunUuid`` (nr-llm ADR-228).
