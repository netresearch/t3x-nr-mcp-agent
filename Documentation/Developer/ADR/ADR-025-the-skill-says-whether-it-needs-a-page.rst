.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

.. _adr-025:

=================================================
ADR-025: The Skill Says Whether It Needs a Page
=================================================

**Status:** Accepted

**Date:** 2026-10-25

Context
=======

Whether a conversation with a skill needs a page (:ref:`adr-019`) is
guessed today, in three places:

-   **The process marker.** ``ChatApiController`` treats a process skill
    (nr-llm ADR-214 item 6), and a skill the catalogue does not mark, as
    one that works on a page: it keeps the conversation's page
    (``keepsItsPage()``), asks for one (``tourStart().choosePage``) and
    starts with it (``startsTour()``). Every other skill follows the page
    the browser shows.
-   **The quick tasks.** :confval:`dashboardQuickTasks` takes a ``|page``
    suffix per entry; with it the *Frequent tasks* widget asks for the page.
-   **The prompt.** ``UserContextPrompt`` tells the model, when no page is
    selected, to ask which page is meant (``NO_PAGE``).

A skill that works on a page without being a process, or a process that
works on no page, cannot say so. nr-llm ADR-229 adds the frontmatter key
``page: required | optional | none`` and exposes it as
``Skill::getPageNeed()``, ``null`` when the skill declares nothing. It is not
part of the skill's version, so it changes no approval.

Decision
========

**The catalogue entry carries the declaration.** ``NrLlmSkillCatalogue``
adds ``page`` to each entry: ``required``, ``optional``, ``none`` or ``null``.
It is ``null`` when the skill declares nothing and when the installed nr-llm
has no ``getPageNeed()`` (checked with ``method_exists()``), so the chat runs
on an nr-llm without the field and the composer and ``ext_emconf``
constraints do not rise.

**One rule turns it into the page need** (``SkillPageNeed::of()``): the
declared value; else ``required`` for a process skill and for a skill the
catalogue does not mark or does not list (as everywhere since
:ref:`adr-023`); else ``optional``. An undeclared skill therefore behaves
exactly as before.

**Each call site keeps its own axis.** The process marker still decides
everything about the process: the start of a conversation with its first
turn (``startsTour()``), the refusal on a four-eyes configuration, the
invocation, the round cap, the open step and the process-run checks. The
page need decides everything about the page:

``required``
    The conversation keeps its page (``keepsItsPage()``: the sent message
    updates only the module; a deleted page makes a process read-only).
    Without a
    page the chat asks for one (``tourStart().choosePage``). For a process
    this needs, as before, an nr-llm that starts a run with an invocation,
    and ``tourStart`` keeps offering *Andere Seite wählen* and *Zur neuen
    Seite wechseln*. A skill that is no process gets the page choice only
    while it has no page, without needing an invocation: its turns carry
    the skill as a forced skill. It is offered no restart, because ending
    a conversation that is not a process does not withdraw a waiting run.
    For the same reason a deleted page does not make it read-only: it
    gives the page up and asks for a new one. The prompt keeps ``NO_PAGE``
    while there is no page.
``optional``
    Today's behaviour of a skill that is no process: the page follows the
    browser with every message, the prompt names it or says ``NO_PAGE``,
    no page choice. A process declared ``optional`` starts with whatever
    page the conversation has (its invocation subject) and does not keep
    it.
``none``
    The conversation is bound to no page. Creating it with a page, picking
    the skill and sending a message store no page, only the module; the
    invocation of such a process therefore has no subject. No page choice,
    no tour header, no read-only state for a deleted page, and the prompt
    carries neither page lines nor ``NO_PAGE``.

**Quick tasks follow the declaration; the suffix stays for skills that
declare nothing.** An entry asks for the page when its skill declares
``required``, and does not when it declares ``optional`` or ``none``,
whatever the suffix says: the skill knows best. For a skill that declares
nothing, or where nr-llm does not expose the field, ``|page`` decides as
before. The default of an undeclared process skill (``required``) is not
applied here: such an entry stayed a link without the suffix, and the chat
asks for the page after it opens; it still does. Every existing setting
therefore behaves as before.

Consequences
============

-   A skill author decides the page need once, in the skill; an
    installation no longer has to repeat it as ``|page``.
-   No new nr-llm requirement. With an nr-llm without ADR-229 nothing
    changes; the declarations take effect once one with it is installed.
    PHPStan sees only the installed nr-llm, so the ``method_exists()`` guard
    is always true against one with the field: that one finding is ignored
    in ``Build/phpstan/phpstan.neon`` with ``reportUnmatched: false``, and
    the check is green against both. The end-to-end tests run against the
    released nr-llm and therefore cover only the fallback; the declared
    values are covered by unit and functional tests.
-   The *Improve a page* widget (:confval:`dashboardGuidedSkills`) is not
    changed: it offers guided processes about a page and always asks for
    one.
-   The page choice of a skill that is no process sends *Diese Seite
    prüfen*, the page choice's own words, as its first message, as it does
    for a process.
-   A ``|page`` suffix on an entry whose skill declares ``optional`` or
    ``none`` is ignored.
