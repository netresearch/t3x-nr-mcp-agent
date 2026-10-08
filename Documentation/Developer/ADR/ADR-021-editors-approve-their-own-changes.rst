.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

.. _adr-021:

==================================================
ADR-021: Editors approve their own changes in chat
==================================================

**Status:** Accepted

**Date:** 2026-10-08

Context
=======

A guided process proposes a change and the editor presses "Übernehmen". In
nr-llm a proposed write is an approval (nr-llm ADR-134), so "Übernehmen" is
the approve button of the approval card. The chat offered that card only to
administrators and to users of nr-llm's AI Tasks module
(``mayDecideApprovals()``): editors who should not see every run of the
installation could not apply the changes they asked for.

nr-llm itself lets the owner of a run decide it (``mayActOnRun()``), checks
the approver against the tool policy (nr-llm ADR-133) and, where a
configuration requires a second approver, refuses the initiator's approval
(nr-llm ADR-172). The module check is the chat's own restriction.

Decision
========

**A new backend group permission,** "Approve own changes in the chat"
(``customPermOptions``, stored as ``tx_nrmcpagent:approve_own_changes``),
lets an editor decide the approvals of their own conversations. It is added
beside the module rule, not instead of it: administrators and module users
decide as before.

**Approving needs the change's preview, built with the editor's rights.**
The brief asked for "the record permissions the write needs". nr-llm offers
no question for that before the write runs; the closest signal is the
approval preview, which the tool computes as the acting user and which
fails, with the tool's reason, for a plan the user may not carry out. So an
editor deciding by the new permission may approve only when every pending
call has a preview that did not fail; otherwise the card offers the denial
only and says that someone with AI Tasks can approve. This is a proxy, and
it is stated as one: the write itself still runs as the conversation's
owner, where TYPO3's DataHandler enforces the record permissions, and a
tool without a preview stays with administrators and module users. The
check is repeated on the server when the decision arrives, from the run as
it waits then.

**Four-eyes is shown, not discovered.** In the chat the reader is always the
run's initiator. When the run's configuration requires a second approver
(read from the run's own ``configurationUid``), the card offers no approve
button to anyone and names AI Tasks, where a colleague approves; the denial
stays, as nr-llm allows it. A refusal nr-llm still returns
(``SelfApprovalDeniedException``) is a hand-back that keeps the card, no
longer a failed conversation.

Consequences
============

*   Integrators grant the permission per backend group under "Custom
    module options".
*   ``getMessages()`` returns ``pendingApproval.approveBlocked``: ``''``,
    ``secondApprover`` or ``preview``.
*   An editor with the permission sees the card, may always deny, and may
    approve changes nr-llm could preview for them.
