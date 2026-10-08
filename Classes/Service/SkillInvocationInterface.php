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
 * every turn; it skips process skills on the forced path. It has no
 * invocation API yet, so nothing implements this interface. Without an
 * implementation, or when it answers null, the chat falls back to passing
 * the skill as a forced skill (`SkillCatalogueInterface::augmentationFor()`),
 * as before (ADR-019).
 */
interface SkillInvocationInterface
{
    /**
     * The request carrying the invocation, or null when nr-llm cannot take
     * one for this request.
     */
    public function withInvocation(AgentRunRequest $request, SkillInvocation $invocation): ?AgentRunRequest;
}
