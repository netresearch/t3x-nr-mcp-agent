..  include:: /Includes.rst.txt

.. _adr-015:

==================================================================
ADR-015: nr-llm Task per Backend Group via Extension Configuration
==================================================================

**Status:** Accepted

**Date:** 2026-09-23

Context
=======

The chat ran every user on one nr-llm Task (``llmTaskUid``). Installations
want different models or prompts for different audiences — editors on a
cheaper model, administrators on one with a larger context window, a press
team with its own instructions (NEXT-172). The Task is the unit nr-llm uses
for exactly that: it names the Configuration (provider, model, system prompt)
and adds a prompt template.

Options considered:

-   **A TCA table** mapping ``be_groups`` to Tasks, edited as records. It
    gives relations with integrity and a list view, at the cost of a table,
    TCA, a place in the page tree (or root-level records) and permissions for
    who may edit it — for a handful of rows an administrator sets once.
-   **A field on ``be_groups``** holding the Task. Natural to edit, but it
    extends a core table from an extension that is a proof of concept, and
    the precedence between several groups of one user becomes hidden in
    record order.
-   **An extension configuration string** of ``groupUid:taskUid`` pairs, next
    to ``llmTaskUid`` and ``allowedGroups`` (ADR-009), which are configured the
    same way.

Decision
========

Use an extension configuration setting ``groupTaskMapping``: comma-separated
``groupUid:taskUid`` pairs. The first pair, in the order of the setting,
whose group the user belongs to decides; users in none of them get
``llmTaskUid``. A malformed pair is skipped rather than invalidating the
whole setting.

``ExtensionConfiguration::getLlmTaskUid()`` answers for the current backend
user. Every caller — the status endpoint, the toolbar item, the turn in the
worker — already runs as the owner of the conversation (the worker
initialises it), so the one method gives the same answer everywhere, and no
caller had to change.

Membership is the *effective* one, ``userGroupsUID``, which includes
subgroups. ``allowedGroups`` (ADR-009) compares the directly assigned
``usergroup`` only; the mapping deliberately does not copy that, because a
Task per group is a group *setting*, and group settings in TYPO3 are
inherited by subgroups. ADR-009 is not changed here.

Consequences
============

-   One field in Admin Tools > Extension Configuration; no table, no records,
    no permissions to manage.
-   Precedence is explicit: the order of the pairs.
-   The Task decides the model and the prompts, not the permissions. What the
    assistant may do stays with nr-llm's per-user tool policy and the
    Configuration's own group restriction, so a mapping cannot widen a user's
    rights. A mapping to a Task whose Configuration the user may not use fails
    the turn the way a misconfigured ``llmTaskUid`` does.
-   Referential integrity is not checked: a deleted Task or group leaves a
    pair that matches nothing or fails the turn with "Task not found". With a
    TCA table this would be a dangling relation just the same.
-   If the mapping grows beyond a few pairs, or needs per-site variants, a TCA
    table is the next step; the resolution stays in ``getLlmTaskUid()``.
