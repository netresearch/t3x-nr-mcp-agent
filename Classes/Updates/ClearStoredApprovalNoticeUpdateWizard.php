<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Updates;

use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Clear the pending-approval sentence the pause used to store (NEXT-159).
 *
 * Up to 0.13.1 a run that stopped for an approval wrote an English sentence
 * into the conversation's error_message, and the chat rendered that field
 * verbatim under the "Waiting for approval" label. The pause stores nothing
 * now and the chat renders the sentence from the status, as a label in the
 * reader's language — but a stored sentence still wins over the label, and
 * nothing in the ordinary flow touches a conversation that is parked at the
 * time of the upgrade: the field is cleared only when the reader decides,
 * retries or sends a message. This wizard clears it once.
 *
 * Two sentences were stored over time and they share their opening words:
 * 0.10.0 to 0.12.x wrote "This step writes data, so it is waiting for your
 * approval. Grant it under …", 0.13.0 and 0.13.1 wrote "This step writes data
 * and needs an approval before it runs. Run: <uuid>". The match is on that
 * prefix so both are covered, and on the status: a reason the runtime wrote
 * back with a refused decision lands in the same field of the same parked
 * conversation, and the chat must keep showing it. Those never begin with
 * these words. The necessity check and the update share one predicate, so
 * the wizard reports done exactly when it has nothing left to clear.
 *
 * The TYPO3\CMS\Install namespace is the one v13 has; on v14 it is a working
 * alias of the core classes, deprecated for removal in v15.
 */
#[UpgradeWizard('nrMcpAgent_clearStoredApprovalNotice')]
final readonly class ClearStoredApprovalNoticeUpdateWizard implements UpgradeWizardInterface
{
    private const TABLE = 'tx_nrmcpagent_conversation';

    private const STORED_SENTENCE_PREFIX = 'This step writes data';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getTitle(): string
    {
        return 'Clear the stored pending-approval sentence from parked AI Chat conversations';
    }

    public function getDescription(): string
    {
        return 'A conversation that was waiting for an approval under an earlier nr_mcp_agent release '
            . 'carries an English sentence in its error message, which the chat showed under the '
            . '"Waiting for approval" label in every backend language. The chat now renders that '
            . 'notice from the conversation status in the language of the reader, but a stored sentence '
            . 'still takes precedence. This wizard clears the stored sentence; the pending run, the '
            . 'approval card and the decision are unchanged.';
    }

    public function updateNecessary(): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $count = $this->whereStoredSentence($queryBuilder)
            ->count('uid')
            ->from(self::TABLE)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) && (int) $count > 0;
    }

    public function executeUpdate(): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $this->whereStoredSentence($queryBuilder)
            ->update(self::TABLE)
            ->set('error_message', '')
            ->executeStatement();

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

    /**
     * The one predicate both halves use. No deleted restriction: the count
     * must count exactly what the update touches, and a deleted row that
     * still carries the sentence is harmless to clear.
     */
    private function whereStoredSentence(QueryBuilder $queryBuilder): QueryBuilder
    {
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder->where(
            $queryBuilder->expr()->eq(
                'status',
                $queryBuilder->createNamedParameter(ConversationStatus::AwaitingApproval->value),
            ),
            $queryBuilder->expr()->like(
                'error_message',
                $queryBuilder->createNamedParameter(
                    $queryBuilder->escapeLikeWildcards(self::STORED_SENTENCE_PREFIX) . '%',
                ),
            ),
        );
    }
}
