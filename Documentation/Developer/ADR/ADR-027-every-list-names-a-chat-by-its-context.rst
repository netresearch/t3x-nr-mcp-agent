.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

.. _adr-027:

=================================================
ADR-027: Every List Names a Chat by Its Context
=================================================

**Status:** Accepted

**Date:** 2026-10-30

Context
=======

Editors found the lists of conversations unusable. On the demo, 49
conversations had 25 distinct titles, and 20 were called "Diese Seite
prüfen":

-   **Every guided tour had the same title.** ``createConversation()``
    starts a tour about a page with the fixed message ``guided.checkPage``
    (:ref:`adr-023`), and a conversation takes its first user message as its
    title.
-   **Only one list had context.** The dashboard widget "Recent chats" showed
    the tour's skill, its page, its point and the time, but in a narrow column
    and for five chats. The chat module's list, the panel's tabs, its "more"
    list, its sidebar and its minimized bar showed the title and the status,
    because the list endpoint sent nothing else.
-   **Empty chats were listed** as "New chat" (five on the demo).
-   **The status colours said the wrong thing.** A ready conversation was
    green, as if it had succeeded; a conversation waiting for the editor's
    answer was blue, like one that is still running.

Decision
========

One display model, built on the server
--------------------------------------

``ConversationListPresenter`` turns a conversation into what a list shows
(``ConversationListItem``). The list endpoint and the dashboard widget use it;
the chat module and the panel render its fields with one shared row
(``conversation-list.js``). Per conversation: the title, the full title for
the tooltip, the context, the page (only one the user may see in the page
tree, titled in the conversation's language where it has a translation the
user may edit), the skill's title, the point of a tour, when it was created,
when its last message was written, and the status with its tone and label.

When the last message was written is kept on the conversation
(``last_message_at``): the repository writes it with every save of the
messages, in the same transaction, from the last message's ``createdAt``. A
list therefore never reads a transcript. A row saved before the column
existed is read from its last message row until the upgrade wizard
``nrMcpAgent_lastMessageTime`` has filled it; a wizard rather than a lazy
backfill, because a list request should not write, and a conversation nobody
opens again would otherwise never be filled. The server sends the time as
ISO 8601; the client words it ("Heute, 10:42") in the reader's time zone and
anew at every render, so a list left open past midnight says "Gestern"; a
time slightly ahead of the reader's clock counts as now. The queries of the
lists already order by it (``CASE WHEN last_message_at > 0 THEN
last_message_at ELSE tstamp END``, portable across SQLite, MySQL/MariaDB and
PostgreSQL); the wizard walks the table once, batch after batch from the last
uid of the one before.

The title is decided at display time
------------------------------------

Stored rows keep their titles; no migration rewrites them. Where a title
comes from is kept in ``title_source``: ``manual`` once the editor renamed the
conversation (``renameConversation()``), ``tour`` for the title a new guided
tour is stored with, empty otherwise and for every row from before the column.
Neither is part of the full-row write (``Conversation::toRow()``), as the
instructions are not: the worker's final save carries the title it loaded,
and a rename during a running turn would be lost. ``add()`` writes them with
a new row; a save writes the title it carries only ``WHERE title_source <>
'manual'``. A rename is cut to the 255 characters of the ``title`` column
(``ConversationTitle::fit()``), never inside a character made of several
code points; the rename field takes no more (``maxlength``).

-   **A title the editor gave** (``manual``) is never replaced.
-   **A guided tour whose title is automatic** is shown as
    "<skill> · <page>" with the page's current title (``ConversationTitle``).
    Automatic means: marked ``tour``, or, for a row without a mark, exactly
    one of the titles the extension gives — empty, the tour's start message in
    the user's language, a shipped one or an installed backend language, the
    skill's title, or "<skill> · <page>" with the page's current title. Never
    a prefix: the rename field starts with the derived title, and
    "<skill> · <page> (Kunde X)" is the editor's. The page is left out when
    the user may not see it or it is deleted.
-   **Any other title** is shown with its whitespace collapsed and, when it is
    longer than 60 characters, shortened to its first sentence (from 20
    characters on) or cut at a word boundary with "…". The full text is the
    tooltip. Lengths are counted in grapheme clusters, so a cut never splits
    an emoji or an accented letter. This covers a chat's first message,
    "Alt-Text: <file>" and a title the editor gave.
-   **Two alike** — the same tour twice on the same page — get the time they
    were started after the title ("SEO optimieren · Über uns · 20. Okt.,
    08:00"), so a tab tells them apart without its tooltip.

The context names what the title does not
------------------------------------------

A tour shown as "<skill> · <page>" gets *Guided tour* and its point; a tour
the editor renamed gets the page, the skill and *Guided tour*. A plain skill
that needs a page (:ref:`adr-025`) gets its page and the skill. Every other
conversation names no page: it stores the page the browser showed with its
last message, which says where the editor was, not what the chat is about.
That rule comes from the widget, unchanged.

Lists
-----

-   Every list is ordered by the last message, newest first, pinned
    conversations first where pinning exists. ``tstamp`` alone moved with a
    rename or a pin.
-   A conversation without a message is not listed, except the open one: the
    endpoint still sends it, because the chat opens a conversation it has just
    created from that list.
-   The panel's tabs show the status icon and the title; the context, the
    status in words and the time are in the tooltip, and the context and the
    time are each tab's description (``aria-describedby``, pointing to a
    ``hidden`` element, so browse mode does not read it twice) for keyboard,
    touch and screen readers. The "more" list searches the title, the full title and
    the context.
-   The dashboard widget is full width and lists ten conversations.

Status
------

Ready is neutral, waiting for the editor's answer or approval is a warning
with an icon, running is information, failed is an error. Every list says the
status in words; the colour only repeats it.

Consequences
============

-   Existing tours read "<skill> · <page>" in every list without a migration;
    they follow a renamed page.
-   A page the user may not see is no longer a reason for the widget to leave
    a tour out: the chat module listed it anyway. It is listed without the
    page.
-   The table gains ``title_source`` and ``last_message_at``; run the
    database analyzer, then the wizard ``nrMcpAgent_lastMessageTime``.
-   A tour renamed before ``title_source`` existed to exactly a title the
    extension gives is treated as automatic. Every rename since is marked.
-   The pages of a list are read with one query per language of the
    conversations and one for their translations, with the same checks as
    ``BackendUtility::readPageAccess()`` — the "show page" clause, the web
    mounts, the workspace overlay — but without the record path no list shows.
    Translations are read as ``BackendUtility::getRecordLocalization()`` reads
    them: live rows and new ones of the user's workspace, no version rows,
    nothing of another workspace, then overlaid.
    The mount check stays per page; core caches the rootlines it reads.
-   The list endpoint reads the context columns of
    ``tx_nrmcpagent_conversation`` (``view_context``, ``skill_uid``,
    ``guided_state``, ``alt_text_hit``, ``title_source``, ``last_message_at``)
    and no message rows, except for rows the wizard has not filled yet.
