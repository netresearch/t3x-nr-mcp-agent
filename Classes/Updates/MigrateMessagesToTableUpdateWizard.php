<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Updates;

use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Move every stored transcript from the conversation's `messages` column into
 * the message table (NEXT-172, ADR-016).
 *
 * Nothing breaks while it has not run: a conversation without message rows is
 * read from the column, and the first time it is saved its transcript moves
 * on its own. The wizard moves the rest, in batches, one conversation per
 * transaction, so an interrupted run leaves every conversation either moved
 * or untouched and can simply be started again.
 *
 * The namespace note of ClearStoredApprovalNoticeUpdateWizard applies here
 * too: TYPO3\CMS\Install is v13's; v14 ships the names as deprecated
 * subclasses, which the attribute autoconfiguration still recognises.
 */
#[UpgradeWizard('nrMcpAgent_migrateMessagesToTable')]
final readonly class MigrateMessagesToTableUpdateWizard implements UpgradeWizardInterface
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private ConversationRepository $repository,
    ) {}

    public function getTitle(): string
    {
        return 'Move AI Chat transcripts into their own table';
    }

    public function getDescription(): string
    {
        return "AI Chat used to keep each conversation's messages as one JSON value in the conversation "
            . 'record. They now live in tx_nrmcpagent_message, one row per message. The chat reads both '
            . 'until this wizard has moved the remaining transcripts; afterwards the old column is empty.';
    }

    public function updateNecessary(): bool
    {
        return $this->repository->countLegacyTranscripts() > 0;
    }

    public function executeUpdate(): bool
    {
        while ($this->repository->migrateLegacyTranscripts(self::BATCH_SIZE) > 0) {
            // Each batch clears what it moved, so the next one picks up the
            // rest. A batch that moves nothing ends the run; updateNecessary()
            // then still reports what is left, instead of the run never
            // returning.
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
