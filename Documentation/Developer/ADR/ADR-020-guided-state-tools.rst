.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

.. _adr-020:

================================================================
ADR-020: Guided processes report progress, focus and open points
================================================================

**Status:** Proposed

**Date:** 2026-10-08

Context
=======

A guided process — a skill that walks an editor through a page point by
point — needs three things from the chat that a free conversation does not:

-   **Where it stands.** The header shows the page, the language and the
    point: "Über uns · Deutsch · Punkt 2 von 5".
-   **What it is about.** The content element of the current point is
    highlighted in the page module beside the chat.
-   **What is left.** A skipped point stays open beyond the conversation and
    is offered again when the process runs on the page later.

The process itself is a skill; the chat provides capabilities any skill can
use, and nr-llm runs and records the run. The model reaches capabilities
through tools. A tool knows the run it belongs to (nr-llm's
``ToolExecutionContext`` carries the acting user and the run reference), not
the conversation; the chat knows the conversation and learns the run's uuid
when the run returns.

Decision
========

Five tools, registered with nr-llm through ``ToolInterface``
(``Classes/Tool/``):

=========================== ==================================================
Tool                        Effect
=========================== ==================================================
``chat_set_progress``       Label, current point, total, for the header.
``chat_highlight_element``  One ``tt_content`` uid, for the page module.
``chat_record_open_point``  Keep a point open, keyed per page, language, skill.
``chat_list_open_points``   The open points of that scope.
``chat_resolve_open_point`` Close one.
=========================== ==================================================

**Run state, keyed by the run.** Progress and highlight are written to
``tx_nrmcpagent_run_state`` under the run's uuid and the acting user. When the
run returns — completed, waiting for an approval or an answer, failed —
``ChatService::applyResult()`` hands the row to ``GuidedStateLinker``, which
copies it into the conversation (``guided_state``) and deletes it. Only the
conversation owner's row is read, so knowing a run uuid gives no access to
anyone's state. The header therefore changes when a run pauses or ends, not
while it works; a guided process pauses at every proposal, so that is when
the editor looks.

**The highlight names a record, never markup.** The tool accepts a uid, and
only one the acting user may see: the record exists, ``tables_select`` allows
``tt_content``, the record's language is allowed and its page passes
``readPageAccess`` with ``PAGE_SHOW``. Every refusal has the same text, so a
uid alone does not reveal whether a record exists. The linker keeps the
highlight only when the element's page is the conversation's page
(``view_context``). The model never supplies a selector.

**postMessage contract.** The floating panel (``ai-chat-panel``) posts

.. code-block:: javascript

    {type: 'nr-mcp-agent:highlight', version: 1, table: 'tt_content', uid: 100}

to the backend's module frame (``list_frame``, the name TYPO3 13.4 and 14.3
give it) with ``targetOrigin`` set to its own origin, once per element.
``page-highlight.js``, loaded into the page module through
``ModifyPageLayoutContentEvent``, accepts a message only from the same origin
and from its parent window, checks every field, and marks the element by
TYPO3's own id ``element-tt_content-<uid>``. Anything else is ignored. The
full-page chat module and the popped-out panel have no page module beside
them and send nothing; the progress shows on every surface.

**Open points are editorial notes about a page, not personal state.** They are
unique per page, language, skill and key, so recording a point again — a
retry, the same finding in the next conversation — updates the row. Everyone
who may show the page and edit the language sees them, through the tools;
the tools check that on every call, reading included, because the scope comes
from the model. The user and run that last wrote a point are kept on it.
Titles and details are written by a model and read by a model in a later
run, possibly another user's: the list tool's description calls them data,
not instructions, and they reach no TYPO3 record.

**Group, data class, effect.** Group ``nr_mcp_agent`` (an administrator or a
configuration's tool groups can switch all five off together), data class
``EDITOR_CONTENT`` and enabled by default. Without a declared data class
nr-llm treats an unknown group as secret-adjacent and withholds it from every
cloud provider. The tools declare ``READ_ONLY``: they write only the chat's
own bookkeeping, never a TYPO3 record, and every write is an upsert on a
stable key. A declared write would require an approval for each call
(nr-llm ADR-134) — an approval to move the progress bar.

Consequences
============

-   A skill can show progress, focus an element and keep open points without
    the chat knowing the skill.
-   The tools live in this extension. nr-llm's draft for trusted process
    skills places such tools in nr-llm; the run-keyed state makes a move
    cheap, since nothing here depends on the conversation until the run
    returns.
-   **Open question for nr-llm:** declaring ``READ_ONLY`` for tools that
    write their own tables deviates from nr-llm ADR-111, which reserves it for
    tools without side effects. It needs the nr-llm maintainers' agreement, or
    an effect class for "writes only its own bookkeeping".
-   Progress appears at the pause, not live.
-   The cross-frame delivery is tested at both ends against the contract
    (Jest); a browser run against a backend is still needed to prove it end to
    end.
-   Two new tables. Run-state rows live from a tool call until the run
    returns; a run that never returns leaves its row behind.
