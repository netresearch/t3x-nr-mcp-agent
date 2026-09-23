..  include:: /Includes.rst.txt

.. _adr-017:

===========================================================
ADR-017: The chat states what the run did, not what it said
===========================================================

**Status:** Accepted

**Date:** 2026-09-23

Context
=======

An analysis of the backend chats on the Netresearch demo installation
(NEXT-167) found four ways in which the chat left its reader with a wrong
picture, each in real conversations:

-   **A change claimed, none made.** Four times in one conversation the
    assistant answered "Erledigt" and listed the page and the content element
    it had changed, with uids — in runs that called no tool at all. The run
    records every write the DataHandler actually performed as a ``tool_write``
    step (nr-llm ADR-182); nothing in the chat compared the answer with it.
-   **A configuration error shown to everyone.** A provider without an API
    key surfaced as "API key identifier is required for provider OpenAI". That
    sentence helps an administrator and tells an editor nothing, and it named
    neither the place to fix it nor the person who can.
-   **Tools the model could not see.** A configuration's tool groups held back
    sixteen tools. Asked which tools could not be used, the model said it saw no
    such list — and for a request one of those tools would have served, it
    could only answer that nothing could do it.
-   **"weiter" while an approval was pending.** A message sent while a run
    waited for an approval abandoned the approval and started a second run over
    the same transcript. After a lost answer, "weiter", "alles approved" and
    "habe alles freigegeben" each made the model draft the same page again;
    four of those five re-drafts were approved.

Decision
========

**The run decides, the text only triggers.** When a completed answer claims a
change (German and English participles such as "erledigt", "gespeichert",
"created", "updated", not preceded by a negation such as "nicht", "nichts",
"kein" or "not" within three words) and the run wrote nothing, the assistant
message is stored with the notice ``nothingSaved``. The chat renders it as a
label in the reader's language: "Nothing was saved in this step". The word
list is generous on purpose: a match on an answer that only talks about a
change still yields a true notice, because the run wrote nothing.

"The run wrote nothing" is read from the run's persisted event stream
(``AgentRuntimeInterface::events()``), not from the result's steps: every
resume starts a fresh trace, so a write carried out at the first approval of a
turn is not in the steps of the segment that answers after the second. Two
kinds of event count as a write: ``tool_write``, which a builtin writer leaves
(nr-llm ADR-182), and a call that executed without error directly after an
``approval`` with ``approved = true`` — a remote tool whose write needed
approval leaves no write target, and every approval-bound call is
write-declared (nr-llm ADR-134). A run that could not be persisted has no
stream; its result's steps are the evidence then. A stream that cannot be read
yields no notice rather than a guessed one.

The notice is for the reader. The model is told the same on the next turn: the
flagged answer reaches it with a note appended — "the run behind this answer
wrote no record" — built per turn and never stored, so the model does not build
on its own claim. The system prompt adds the rule behind it: claim a change
only on a successful write result, and never state a uid no tool returned.

**A failure carries its kind.** Next to ``error_message`` the conversation
stores ``error_code``: ``providerNotConfigured`` for nr-llm's
``ProviderConfigurationException`` and ``ProviderAuthenticationException``
(the exception chain is walked), ``chatNotConfigured`` for a missing Task,
configuration or model — on an ordinary turn and on the continuation an
approval starts alike. The request that shows the failure phrases it for the
reader: an administrator gets the stored text and a link to the nr-llm
providers or tasks module, everyone else a localised sentence that tells them to
ask the administration. The code is data and the sentence is rendered per
request, for the reason NEXT-159 moved the pause notice out of the stored
field: a stored sentence is frozen in one language and for one reader.

**The model is told which tools it is not given.** Where nr-llm provides
``UnavailableToolsResolverInterface`` (its ADR-201), the system prompt lists
the tools this user's run is not offered, one line each with the reason in
plain words, and tells the model to say that such a tool exists and to point
to an administrator. The service is looked up in the container by name and its
absence yields an empty list, so the chat keeps working unchanged on an nr-llm
without it. The list is information, never a gate. A builtin tool's trust-zone
refusal in observe mode is offered and therefore not listed. Remote (MCP) tools
are listed only when the chat user is an administrator: nr-llm leaves them out
for everyone else, because their names are operator configuration.

**"Go on" is not a new request, and not a decision.** When the conversation
waits for an approval and the whole message is one of a short, explicit list of
"go on" and "I approved it" phrases, the chat asks the run where it stands
before anything is written:

-   still waiting: nothing is stored and no run starts; the reader is told
    that the decision is taken on the card — or in AI Tasks, for a reader who
    may not decide in the chat and therefore gets no card;
-   decided elsewhere and still running: the same answer as any busy
    conversation;
-   decided elsewhere and finished: the message is stored together with a line
    that names the records the run wrote (``pages:10073``, read from the run's
    ``tool_write`` events, which the privacy filter keeps at every level), and
    the conversation is idle. The line is stored language-neutral, for the
    model, with the notice ``runFinishedOutside`` and the records beside it;
    the reader sees the label in their own language, for the reason
    ``error_code`` exists. The next turn therefore knows the page exists;
-   anything the run cannot answer: the ordinary path.

The message is never taken as the approval. An approval is a decision on the
preview the card shows (nr-llm ADR-132, ADR-136); "habe alles freigegeben" is a
statement about a decision the writer believes was already taken, and in the
demo it was written about a run that had been decided in another module.

The second half of the duplicate guard is nr-llm's: the preview of
``create_page_draft`` warns when the parent already holds a page with the same
title.

**Stuck conversations** were already covered: ``ai-chat:cleanup`` fails any
conversation left in ``processing``, ``locked`` or ``tool_loop`` for more than
five minutes. The demo conversation that stayed in ``processing`` for weeks
matches that condition, so the command cannot have run there in that time;
whether and how it is scheduled on the demo is not in the exported data. The
reset now also clears the failure code.

Consequences
============

-   An answer that claims a change the run did not make is visibly marked.
    An answer that describes a change a previous run made, in a turn that made
    none, is marked too — accurately, since nothing was saved in that step.
-   The model still says what it says; the notice sits beside it. The prompt
    rule makes the false claim less likely, the notice makes it visible.
-   The conversation table gains the column ``error_code``. Existing rows have
    an empty code and are shown as before.
-   Non-administrators learn the names of builtin tools they may not use,
    including administrator-only ones. Tool names and reasons are policy facts,
    not instance data. Remote tool names stay with administrators.
-   A "go on" phrase outside the list is an ordinary request, and so is any
    longer sentence that contains one.
