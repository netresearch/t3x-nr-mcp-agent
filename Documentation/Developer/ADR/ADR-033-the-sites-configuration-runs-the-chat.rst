.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

.. _adr-033:

========================================================
ADR-033: A site's own configuration runs the chat
========================================================

**Status:** Accepted

**Date:** 2026-11-11

**Amends:** :ref:`adr-015` (the Task decides the configuration; for a site
with its own configuration it no longer does).

Context
=======

nr-llm lets a site name its own LLM configuration in the site setting
``nrllm.configuration`` (nr-llm ADR-244). An installation uses it where a
site's content may only go to one provider, or one model. cowriter,
nr-landingpage and nr-repurpose honour it. The chat passes the site of the
conversation's page to nr-llm since 0.36.0, so the site's term lists apply,
but it ran every turn on the configuration of its Task: in ADR-244 a
configuration the caller passes wins over the site's, and the chat always
passed its Task's. A site set up for one provider therefore had its pages
read and edited through the Task's provider.

The product decision of 2026-11-11 (NEXT-169): where the site of the page
sets its own configuration, the chat uses it instead of the Task's. If its
model cannot call tools, the chat refuses with a message that names the
reason and what an administrator can change. It never falls back to another
model, because that would break the site's provider requirement.

How nr-llm records whether a model can call tools, as measured on nr-llm
0.43.23:

-   The model record has a capability ``tools`` (*Tool Use*,
    ``Model::supportsTools()``). nr-llm requires it of a model it selects by
    criteria for a tool call (nr-llm ADR-138, ``OperationCapabilityMap``)
    and does not check it for a fixed model. Discovery writes it for
    Mistral, OpenRouter and Ollama, which report it per model, and for the
    curated OpenAI, Anthropic and Gemini entries. Groq reports no
    capabilities, so its models lack the flag whether or not they can call
    tools.
-   The provider adapter implements ``ToolCapableInterface``. nr-llm's agent
    loop refuses an adapter that does not, with "does not support tool
    calling".

Neither alone is reliable: the adapter answers per provider, not per model,
and the flag is missing on every Groq model.

Decision
========

**The site's configuration replaces the Task's.** At the start of every
turn, a retry included, ``SiteConfigurationSelector`` asks nr-llm's
``ConfigurationResolver::resolveForSite()`` for the configuration the site
of the conversation's page names, with the turn's actor. The site is the one
``siteOf()`` already passes to nr-llm, so the term lists and the
configuration cannot come from two sites. Where the site names one, the turn
runs on it: its provider, model, options and system prompt. The Task still
adds its prompt template, the chat's job. The Task's configuration is not
asked for, so a disabled or group-restricted Task configuration does not
stop a turn on the site's. A site that names none, a conversation about no
page, and a page the user may not show keep the Task's configuration, as
before.

**What the chat cannot run on is refused before any model call.** The turn
fails with one of three codes (``SiteConfigurationRefusal``):

-   ``siteConfig.unusable``: the configuration does not exist, is disabled,
    or is restricted to backend groups the user is not in (nr-llm's
    exceptions from ``resolveForSite()``);
-   ``siteConfig.noFixedModel``: it selects its model by criteria. The chat
    needs a fixed model to check, and already ran only on fixed models;
-   ``siteConfig.noTools``: the model lacks the capability ``tools``, or its
    adapter is not tool-capable. Both have to say yes.

The stored message carries the site, configuration and model names as JSON;
the chat renders the label in the reader's language for every reader, an
administrator with a link to the LLM configurations. Nothing falls back to
the Task's configuration.

**A waiting run is not continued on another configuration.** A run keeps
the configuration it started with, and nr-llm continues it there. Before
``approve()`` or ``submitInput()`` the chat asks the site again. When it now
names a configuration other than the run's, or one the chat refuses, the
decision or answer is handed back (``handBack.siteConfig``,
``handBack.inputSiteConfig``) without calling nr-llm. A denial is held back
too, because it also continues the run with a model call. A new message is
the way on: an ordinary chat leaves the waiting run waiting and a guided
process cancels it, neither with a model call. A site that names no
configuration leaves waiting runs as they were. The site asked is the one
of the conversation's page, which only a new turn changes (a decision or an
answer keeps the page of the turn that made the proposal), so it is the site
the run was started for.

**The bans of both configurations apply** (owner decision of 2026-11-11).
On a site's configuration the run names the Task's configuration with
nr-llm's ``withGuardrailConfigurations()`` (nr-llm ADR-290). nr-llm then
screens the run with the term lists of both: a ban of either one blocks, a
list bound to both is read once, and the value survives a pause for an
approval or an answer. A ban can be added, never dropped, as a privacy level
can only be tightened. The rule-set judge (nr-llm ADR-288) follows the same
principle in nr-llm: every side whose judge is on is judged with its own rule
set by its own judging configuration, and the stricter mode of the two
decides (block over report over off). The value is chosen again on every
turn, so a turn on the Task's configuration names nothing further.

**Recorded on the run.** nr-llm stores the configuration on every run
(``AgentRun::configurationUid``) and shows it in *Agent Runs*. The chat adds
no record of its own: the run record (ADR-030) feeds later prompts and stays
limited to what ran. A refused turn starts no run; the refused site,
configuration and model are kept only in the conversation's error and in the
log.

**Without a conversation the Task answers.** The upload formats and the
list of unavailable tools are computed without a page, so they describe the
Task's configuration. A turn expands its attachments for the provider it
actually runs on.

Consequences
============

-   ● A site that names its own configuration keeps its content with its
    provider and model in the chat too, with the same rule as cowriter,
    nr-landingpage and nr-repurpose, except that the site wins over the
    chat's Task.
-   ● A refusal says which site, configuration and model, and what to
    change, in the reader's language. The editor is not left with a generic
    failure.
-   ● The term lists of the chat's configuration and of the site's
    configuration both apply; site-bound lists keep applying. The privacy
    level follows the run's configuration, which is now the site's.
-   ◑ The chat calls ``withGuardrailConfigurations()``, so it needs an
    nr-llm release that contains ADR-290.
-   ◑ A Groq model, or a hand-made model record, that can call tools but
    lacks the *Tool Use* flag is refused until an administrator ticks it.
    The flag is the only per-model statement nr-llm has.
-   ◑ A site configuration made for cowriter brings its system prompt and its
    tool groups into the chat. An empty tool group list allows every group.
-   ◑ A guided process whose site changes its setting mid-way continues on
    another configuration with the next turn, and nr-llm drops the process
    with ``configuration_changed`` (nr-llm ADR-228); the chat says so.
-   ✗ A proposal waiting when the setting changes cannot be decided any more
    in the chat; the editor goes on with a new message.

Alternatives considered
=======================

**Fall back to the Task's configuration when the site's cannot call
tools.** Rejected by the product decision: silent, and it sends the site's
content to the provider it was set up to avoid.

**The Task wins, as in cowriter.** cowriter puts a task's configuration
above the site's. For the chat that is today's behaviour, which the decision
reverses.

**Trust the adapter alone.** Rejected: ``ToolCapableInterface`` is
implemented per provider, so a model of a tool-capable provider that cannot
call tools (an embedding or audio model put into a configuration) would pass
and fail at the first call.

**Probe the model with a tool call.** Rejected: a model call before the
turn costs money, takes seconds on every turn, and sends a request to the
provider for a turn that may then be refused.
