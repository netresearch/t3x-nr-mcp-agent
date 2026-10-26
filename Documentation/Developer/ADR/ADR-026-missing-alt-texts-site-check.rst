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
owns with it. An inline field from a table to itself is not followed: it
declares a container of records of its own kind (a grid extension's
content element holding content elements, for example), whose children the
page module edits on their own and the DataHandler checks alone, so a
locked container does not hide or refuse the images of its children.

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
record. Before these, every table on the way has to be writable at all,
for admins too: not on nr-llm's denylist of credential and audit tables,
not ``readOnly``, and not ``adminOnly`` for an editor; the list and the
writers ask this in one place (``TableWriteEligibility``). A page property
needs both page rights because the form edits it
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
(nr-llm ADR-230) asked the record's own rights but not those of its owners
up to 0.41.6; nr-llm 0.41.7 asks every inline owner in another table too,
and this extension requires 0.41.7 from 0.22.0 on. Not
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
every action of the feature leads into the chat. Where *Optimieren* cannot
work (slice 2) it is not ``disabled``, which cannot be focused, so its
explanation would not reach keyboard users: the button stays focusable with
``aria-disabled`` and ``aria-describedby`` on a visible note that says why.

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
says: „Gespeichert wird erst nach „Übernehmen“ bzw. „Als dekorativ
markieren“.“ nr-llm saves a write only on an approved card (nr-llm
ADR-134), and that holds here too. The
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

**The chat opens beside the list, on the image.** *Optimieren* opens the
chat as the column (:ref:`adr-024`) for this opening only, folds the module
menu through its own toggle and unfolds it on closing when it folded it,
and starts the conversation, or moves the open one to the row's image. The
list follows the chat through the panel's event ``nr-mcp-agent:alt-text``
(``active``, ``done``, ``gone``, ``closed``): the row the chat is on is
marked (``aria-current``) and its button reads *Im Chat*, a fixed image
leaves the list with its counts and a live region, and closing gives the
keyboard focus back to the row. Without the panel the button opens the chat
module with ``altTextReference``.

**The first message is the server's.** The image card (image, page or
record, element or field, language, "Dieses Bild hat keinen Alt-Text") is a
notice with the card's lines as data, written when the conversation takes
the image up; the model's turn follows. Every line of the chat's own
(card, divider *Treffer gewechselt*, the chip *Überspringen*, the
confirmations) is a notice the reader sees in their language and the model
never reads; the chips *Als dekorativ markieren* and *Erneut anbieten* are
requests the model reads. A label the chat fills with values uses
positional placeholders only (``%1$s``): TYPO3's ``lll()`` fills a plain
``%s`` itself, with "undefined" where no value is passed.
What the model reads of the image is built anew on every turn from the
database: the latest card becomes the image's context (file, language,
page or record, element text, neighbouring elements, the record's text
fields), an earlier card a line saying the editor moved on.

**The flow is a section of the system prompt, not a skill.** An extension
cannot ship a skill to nr-llm (nr-llm ADR-163), so the instructions —
propose one text through ``set_file_reference_alternative_text``, propose
the decorative mark through ``set_file_reference_decorative`` only for an
image that adds nothing, never touch another image — are added to the
system prompt of a conversation about an image.

**The answers.** On a proposal the card offers *Übernehmen* (approves the
card; not offered while the approval is blocked), *Andere Variante* (denies
it with the reason ``variant``, which nr-llm passes to the model), *Als
dekorativ markieren* and *Überspringen*. *Überspringen* is the chat's own
action with a fixed text and no model turn: the waiting run is withdrawn,
nothing is saved, and the chat says that the hit stays open. *Als
dekorativ markieren* is offered only where the user may set the mark (the
rights and the exclude-field grant for ``tx_nrmcpagent_decorative``, as the
tool asks them); it withdraws the waiting run and starts a turn whose
request the model answers with ``set_file_reference_decorative``. The mark's
card — the model's own proposal (case 1) or the one the chip asked for —
shows its lines, with the warning of case 2 where it applies, and offers
*Als dekorativ markieren* (approves it, the one confirmation) and
*Alt-Text erstellen*. The specification's buttons for case 2, „Ja, als
dekorativ markieren“ and „Doch Alt-Text verwenden“, belong to a step of
their own, which the single confirmation leaves out. The model's own mark
reaches a user who may not set it as a card without the approve button,
which says so in the reader's language in place of the tool's refusal, and
the image's context tells the model that the mark is not available. Only
the system prompt binds the model to the conversation's image; a call of
either tool on another reference is therefore not approved in the chat: the
card says that the proposal concerns a different image, and an approval is
refused. After an approved
write the chat confirms it itself, "Alt-Text gespeichert." or "Als
dekorativ markiert.", in place of the model's answer, when the run recorded
the write. When the write of an approved text did not land, the model's
answer says why and the proposal stays, as the specification has it („der
Vorschlag bleibt erhalten“): *Erneut anbieten* asks the model to propose
the same text again, as a new card; the server takes the text from the
conversation, never from the browser.

