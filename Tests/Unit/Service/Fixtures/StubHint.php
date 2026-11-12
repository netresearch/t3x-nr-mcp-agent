<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\Fixtures;

/**
 * A hint shaped like nr-llm's GuardrailHint (ADR-288), which releases before
 * that ADR do not have.
 */
final readonly class StubHint
{
    public function __construct(
        public string $kind,
        public string $message,
        public string $guardrail = 'ruleSetJudge',
        public string $rule = '',
        public string $passage = '',
    ) {}
}
