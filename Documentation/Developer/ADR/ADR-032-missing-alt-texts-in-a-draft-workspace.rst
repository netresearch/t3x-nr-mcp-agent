.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

.. _adr-032:

=========================================================
ADR-032: Missing alt texts are fixed in a draft workspace
=========================================================

**Status:** Accepted

**Date:** 2026-11-05

**Amends:** :ref:`adr-026` (the refusal of a draft workspace, the decorative
mark written in live only).

Context
=======

"Optimieren" in the list of missing alt texts (:ref:`adr-026`) refused an
editor working in a draft workspace: the start, the switch to another image,
"Nächsten Treffer optimieren", "Erneut anbieten" and "Als dekorativ
markieren" answered 409 ``workspace``, the list showed no button, and
``set_file_reference_decorative`` refused every workspace but live. The
reason was nr-llm: its writers wrote the live workspace only.

That has changed. A conversation records the workspace its turn was handed
over in, the worker sets the user up in it, and the run's actor carries it
(nr-llm ADR-260, this extension since 0.33.0). nr-llm's
``set_file_reference_alternative_text`` writes a version of the reference in
the workspace the run recorded (nr-llm #1294), and refuses a draft workspace
the run did not record. The list itself already read the editor's workspace
(:ref:`adr-026`, *Workspaces*).

What core does with the one write involved, a datamap of
``sys_file_reference`` by the live uid, as a backend user in a draft
workspace (``DataHandler::process_datamap()``, TYPO3 v14.3.10 lines 921-945,
v13.4.37 lines 909-933): a live record of a workspace-aware table that the
workspace may not edit live (``workspaceCannotEditRecord()``, v14.3.10 lines
9564-9584, v13.4.37 lines 9430-9471) gets its existing version in the
user's workspace written, or a version created by a ``version``/``new``
command and then written. A row of another workspace is refused (v14.3.10:
"Workspace ID of record does not match current user workspace", v13.4.37:
"Workspace ID of record didn't match current workspace").
``sys_file_reference`` is workspace-aware (``versioningWS``) in both
versions.

Decision
========

**A draft workspace is no refusal.** ``AltTextGate::refusal()`` answers
``workspace`` only for a user who may work in no workspace (core sets a
negative workspace): there a turn records none, and nothing could be saved.
The label ``error.altTextWorkspace`` says that. In a draft workspace it
answers ``liveOnly`` where the installation's TCA lets core keep no draft of
``sys_file_reference`` (see below): both writers refuse there, and the label
``error.altTextLiveOnly`` says so. Every other step of the flow works in the
workspace the editor works in when they take it, and the turn records it.

**The image is decided on as the workspace shows it.**
``ReferenceWriteAccess`` reads the reference and its record as nr-llm's
writer reads them (nr-llm ADR-260): in a draft workspace the live row
overlaid with its version there, or a row new in that workspace; a version
row named by its own uid and another workspace's row are not there. The
pages and the inline owners are read live, as nr-llm reads them, so the
gate lets through what the writer accepts and nothing else
(``ImageReferenceRepository::findAsTheUserSees()``).

**The decorative mark writes into the workspace the run recorded.**
``DecorativeImageWriter`` writes through nr-llm's ``ToolDataHandler`` as
before. In a draft workspace it writes only where the run's actor recorded
that workspace (``ToolExecutionContext::$actor->workspaceId``, nr-llm
``@api``), and only where core keeps a draft of ``sys_file_reference``
(``versioningWS`` set, ``versioningWS_alwaysAllowLiveEdit`` not, which
TYPO3 13.4 also honours for a versioned table). The read-back reads the
version through the live uid. The tool's answer ends with "Stored as a draft
in the workspace …", so the model does not report a draft as live.

**The card names the workspace.** ``set_file_reference_decorative`` adds
"Workspace: “…” — saved there as a draft, live stays unchanged until the
workspace is published" (the acting user's workspace, the one the run writes
into), shows the record as the workspace shows it, and shows only the
refusal for a call its write will refuse for the workspace, so no card
promises a draft that is not stored. Who may read the card is nr-llm's
decision (nr-llm ADR-136, ADR-260); the tool's own check runs against the
viewer in the viewer's workspace.

**The model reads the texts of the workspace.** ``AltTextContext`` reads the
page, the content element, the elements around it and a record's text
fields as the workspace of the turn shows them.

**Provenance needs nothing here.** nr-llm dispatches
``AfterAiRecordWrittenEvent`` for the tool's write target with the acting
user's workspace; its ``MarkAiWrittenRecord`` marks ``pages`` and
``tt_content`` only, not references.

Consequences
============

-   An editor in a draft workspace gets "Optimieren" and the whole flow; the
    alt text and the decorative mark are stored as a version of the
    reference in that workspace, and live stays as it is until the
    workspace is published.
-   nr-llm must be a version that contains #1294 (the first release after
    0.43.17); the release of this extension requires it. With 0.43.17,
    nr-llm's alt text writer still refuses a draft workspace: for a live
    reference after its card, for a reference new in the workspace already
    on the card. The decorative mark works with 0.43.16 and later.
-   ✗ A reference whose page or inline owner is new in the workspace is in
    the list but refused at "Optimieren" with the neutral 403, because
    nr-llm reads pages and owners live.
-   ✗ An alt text that is set live and emptied only in the workspace is
    still not listed there (:ref:`adr-026`).
-   ✗ An element moved onto the page in the workspace is not among the
    elements around the image that the model reads. On a page with more
    than 500 elements in the image's language, the elements around it are
    picked from the first 500 by sorting.
-   ✗ The scaled image the worker sends to the model is cropped as the live
    reference says: outside a backend request, core's ``ResourceFactory``
    reads the reference without the workspace overlay.
