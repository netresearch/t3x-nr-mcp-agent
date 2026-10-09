<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

/**
 * A run of this conversation that waits for the user's answer (nr-llm
 * ADR-105), as the chat needs it: which run, the digest that binds an answer
 * to the question shown (nr-llm ADR-150), and the question's schema.
 *
 * The digest is nr-llm's, taken from its approvals-inbox view, not computed
 * here: the runtime recomputes it from the claimed state and refuses a
 * mismatch, so a second implementation could only drift.
 */
final readonly class InputPause
{
    /**
     * @param array<string, mixed> $schema           the input schema the run is suspended on; empty when unreadable
     * @param string|null          $unreadableReason nr-llm's reason when the pause cannot be shown as a form
     */
    public function __construct(
        public string $runUuid,
        public string $turnDigest,
        public array $schema,
        public ?string $unreadableReason = null,
    ) {}

    public function form(): InputPauseForm
    {
        return $this->unreadableReason !== null || $this->turnDigest === ''
            ? InputPauseForm::unsupported()
            : InputPauseForm::fromSchema($this->schema);
    }
}
