<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Updates;

use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Fill `last_message_at` of conversations saved before the column existed
 * (ADR-027).
 *
 * Nothing is wrong while it has not run: a list reads the time of such a
 * conversation from its last message row instead, which costs one more query
 * per list for exactly these rows, and the next save of a conversation writes
 * the column on its own. The wizard ends that for conversations nobody opens
 * again. Each row is written once, guarded by `last_message_at = 0`, so an
 * interrupted run can be started again.
 *
 * The namespace note of ClearStoredApprovalNoticeUpdateWizard applies here
 * too: TYPO3\CMS\Install is v13's; v14 ships the names as deprecated
 * subclasses, which the attribute autoconfiguration still recognises.
 */
#[UpgradeWizard('nrMcpAgent_lastMessageTime')]
final readonly class LastMessageTimeUpdateWizard implements UpgradeWizardInterface
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private ConversationRepository $repository,
    ) {}

    public function getTitle(): string
    {
        return 'Store when the last message of each AI Chat conversation was written';
    }

    public function getDescription(): string
    {
        return 'The lists of conversations are ordered by their last message. That time is now kept on the '
            . 'conversation record; this wizard fills it for conversations saved before. Until it has run, '
            . 'the lists read the time from the message table.';
    }

    public function updateNecessary(): bool
    {
        return $this->repository->countWithoutLastMessageTime() > 0;
    }

    public function executeUpdate(): bool
    {
        $cursor = 0;
        while (($cursor = $this->repository->backfillLastMessageTimes(self::BATCH_SIZE, $cursor)) > 0) {
            // The cursor only grows, so the loop ends; each batch starts
            // after the last row of the one before.
        }

        return true;
    }

    /**
     * @return array<int, class-string>
     */
    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }
}