**Moving on.** Another row's *Optimieren* and *Nächsten Treffer
optimieren* move the conversation to another image: a divider naming it,
its card and a new turn, the waiting proposal withdrawn. The next hit is
the open hit after the conversation's image in list order across the tabs
(pages first, then the record types by their label), after the last the
first again; the conversation keeps the image's place in the list, so
after a fixed image the next one is the one that followed it. The server
refuses every move while a turn runs; the panel keeps an *Optimieren* that
comes meanwhile and takes it up when the turn ends, unless the chat is
closed in between. Whether the chat folded
the module menu is kept in the user's settings, so closing the chat after a
reload unfolds it as well.

**The image goes to the model as a scaled copy, where it may see it.** A
provider that supports vision gets a PNG copy of at most 1024 pixels with
the reference's crop; an image that does not come out as a raster image
(an SVG the image processor does not convert) is not sent. The card's note
is decided with the copy: wherever no copy goes to the model — no vision, a
file that cannot be read or converted — the card says that the proposal
rests on the page, the element and the file name.
Whether an image may be sent at all is ``AltTextImagePolicyInterface``,
where slice 3's setting plugs in (below).

Slice 3
=======

**The setting is extension configuration.** The specification places
*Bildanalyse* in "AI Assistant / Wissen & Einstellungen, Bereich
Datenschutz", changeable by administrators only. The module has no such
area, and the extension keeps every administrator setting in its extension
configuration; a second store for one setting is not added. So it is
:confval:`imageAnalysis` (``analyse``, *Bilder analysieren*, or
``contextOnly``, *Nur Kontext verwenden*) and
:confval:`imageAnalysisExcludedFolders`, in the category *privacy*. The
extension configuration is changed by administrators with system
maintainer rights, fewer than the specification's administrators. The
labels follow the extension configuration's convention, English, and carry
the specification's explanation (data processing agreement, people on
images) in short form; the specification's status line in the navigation
("Bilder werden analysiert" / "Nur Kontext, keine Bilder") has no place
without the area and is not built. Both come with the area, if it is built.

**Default: images are analysed.** The specification marks *Bilder
analysieren* as the default, which is also how slices 1 and 2 behave. A
value other than the two (only possible when written by hand) sends no
image: a typo must not send images by mistake.

**One policy decides, for every file this extension sends.**
``ImageAnalysisPolicy`` replaces ``SendEveryImage`` behind
``AltTextImagePolicyInterface``, and the chat depends on the interface too,
so rebinding it changes every path. The specification says the setting
applies "für alle Bilder und für alle Funktionen des AI Assistant, die
Bilder verarbeiten", so the same policy also decides a file attached in the
chat. With *Nur Kontext* the chat offers no image format and no format the
provider reads natively as a document, and the upload endpoint, which
checks against the same list, refuses them; what text extraction reads
stays. An attachment is asked about whatever its type, because a document
the provider reads natively (a PDF for Claude and Gemini in nr-llm 0.41.7)
carries its photos along: a refused image is replaced in the model's turn
by a line saying that it is not sent and why, and a refused document goes
as its extracted text only (the extractors return text), or as that line
where no extractor reads it. The refusal carries the note code and the
folder it names (``AltTextImageRefusal``); the interface's return type
changed from a bare code for that, and it gained ``refusalForFile()`` and
``analysesImages()``. nr-llm's own image features (its vision service and
modules) are not this extension's and are not covered; none of nr-llm's
tools the chat calls sends an image.

