.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

.. _adr-026:

================================================
ADR-026: A Site-Wide Check for Missing Alt Texts
================================================

**Status:** Accepted

**Date:** 2026-10-25

Context
=======

The product specification "Website Check: Fehlende Alt-Texte" asks for a
list of every use of an image that has no alternative text, across pages
and records, reached from the dashboard. Each hit is fixed with the chat or
marked as decorative. It is a work list, not a guided tour.

:ref:`adr-023` did not adopt site-wide scan tiles and the widget *Website
prüfen*, because no scanner existed. This ADR adds one scanner, for alt
texts, and the widget for it. Bulk operations ("Website bearbeiten") stay
not adopted.

What exists: alt texts live on the image reference
(``sys_file_reference.alternative``) and, as the file's default, in the
file list (``sys_file_metadata.alternative``). TYPO3 has no field for a decorative
image. nr-llm's ``set_file_alternative_text`` writes ``sys_file_metadata``,
which the specification rules out: an alt text belongs to one use of the
image, not to the file.

Decision
========

**Slices.** The feature ships in three:

1.  The check, the "decorative image" field, the list *Fehlende Alt-Texte*
    and its two entries on the dashboard (this ADR's subject). No button in
    the list writes anything.
2.  The write tool, and *Optimieren*: the chat opens beside the list with
    the hit's context, proposes an alt text, saves it or marks the image
    decorative, and moves to the next hit.
3.  The privacy setting *Bildanalyse*: whether a scaled image is sent to
    the model, and folders that are never sent.

**Where the write tool lives.** Writing ``alternative`` on an existing
reference goes into nr-llm, next to ``set_file_alternative_text``: every
content write the chat makes is an nr-llm tool with its approval card
(:ref:`adr-004`, :ref:`adr-021`), through DataHandler, so permissions,
workspaces and the record history apply, and the tool is useful without
this extension. Setting ``tx_nrmcpagent_decorative`` is a tool of this
extension, registered with nr-llm's tool registry like the guided-state
tools (:ref:`adr-020`): nr-llm does not know the field, and the field goes
away with this extension. It writes through DataHandler as well. Whether it
asks for approval is slice 2's decision.

**The decorative field.** ``sys_file_reference.tx_nrmcpagent_decorative``
(the extension's prefix; the specification's ``tx_aiassistant_`` is an
example), a checkbox, default off, from ``ext_tables.sql`` and a TCA
override that puts it after *Alternative text* in ``imageoverlayPalette``.
Like *Alternative text* it is an exclude field: an editor sees it once
their group is granted it. No ``l10n_mode``, so each language version of a
reference has its own. It is not on ``sys_file_metadata``.

**Frontend output.** fluid_styled_content's image partial
(``Media/Rendering/Image.html`` in 13.4, ``Image.fluid.html`` in 14.3, the
same markup) gets an override that renders a decorative image with
``f:image … alt=""`` and every other image with the core's own line.
``f:media`` cannot be used for the decorative branch: it treats ``alt=""``
as missing and falls back to the file's alternative text; ``f:image``
keeps an explicit empty one. The partial path sits at key 5: above the
core's (0), below a site package's (10, ``styles.templates.partialRootPath``).

The path is never added to every site. A content rendering of its own
with its image partial below key 5 would lose that partial for every
image: bootstrap_package 16 sets its own path at 0 and, at 10, a setting
whose default names a folder it does not ship (``Partials/ContentElement/``;
its partials are in ``Partials/ContentElements/``), so key 5 would win and
its images would lose their ``<picture>`` and ``srcset`` output. The path
comes only together with fluid_styled_content:

-   Sites with site sets opt in with the set
    ``netresearch/nr-mcp-agent-decorative-images``, which depends on
    ``typo3/fluid-styled-content``. A ``defaultContentRendering`` addition
    would not reach them at all: the core adds it before the sets, and
    fluid_styled_content's TypoScript starts with ``lib.contentElement >``.
-   Sites with ``sys_template`` records get it right after
    fluid_styled_content's static template
    (``addTypoScript(…, 'fluidstyledcontent/Configuration/TypoScript/')``),
    and only when they include that template. bootstrap_package 16.0.1
    ships site sets only, no static template, and its sets do not depend
    on ``typo3/fluid-styled-content``. A site that includes fluid_styled_content
    and then another content rendering keeps key 5 and puts its own partial
    path at 10 or above.

A site package or extension with its own image partial keeps it; the
installation documentation gives the condition to add.

**Which fields count.** Every column of type ``file`` in the TCA, read at
runtime: a newly installed extension's image fields are checked without
configuration (the specification asked whether this is possible: it is;
13.4 migrates the older inline FAL fields to type ``file``). Of these, the
file must be an image (``sys_file.type``). Excluded by default, in
:confval:`altTextExcludedFields`: ``pages.og_image``,
``pages.twitter_image``, ``be_users.avatar`` and ``backend_layout.icon``
(no image with an alt text in the frontend), and ``pages.nav_icon``:
bootstrap_package renders it with its icon ViewHelper, which outputs no
alt text from the reference. Unlike the
specification's "pages = content elements and *Medien*", every other image
field of ``pages`` counts too, ``thumbnail`` among them: bootstrap_package
renders it through the image partial with the reference's alt text, in
thumbnail menus. Tables are excluded with
:confval:`altTextExcludedTables` (none by default).

**Which tab a hit belongs to.** A reference is followed to its record and,
through inline fields that name their owner (``foreign_field``), to that
record's owner, up to four levels. The outermost record decides: a page or
a content element puts the hit under *Seiten*, any other table under its
own tab, labelled with the table's TCA title. So a carousel item's image
belongs to its content element on the page, and a content element inside
a news record to that record. An excluded table also takes the records it
owns with it.

**When a hit is open.** The image's effective alt text is empty: the
reference's own text, or, where the reference has none (``NULL``), the
file list's text in the reference's language where the file has a metadata
translation, else in the default language. The file list's text is read in
its live version; a draft in a workspace does not decide outside it. A
reference whose text was overridden with an empty one is open even if the
file list has a text, because the frontend then renders it empty;
whitespace counts as empty. A reference for all languages
(``sys_language_uid`` -1) is checked against the default language's file
list text only; on a translated page the frontend shows that language's
metadata translation instead, so such a reference can be listed although
a translation has a text, or the other way round.

Not decorative; reference, records on the way and page not deleted. Only
references in a field the record's type shows: listed in its ``showitem``,
directly or in a palette, and not turned into another field type by
``columnsOverrides``. A reference left in a field after the record's type
changed (an image on a *Text* element that was *Text & Images*) is in no
form and in no output.

Hidden and timed content counts and carries why it is not visible: a
parent page that passes its visibility on to its subpages
(``extendToSubpages``) and is hidden, not started yet or expired; the
page; its translation in the content's language; the element or record; a
start time in the future, an end time passed; and, beyond the
specification, a hidden reference (*Bild ausgeblendet*). The level highest
up wins, a parent page before the page.

**Permissions.** A hit is kept only when the user may change the
reference, as DataHandler would allow it: ``readPageAccess()`` with *edit
page* for a page property and *edit content* otherwise (records: their
folder), which includes the web mounts; no ``editlock`` on the page or a
record on the way (admins excepted); ``tables_modify`` for every table on
the way and for ``sys_file_reference``; the image field, the inline fields
on the way and ``sys_file_reference.alternative`` granted where they are
exclude fields; the language of the reference and of each record. The
same filter feeds the list, its tabs and the dashboard number. Not
enforced in this slice: per-value access to the content type
(``explicit_allowdeny``) and access checks extensions add to DataHandler
through hooks; DataHandler still applies both when slice 2 writes.

**Workspaces.** In a workspace each row is overlaid with its workspace
version: an alt text or mark set there, a file list text set there, and a
record deleted there, count; references new in the workspace are found.
Records of tables that are not workspace-aware are left out. Not covered:
an alt text that is set live and emptied only in the workspace, on the
reference or in the file list, is not found, because the query decides on
the live rows; a metadata translation that exists only in the workspace is
not read. The tests load EXT:workspaces for this part.

**Live, no cache.** The list and the dashboard number run the query and
the filter on every request, and the list pages in memory. The
specification's nightly cache for large sites is not built; it is added
when a site needs it. The query over the references has no limit and the
number is not counted in SQL: whether a hit counts depends on the page
access over the rootline, the field and language rights and the record
type, which are decided per reference in PHP. A limit would make the
dashboard number wrong. Its cost grows with the number of open references,
not with the number of pages.

**The list and its entries.** A second route of the existing module
``nr_mcp_agent_chat``, reached from the widget *Website prüfen* (always
listing *Fehlende Alt-Texte*) and a tile in *Für deine Website empfohlen*
(shown only with open hits). Both are behind the same gate as the other
assistant widgets, ``ChatToolbarItem::checkAccess()`` (:ref:`adr-009`):
every action of the feature leads into the chat. *Optimieren* is left out
until slice 2 rather than shown disabled: a disabled button cannot be
focused, so its explanation would not reach keyboard users.

Consequences
============

-   An editor sees every image without an alt text they may fix, in one
    place, without opening pages.
-   A decorative image is output with ``alt=""`` in fluid_styled_content:
    on ``sys_template`` sites that include its static template right away,
    on site set sites once the extension's set is added. Sites with their
    own image partial need the documented condition until they add it.
-   The check costs one query over the references, one query per table and
    level for the records, one per 500 pages for the pages and their
    translations, and per page one page access check (a query of its own
    plus the rootline from the core's runtime cache), which the scanner
    keeps for the rest of the scan; it scales with the number of open hits,
    not with the number of pages.
-   The tests need ``typo3/cms-fluid-styled-content`` and
    ``typo3/cms-workspaces`` as development dependencies, to prove the
    frontend output and the workspace overlay.
