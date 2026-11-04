.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

.. _adr-031:

=====================================================
ADR-031: The module is an overview, the chat a route
=====================================================

**Status:** Accepted

**Date:** 2026-11-03

**Amends:** :ref:`adr-011` and :ref:`adr-024` (where the chat is opened).

Context
=======

The module ``nr_mcp_agent_chat`` rendered the whole chat, the same
``<nr-chat-app>`` the panel from the top bar shows (:ref:`adr-011`) and the
column beside the page tree (:ref:`adr-024`). A user who opened the module saw
a second copy of the chat and nothing about whether it worked for them, how it
was configured, or how it had been used. The module sat under Administration,
while nr_llm's modules sit in the shared section *AI* (nr_llm ADR-183).

Links built from the route (dashboard widgets, the missing alternative texts,
``ChatStartUriBuilder``) are the fallback for a middle click and for a user
without the panel: the chat reads ``conversation``, ``skill``, ``pageUid``,
``languageUid`` and ``altTextReference`` from the URL.

Decision
========

-   The module's first page is an overview (``OverviewController``): what the
    chat does and where it appears, whether it works for the viewer, the
    extension configuration (administrators), usage in a chosen period, and
    links into the chat and into nr_llm.
-   The full-page chat is the sub-route ``nr_mcp_agent_chat.conversation``
    (path ``/conversation``), the shape of ``missingAltTexts``. Every link
    builder targets it. A request to the first page that carries one of the
    chat's parameters is redirected there, so a link built before this change
    still opens the chat.
-   Readiness asks the rules the chat applies, not a copy of them:
    ``ChatAccess`` (the toolbar item's access rule) and ``ChatTaskResolver``
    (Task → Configuration, active, usable by the actor), which ``ChatService``
    now calls too. A state the page cannot measure is shown as *not checked*.
    A worker busy with a turn leaves no row, so "no idle worker" is a warning,
    not a failure: turns then start a process of their own.
-   Usage comes from the conversation table and from nr_llm's public
    ``ExtensionUsageReaderInterface`` (nr_llm ADR-248) for the source
    extension ``nr_mcp_agent``. Administrators see the installation's figures,
    everyone else their own. No message content and no other user's
    conversation title is shown; failed conversations are counted per error
    code, never with their message.
-   The module moves to the section ``netresearch_ai``. Its identifier and
    path stay, so bookmarks keep working.

Consequences
============

-   ``maxConversationsPerUser`` is read nowhere; the overview does not list it,
    and the configuration documentation says so.
-   Agent runs, analytics and records stay in nr_llm's modules; the overview
    links to the ones the viewer may open.
