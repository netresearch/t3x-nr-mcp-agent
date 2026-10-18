<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Service\Agent\AgentRunRequest;

/**
 * Start a run with an invoked skill and a subject record (nr-llm ADR-214,
 * item 10).
 *
 * nr-llm passes a process skill as an invocation, not as a forced skill on
 * every turn; it skips process skills on the forced path. The invocation goes
 * out once, on the turn that starts the process; every later turn names the
 * previous run as its predecessor and nr-llm derives the process from it
 * (ADR-228). {@see NrLlmSkillInvocation} is the nr-llm side.
 */
interface SkillInvocationInterface
{
    /**
     * The request carrying the invocation, or null when the invocation cannot
     * be built for it — a skill without a record uid. The chat then passes the
     * skill as a forced skill (`SkillCatalogueInterface::augmentationFor()`).
     */
    public function withInvocation(AgentRunRequest $request, SkillInvocation $invocation): ?AgentRunRequest;
}
