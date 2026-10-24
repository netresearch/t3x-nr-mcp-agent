.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

.. _adr-024:

==================================================
ADR-024: The Chat as a Column Beside the Page Tree
==================================================

**Status:** Accepted

**Date:** 2026-10-24

Context
=======

The floating panel (:ref:`adr-011`) always covers part of the module it is
used with. A guided process (:ref:`adr-023`) works on the page the page module
shows, and the editor reads the chat and the page side by side. The design
places the chat as a column between the page tree and the module, with a
handle to change its width, and a small bar in the corner when it is
minimized.

TYPO3 offers no API for a column in the backend frame, in 13.4 or 14.3.
Alternatives considered:

-   **A panel below the page tree**: the navigation container shows exactly
    one component, and only modules with a tree have one. The chat would
    disappear in the Dashboard, when the tree is collapsed, and on narrow
    screens.
-   **Padding on the scaffold for a drawer on the right**: shrinks
    ``typo3-backend-content-navigation`` in 14.3, which turns the page tree
    into a flyout below 750 px.
-   **The core context panel (14.2)**: a modal dialog; it blocks the page
    tree, which the chat has to follow.
-   **A sibling before the module router in the content area**: the content
    area outlives module switches and holds the router in both versions.

Decision
========

The ``<ai-chat-panel>`` gets a second layout, *column*. Docked, the element is
inserted before ``typo3-backend-module-router`` in the content area: the
``content`` slot of ``typo3-backend-content-navigation[identifier="backend"]``
in 14.3, ``.t3js-scaffold-content-module`` in 13.4. Both are horizontal flex
rows. The area is found by its shape (``chat-column.js``), not by the TYPO3
version, and only where the router is its direct child: the workspace-denied
and no-module-access pages render an infobox there, and the chat stays
floating.

-   While the column is open, the area carries ``data-nr-chat-column="open"``,
    and a constructed stylesheet on the top document gives the router
    ``flex: 1 1 0; min-width: 0``. Without it the router's
    ``:host { width: 100%; flex: 1 0 auto }`` overflows the area.
-   The column has ``contain: size``: in 13.4 the content area is a grid cell
    that grows with its content, so a long transcript made the column as tall
    as the transcript instead of scrolling it.
-   The page tree's component is not resized, so 14.3's flyout breakpoint is
    not reached by docking the chat.
-   Width: at least 320 px, at most half the content area (core's page tree
    uses the same half). A content area narrower than twice the minimum
    (640 px) gets no column: the panel stays where it is in the DOM but is
    drawn floating (attribute ``narrow``), the module keeps the whole area,
    and the stored layout is not changed, so the column returns when the
    area is wide enough again. The setting follows the user to smaller
    devices, which is why this is decided from the area's width, not stored. The splitter follows the WAI-ARIA Window Splitter pattern: a
    focusable ``role="separator"`` with its width as ``aria-valuenow``, arrow
    keys in steps of 16 px, :kbd:`Home` and :kbd:`End` to the limits.
-   Layout and width are stored in the backend user's settings with
    ``@typo3/backend/storage/persistent.js`` (``nrMcpAgent.chatPanel.layout``,
    ``nrMcpAgent.chatPanel.columnWidth``), like core stores the tree width.
    The width is written after a drag ends and 400 ms after the last key press.
    The floating panel's position, height and state stay in ``localStorage``:
    they describe one browser window.
-   Minimized, the column is the existing *collapsed* state, drawn as a bar in
    the bottom right corner. A new state would have repeated what *collapsed*
    already means: chat kept, header only, reached by :kbd:`Escape`. There is
    no *maximized* state in the column.
-   The page the chat is about follows the page tree: ``ModuleStateStorage``
    (``web``) is read where the module URL carries a page, because a tree
    click updates it before the module has loaded the page. The panel listens
    on the top document to ``typo3:module-state-storage:update:web``,
    ``typo3:module-state-storage:update-with-tree-identifier:web`` and
    ``typo3-module-loaded``. A module that is not about a page still sends
    none, as before.
-   The element is placed once, when the toolbar item creates it, and moved
    only when the user switches the layout: every move reconnects the element
    and re-initialises the chat controller. Switching the layout and the
    pop-out are disabled while a message is being sent, because the
    reconnect aborts the request.

Consequences
============

-   The column depends on undocumented core markup in two shapes. A core
    change that removes both makes the chat fall back to the floating panel;
    the Jest tests pin both shapes, the E2E tests check the geometry.
-   CI's E2E job runs against 13.4 only; the 14.3 shape is covered by the
    Jest tests and by local E2E runs.
-   The module menu is not collapsed automatically when the column opens.
