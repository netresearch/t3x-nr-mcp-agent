.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

.. _adr-028:

==========================================================
ADR-028: The answer reaches the reader while it is written
==========================================================

**Status:** Accepted

**Date:** 2026-11-01

Context
=======

The chat ran a turn in a background process and polled every two seconds. A
reader saw nothing of an answer until the turn had ended, and then, on average,
another second later. On the demo the median turn took 11 s. nr-llm ADR-240
makes the text of a tool round available while the model writes it, in the
process that runs the turn. It has to reach the browser from there.

Decision
========

**The conversation's row carries the text.** The process writes the whole text
so far to ``live_text``, at most every 100 ms (``LiveTextRecorder``), and empties
the column once the turn's messages are stored. Every reader starts from the
same state, so a second tab, a reload in the middle of an answer and a reconnect
need no code of their own. Only the transcript is a record.

**A request of its own delivers it.** ``GET /ai-chat/conversations/stream``
(``ConversationStream``) reads the row every 100 ms and sends what changed as
Server-Sent Events, with the same headers and padding as nr-llm's playground and
the Cowriter dialog. Through the demo's chain (Caddy, nginx, PHP-FPM) the Cowriter
route delivered its events as they were sent, measured on 2026-11-01.

**Polling stays.** The stream carries the text, the activity and the number of
messages, nothing else. A message that arrives inside a turn that goes on makes
the chat poll at once, as the end of the turn does.
Its ``done`` event makes the chat poll once, at once, and that poll brings the
messages, the approval card, the question and the guided state exactly as
before. While a stream is open the poll runs every 10 s to notice a stream that
went quiet. When a stream is refused or breaks without having delivered, the chat
goes on polling every 2 s, and the poll shows ``live_text`` too.

**A stream is bounded, because it holds a PHP-FPM worker.** It opens only for a
running turn and ends with it. It ends after 100 s in any case, and the chat
opens a new one. ``maxLiveStreams`` conversations stream at the same time, 3 by
default. A lease on the row counts them, so a stream that died stops counting
after 15 s. A conversation over the limit is refused and polls. Two readers can
pass the count at the same moment and go one over, and two tabs on one
conversation count once: the limit is a margin below ``pm.max_children``, not an
exact number. The nginx comment of the demo records what a pinned pool costs.

**No announcement per token.** The running text is ``aria-hidden``. The finished
message is added to the live region as before and is announced once.

**What a guardrail holds does not appear early.** nr-llm decides: with a
guardrail that holds a stream back (nr-llm ADR-238) the round is not streamed,
and the text appears with the message. Otherwise a guardrail that refuses the
finished text fails the turn after the reader saw it; the running text then goes
and the error stands in its place.

Consequences
============

-   The reader sees the first text about when the model has written the first
    128 bytes, the part nr-llm's secret redaction holds back. An answer shorter
    than that appears when it is complete.
-   A model round that only calls a tool shows no text; the activity list and the
    typing indicator say the turn is working.
-   A provider that cannot stream a tool round behaves as before, through the
    poll.
-   Every running turn with a reader causes ten small reads per second and one
    write per 100 ms of model output.
-   Two more columns on the conversation. Neither is part of the full-row write,
    so a turn's final write cannot put back an old value.
