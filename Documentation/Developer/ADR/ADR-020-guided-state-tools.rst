.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

.. _adr-020:

===============================================
ADR-020: Guided processes report progress and focus
===============================================

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

Open points — a skipped finding that stays open beyond the conversation — are
not part of this decision. nr-llm ADR-214 has them recorded by this extension
from the denial reason of the approval card, not by a tool the model calls,
which needs the reason and the conversation's skill; they follow in their
own change.

The process itself is a skill; the chat provides capabilities any skill can
use, and nr-llm runs and records the run. The model reaches capabilities
through tools. A tool knows the run it belongs to (nr-llm's
``ToolExecutionContext`` carries the acting user and the run reference), not
the conversation; the chat knows the conversation and learns the run's uuid
when the run returns.

Decision
========

Two tools, registered with nr-llm through ``ToolInterface``
(``Classes/Tool/``):

=========================== ==================================================
Tool                        Effect
=========================== ==================================================
``chat_set_progress``       Label, current point, total and a completion flag,
                            for the header.
``chat_highlight_element``  One ``tt_content`` uid, for the page module.
=========================== ==================================================

**A completion report ends the tour.** ``chat_set_progress`` with
``completed`` shows the process as done in the header and drops the
highlight. The conversation keeps ``completed`` in its guided state, which is
where the end of a forced skill (nr-llm ADR-214, the release of the process
pin) can be read once the conversation carries one.

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

**Group, data class, effect.** Group ``nr_mcp_agent`` (an administrator or a
configuration's tool groups can switch both off together), data class
``EDITOR_CONTENT`` and enabled by default. Without a declared data class
nr-llm treats an unknown group as secret-adjacent and withholds it from every
cloud provider. Both tools are reads (``READ_ONLY``), as nr-llm ADR-214 item 9
classifies progress and highlight: they change no TYPO3 record, and their only
writes are the chat's own bookkeeping, an upsert on the run's uuid. In a
process run a read executes while the editor decides the pending write, so
the header and the highlight can show what the card is about.

Consequences
============

-   A skill can show progress and focus an element without the chat knowing
    the skill.
-   **Placement:** nr-llm ADR-214 has nr-llm ship progress and highlight as
    builtins, the progress report recorded as a run event and the highlight
    limited to targets the run registered from its subject record. These tools
    live here until then; the run-keyed state makes the switch cheap, since
    nothing here depends on the conversation until the run returns. The
    highlight here accepts any content element of the conversation's page the
    user may see, which is wider than a registered target list.
-   Progress appears at the pause, not live.
-   The cross-frame delivery is tested at both ends against the contract
    (Jest); a browser run against a backend is still needed to prove it end to
    end.
-   One new table. Run-state rows live from a tool call until the run
    returns to the chat; ``ai-chat:cleanup`` removes rows no chat took over
    after a day.
-   **Open question for nr-llm:** nr-llm offers every enabled tool to every
    run that does not narrow its tool list (``ToolCallPolicy::explain()``
    falls back to the enabled set). Runs started outside the chat — AI Tasks,
    other extensions — are therefore offered the two tools as well. Their
    run state is never taken over and is purged by ``ai-chat:cleanup``.
    Restricting the tools to chat runs needs a way for a tool to learn the
    run's caller, or a per-run tool list, from nr-llm.
