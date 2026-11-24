.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

.. _adr-035:

========================================================
ADR-035: The chat links to the page a change is shown on
========================================================

**Status:** Proposed

**Date:** 2026-11-24

**Amends:** :ref:`adr-034` (one more link per record, and one on the card).

Context
=======

The approval card states the current and the proposed values, and the list of
changes links to the edit form, the record history and the workspaces module.
Neither leads to the page the visitor would see. An editor who wants to look at
the page has to find it in the page tree.

A write that waits for approval is not stored, so no page shows it. A link on
the card can only open the page as it is stored now.

Decision
========

**The list of changes links to the page.** "Vorschau" (*Preview*) opens the
page of a changed page or content element as the viewer's workspace renders it:
in a draft workspace core's workspace preview, live the frontend page, for an
element with its anchor and in the language of the record.

**The card links to the page without the change, and says so.** The link reads
"Seite ohne diese Änderung öffnen" (*Open page without this change*). It stands
below the values of a call that names an existing record. A call that creates
its record, is refused or has no preview carries none.

**The link is offered only where it is true.** It is built by core's
``PreviewUriBuilder``, which uses the workspace the viewer works in, so there is
no link for a record of another workspace. The record must be readable by the
viewer by the rule of :ref:`adr-034`. A hidden element and a deleted record have
none, decided by the version in the workspace; a hidden page is previewable for
its editors and keeps its link. The page type, as the workspace
holds the page, is checked before the URL is built, because the workspace
preview answers for a page type without a frontend page as well. The rule is
that of ``PreviewUriBuilder::isPreviewable()``, which reads the live page and is
missing in early TYPO3 13.4 releases.

Consequences
============

-   Records of other tables have no preview link, although
    ``TCEMAIN.preview`` may configure one.
-   The card does not show the rendered proposal. That needs a rendering of
    values that are not stored, and is not decided here.
