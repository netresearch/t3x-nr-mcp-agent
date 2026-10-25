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
away with this extension. It writes through DataHandler as well. It asks for
approval (below, *Slice 2*).

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
    on ``typo3/fluid-styled-content``.

No further mechanism is added; the remaining setups are documented in the
installation chapter and covered by the rendering test:

-   fluid_styled_content loaded with ``@import`` in a ``sys_template``
    record: the static-template key is read only for *Include static*
    entries, so the site imports the set's ``setup.typoscript`` after
    fluid_styled_content's.
-   The static template followed by another content rendering that
    redefines ``lib.contentElement`` without ``>`` (bootstrap_package 15
    through its static template): key 5 stays and replaces that
    rendering's partial, so the site removes it with
    ``lib.contentElement.partialRootPaths.5 >``.
-   bootstrap_package or any other own content rendering never takes the
    set or the import; it adds the condition to its own image partial.

A site package with its own image partial at a higher key keeps it; the
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
form and in no output. Fields a plugin adds through ``subtypes_addlist``
are not read; a reference in such a field is not checked.

Hidden and timed content counts and carries why it is not visible: a
parent page that passes its visibility on to its subpages
(``extendToSubpages``) and is hidden, not started yet or expired; the
page; its translation in the content's language; the element or record; a
start time in the future, an end time passed; and, beyond the
specification, a hidden reference (*Bild ausgeblendet*). The level highest
up wins, a parent page before the page. Content in a language for which
the page has no translation gets no reason, although the frontend may not
show it.

**Permissions.** A hit is kept only when the user may change the
reference, as nr-llm's writer asks it before its card (nr-llm ADR-230):
``readPageAccess()`` with *edit content*, and for a page property *edit
page* as well (records: their folder), which includes the web mounts; no
``editlock`` on the page or a record on the way (admins excepted);
``tables_modify`` for every table on the way and for
``sys_file_reference``; the values of each record's ``authMode`` fields,
the content type above all (``explicit_allowdeny``); the image field, the
inline fields on the way and ``sys_file_reference.alternative`` granted
where they are exclude fields; the language of the reference and of each
record. A page property needs both page rights because the form edits it
as a page property, while the DataHandler, writing the reference row
alone, asks *edit content* on its page and *edit page* only with ``pages``
in the same datamap. The same filter feeds the list, its tabs and the
dashboard number.

The DataHandler does not repeat these checks for the records the
reference hangs on: writing ``sys_file_reference`` alone, it checks that
row, its page and its table, field and language rights, not the record it
belongs to, and for an inline child not the outer content element either.
So ``ReferenceWriteAccess`` asks, before its card, the rights of the record
and of every record that owns it through an inline field, up to the
outermost one: an image on a carousel item is refused when its content
element is locked or of a type the user may not use. The list asks the
same of the same chain. nr-llm's ``set_file_reference_alternative_text``
(nr-llm ADR-230) asks the record's own rights but not those of its owners,
so such an image passes it; that gap is closed in nr-llm on its own. Not
enforced anywhere before the write: access checks extensions add to
DataHandler through hooks.

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

Slice 2
=======

**The decorative mark is a tool that asks for approval.**
``set_file_reference_decorative(reference, decorative)`` sets or clears
``tx_nrmcpagent_decorative`` on one reference through nr-llm's
``ToolDataHandler``, the DataHandler its own writers run (nr-llm ADR-206),
as the acting user, in the live workspace only, reads it back, and names
the reference as its write target, so nr-llm announces the write with
``AfterAiRecordWrittenEvent`` as it does for its own writers. nr-llm pauses
every call of a tool that declares a write for an approval card
(``ToolApprovalRule``, nr-llm ADR-134), and no declaration lifts that;
declaring the tool a read to save the editor a click would mislabel a
record write, which the guided-state tools' ``READ_ONLY`` does not do
(they write the chat's own bookkeeping only). Like nr-llm's writers it is
disabled until an administrator enables it, in the writers' group
``editing``, and usable by editors. Before the card it asks what nr-llm's
``set_file_reference_alternative_text`` asks (``ReferenceWriteAccess``),
with the grant for the mark instead of the alternative text, so no card is
offered that the DataHandler would then refuse. Its card names the image
use and, when the image belongs to a section (the header of its content
element, also through a carousel item) or to a record outside the pages,
carries the specification's warning (case 2): "Dieses Bild gehört zum
Abschnitt „…“ und braucht deshalb wahrscheinlich einen Alt-Text. Trotzdem
als dekorativ markieren?"

**The editor confirms the mark once, on that card.** The specification
saves on "Übernehmen" or "Als dekorativ markieren"; nr-llm saves a write
only on an approved card (nr-llm ADR-134), and that holds here too. The
chip "Als dekorativ markieren" therefore does not save anything and does
not count as a confirmation: it asks for the card, and approving the card
is the one confirmation, with the warning of case 2 on the card itself. No
further "really?" step comes before or after it.

**A conversation starts from the reference alone.**
``POST /ai-chat/conversations/create`` takes ``altTextReference``, the
reference's uid, and nothing else: a page or a skill sent along is
refused. The server reads everything about the image again: the hit has to
be one the list shows the user (``MissingAltTextScan::find()``, the list's
own filter) and one nr-llm's writer would accept (``ReferenceWriteAccess``
for ``alternative``). A reference that is not there and one that is not
the user's get one neutral 403, so a uid alone does not tell whether it
exists; a reference the user may edit that is no longer open gets 409
``done`` ("Treffer zwischenzeitlich erledigt"). A draft workspace gets 409
``workspace``: nr-llm's writer writes the live workspace only. A chat whose
runs may not call both tools, as nr-llm's own policy answers it
(``ToolCallPolicyInterface::decide()`` for the chat's configuration),
gets 409 ``tools`` and the names of the tools an administrator has to
enable. A user who may not approve their own changes in the chat
(:ref:`adr-021`) gets 409 ``approval``, and a chat whose configuration
requires a second approver 409 ``secondApprover``: the proposal is saved by
approving its card, which neither could do. The conversation keeps the reference uid only (column
``alt_text_hit``), and is about the image's page in the reference's
language when the image lies on a page; a record's image binds no page.

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
-   Slice 2 needs nr-llm 0.41.6 (``set_file_reference_alternative_text``)
    and adds the column ``tx_nrmcpagent_conversation.alt_text_hit``: run the
    database analyzer after upgrading. An administrator enables both
    writing tools, nr-llm's and ``set_file_reference_decorative``, and gives
    editors the field ``sys_file_reference:tx_nrmcpagent_decorative``.