**The setting comes before the provider.** ``AltTextImage`` asks the
policy before it asks whether the provider can see images: where the
settings keep an image back, the card says so also on a provider without
vision, because that reason holds whatever the provider. The card's note
and what the model gets are still decided in one call (``prepared()``):
a refused image is not even processed.

**Excluded folders are bound to their storage.** An entry is
``fileadmin/team/`` (the base path of a local storage in the web root and
the folder) or ``1:/team/`` (the storage uid and the folder); the second
form reaches storages outside the web root. A folder includes its
subfolders and is compared with a slash at either end, so ``/team/``
does not take in ``/teamwork/``, and ``/team/`` in another storage is not
excluded. Comparison follows the storage's case sensitivity. An entry is
resolved storage first: its uid, or its base path at the start of the
entry in any case (``Fileadmin/team/`` is ``fileadmin/``'s ``/team/``); then
the rest as a folder of that storage, with ``.`` and ``..`` resolved. Taken
the other way round, ``fileadmin/../user_upload/x/`` would name another
storage's folder; it climbs above the storage's root and is refused.
Folders are compared by their identifiers without asking the storage, so a
folder that does not exist yet costs nothing. An entry that names no
folder of a storage (``portraits``, ``user_upload/team/`` without such a
storage, one that climbs above the root) excludes nothing and is logged as
a warning, once per configured value. The first configured folder that
holds the image is the one the card names, written as an editor knows it
(``fileadmin/team/``, or ``2:/team/`` for a storage outside the web root).

**The card's lines are the specification's.** *Nur Kontext*: "Die
Bildanalyse ist in den Einstellungen abgeschaltet." An excluded folder:
"Bilder aus dem Ordner {Ordner} werden nicht an die KI übertragen." Both go
on, like the other notes: "Der Vorschlag beruht nur auf Seite,
Content-Element und Dateiname. Prüfe, ob er zum Bild passt." The folder is
kept on the card as data (``imageNoteFolder``) and filled into a
positional placeholder. The preview in the chat and the list stays; it is
a backend image and never goes to the provider.

**A turn runs with the setting of the request that started it.** TYPO3
loads its configuration into ``$GLOBALS['TYPO3_CONF_VARS']`` once per
process (``Bootstrap::populateLocalConfiguration()``), and the core's
``ExtensionConfiguration::get()`` reads it from there. A worker
(``processingStrategy = worker``) is one long process, so reading the
setting anew inside it still yields the value it started with. Reloading
the configuration there is no way out: ``exportConfiguration()`` resets what
every ``ext_localconf.php`` added to ``TYPO3_CONF_VARS``, and reading
``settings.php`` alone drops the values ``additional.php`` sets (the E2E
setup sets ``imageAnalysisExcludedFolders`` there). Every turn, however,
starts in a backend request that reads the configuration anew and hands the
turn over by writing the status *processing*. That same write stamps the
two values on the conversation (``image_analysis``,
``ImageAnalysisTurnSettings``), so a worker cannot take the turn before
its stamp is there; the turn overlays them on the process's configuration
and puts the process's own back afterwards. Values, not a decision, are
stamped, per turn rather than per message or attachment: attachments are
read again on every turn, so a stamp from the time a file was attached
would go on sending it after a switch to *Nur Kontext*. The policy reads
the setting on every decision, and resolves the folders once per value. A
turn queued before the stamp existed runs with the process's values. The
card also binds the turn: where it says the setting keeps the image back,
the image is not sent.

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
-   Slice 3 sends images as before until an administrator changes
    :confval:`imageAnalysis`; with *Context only* the chat no longer
    accepts image attachments, and a PDF goes as its extracted text. It
    adds the column ``tx_nrmcpagent_conversation.image_analysis``: run the
    database analyzer after upgrading.
-   Slice 2 needs nr-llm 0.41.7 (``set_file_reference_alternative_text``)
    and adds the column ``tx_nrmcpagent_conversation.alt_text_hit``: run the
    database analyzer after upgrading. An administrator enables both
    writing tools, nr-llm's and ``set_file_reference_decorative``, and gives
    editors the field ``sys_file_reference:tx_nrmcpagent_decorative``.
