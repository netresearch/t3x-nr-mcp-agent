<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;

/**
 * A conversation runs a guided process when it carries a skill (ADR-019)
 * that is not known to be a plain one (ADR-022). nr-llm 0.41 marks process
 * skills (`Skill::isProcess()`, nr-llm PR 1025); without the marker every
 * skill counts as a process, as for the four-eyes refusal at the start.
 *
 * Asked in the web request (the card's answers, a new message while a run
 * waits), where the catalogue answers for the conversation's owner.
 */
final readonly class SkillProcessRunDetector implements ProcessRunDetectorInterface
{
    public function __construct(
        private SkillCatalogueInterface $skills,
    ) {}

    public function isProcessRun(Conversation $conversation): bool
    {
        $identifier = $conversation->getSkillIdentifier();
        if ($identifier === '') {
            return false;
        }

        return ($this->skills->find($identifier)['process'] ?? null) !== false;
    }
}
