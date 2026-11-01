.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

.. _adr-029:

===========================================
ADR-029: A booted process waits for the turn
===========================================

**Status:** Accepted

**Date:** 2026-11-01

Context
=======

ADR-002 runs every chat turn in a process started for it. Before the model is
called, that process boots TYPO3, which the reader waits for on every message and
every decision. ``ai-chat:worker`` existed as the alternative, but it never ended,
one worker ran one turn at a time, a turn waited for a worker that was down until
``ai-chat:cleanup`` found it, and a long-lived process keeps what a turn left in
memory: Extbase's identity map, runtime caches, and the configuration it booted
with.

Decision
========

**The boot moves in front of the turn, and the process still ends with it.** A
worker boots, waits for a turn, runs it and ends; ``--max-turns=1`` is the
recommended setting and its supervisor starts the next one at once. So the reader
no longer waits for the boot, and every turn still gets a fresh process: nothing
of a turn reaches the next one, memory cannot grow, and a changed configuration
counts from the next turn. The price is a boot after each turn instead of before
it, which nobody waits for.

**Keeping a process across turns stays possible and is not the default.**
``--max-turns=0`` saves that boot and needs the state above to be safe. It would
also keep a connection to the provider open between turns, which saves little: in
six direct calls of a chat turn each, 0.98 s with a kept connection against 1.02 s
with a new one.

**A turn never waits for a worker that is not coming.** A worker reports itself
idle every two seconds in ``tx_nrmcpagent_chat_worker``, and takes its row away
with a turn. A dispatch compares the idle workers with the turns no process holds
and, with too few, starts a process as ``exec`` does. So all workers being busy
costs a boot, a worker that is down costs a boot, and a deploy costs a boot. Both
may reach for the turn; the claim in ``dequeueForWorker()`` and
``claimForProcess()`` lets one win.

**The limits end a worker, not a turn.** ``--time-limit`` and ``--memory-limit``
take effect between turns. SIGTERM and SIGINT let a running turn finish.
``--turn-limit`` is the one exception: a turn that exceeds it ends the process.

**A turn is never run again by itself.** A turn that was killed or exceeded the
limit may have written, and the duplicated pages of an earlier version came from a
second run over the same transcript. It fails with the state ``ai-chat:cleanup``
leaves, without a pending decision, and the reader gets a Retry.

Consequences
============

-   The reader waits for a poll of the queue, ``--poll-interval`` at most, instead
    of a boot.
-   Turns that run at the same time need as many workers. The rest are served by
    a process of their own, at the old speed.
-   A deployment runs one more service per worker. The extension works without
    any: ``processingStrategy`` stays ``exec`` by default.
-   SIGTERM handling and ``--turn-limit`` need ``pcntl``. Without it a worker dies
    on SIGTERM, in the middle of a turn if there is one, and a stuck turn waits
    for ``ai-chat:cleanup``.
-   An idle worker queries the database every ``--poll-interval`` (200 ms unless
    set) and writes every 2 s.
