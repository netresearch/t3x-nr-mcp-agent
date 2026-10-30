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
tree), the skill's title, the point of a tour, when it was created, when its
last message was written (``createdAt`` of the last message, the row's
``tstamp`` when there is none), and the status with its tone and label.

The title is decided at display time
------------------------------------

Stored rows keep their titles; no migration rewrites them.

-   **A guided tour whose title is automatic** is shown as
    "<skill> · <page>" with the page's current title (``ConversationTitle``).
    Automatic means: empty, the start message in any shipped language, or
    "<skill> · …" as a new tour now stores it. The page is left out when the
    user may not see it or it is deleted.
-   **Any other title** is shown with its whitespace collapsed and, when it is
    longer than 60 characters, shortened to its first sentence (from 20
    characters on) or cut at a word boundary with "…". The full text is the
    tooltip. This covers a chat's first message, "Alt-Text: <file>" and a
    title the editor gave.
-   **A rename sets no marker.** ``renameConversation()`` writes the title
    only. The rule above therefore treats every title the extension did not
    give as the editor's, which keeps a renamed tour's title. A new tour is
    stored as "<skill> · <page>", so its title is meaningful in the database
    too and is still recognised as automatic.

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
    status in words and the time are in the tooltip. The "more" list searches
    the title, the full title and the context.
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
-   A new tour stored in one backend language and listed in another keeps
    its stored title when the skill's title differs between the languages; the
    title is still "<skill> · <page>", only not re-derived.
-   Renaming a chat to exactly "<skill> · …" of its own tour makes the title
    automatic again. That is the one rename the rule cannot tell from the
    extension's own title.
-   The presenter reads each page, skill and label once per list. The list
    endpoint reads the context columns of ``tx_nrmcpagent_conversation``
    (``view_context``, ``skill_uid``, ``guided_state``, ``alt_text_hit``) and
    one row of ``tx_nrmcpagent_message`` per conversation for the time.
