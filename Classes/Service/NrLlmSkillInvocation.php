<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\SkillInvocation as NrLlmInvocation;
use Netresearch\NrLlm\Domain\ValueObject\SubjectRecord;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;

/**
 * The nr-llm side of {@see SkillInvocationInterface}: the chat's invocation
 * as nr-llm's `SkillInvocation` (ADR-214 item 10, ADR-228) — the skill by
 * record uid, and the conversation's page as the subject record, in the
 * page's language.
 *
 * Which version of the skill runs, its text and its tools are nr-llm's to
 * decide from the approval it finds; the chat names the skill only.
 */
final readonly class NrLlmSkillInvocation implements SkillInvocationInterface
{
    public function withInvocation(AgentRunRequest $request, SkillInvocation $invocation): ?AgentRunRequest
    {
        if ($invocation->skillUid < 1) {
            return null;
        }

        $subject = $invocation->subjectTable !== null && $invocation->subjectUid !== null && $invocation->subjectUid > 0
            ? new SubjectRecord(new RecordReference($invocation->subjectTable, $invocation->subjectUid), max(0, $invocation->subjectLanguage))
            : null;

        return new AgentRunRequest(
            configuration: $request->configuration,
            messages: $request->messages,
            actor: $request->actor,
            allowedToolNames: $request->allowedToolNames,
            options: $request->options,
            maxIterations: $request->maxIterations,
            augmentation: $request->augmentation,
            captureRaw: $request->captureRaw,
            skillAllowList: $request->skillAllowList,
            invocation: new NrLlmInvocation($invocation->skillUid, $subject),
            predecessorRunUuid: $request->predecessorRunUuid,
        );
    }
}
