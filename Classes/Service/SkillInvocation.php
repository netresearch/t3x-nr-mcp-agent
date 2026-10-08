<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

/**
 * A skill a conversation invokes, and the record it is about (nr-llm
 * ADR-214, item 10: "start with a skill" — the skill uid and an optional
 * subject record).
 *
 * The subject is the conversation's page. The skill uid is 0 when the
 * catalogue did not know it when the skill was picked.
 */
final readonly class SkillInvocation
{
    public function __construct(
        public string $identifier,
        public int $skillUid,
        public ?string $subjectTable = null,
        public ?int $subjectUid = null,
    ) {}
}
