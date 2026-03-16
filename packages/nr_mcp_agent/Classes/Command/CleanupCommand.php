<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Command;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[AsCommand(name: 'ai-chat:cleanup', description: 'Clean up stuck, inactive and archived conversations')]
final class CleanupCommand extends Command
{
    private const TABLE = 'tx_nrmcpagent_conversation';
    private const STUCK_TIMEOUT_SECONDS = 300;
    private const DEFAULT_DELETE_AFTER_DAYS = 90;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'delete-after-days',
            null,
            InputOption::VALUE_OPTIONAL,
            'Delete archived conversations older than this many days',
            (string) self::DEFAULT_DELETE_AFTER_DAYS,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleteAfterDaysOpt = $input->getOption('delete-after-days');
        $deleteAfterDays = is_numeric($deleteAfterDaysOpt) ? (int) $deleteAfterDaysOpt : self::DEFAULT_DELETE_AFTER_DAYS;

        $timeoutCount = $this->timeoutStuckConversations($output);
        $archiveCount = $this->autoArchiveInactiveConversations($output);
        $deleteCount = $this->deleteOldArchivedConversations($output, $deleteAfterDays);

        $output->writeln('');
        $output->writeln('<info>Cleanup summary:</info>');
        $output->writeln(sprintf('  Timed out stuck conversations: %d', $timeoutCount));
        $output->writeln(sprintf('  Auto-archived inactive conversations: %d', $archiveCount));
        $output->writeln(sprintf('  Deleted old archived conversations: %d', $deleteCount));

        return Command::SUCCESS;
    }

    /**
     * Set conversations stuck in processing/locked/tool_loop for >5 minutes to 'failed'.
     */
    private function timeoutStuckConversations(OutputInterface $output): int
    {
        $cutoff = time() - self::STUCK_TIMEOUT_SECONDS;

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $affected = $queryBuilder
            ->update(self::TABLE)
            ->set('status', 'failed')
            ->set('error_message', 'Timed out after 5 minutes without completion')
            ->where(
                $queryBuilder->expr()->in('status', $queryBuilder->createNamedParameter(
                    ['processing', 'locked', 'tool_loop'],
                    Connection::PARAM_STR_ARRAY,
                )),
                $queryBuilder->expr()->lt('tstamp', $queryBuilder->createNamedParameter($cutoff, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeStatement();

        if ($affected > 0) {
            $output->writeln(sprintf('<comment>Timed out %d stuck conversation(s)</comment>', $affected));
        }

        return $affected;
    }

    /**
     * Archive conversations that have been idle longer than the configured auto-archive days.
     */
    private function autoArchiveInactiveConversations(OutputInterface $output): int
    {
        $autoArchiveDays = $this->extensionConfiguration->getAutoArchiveDays();
        if ($autoArchiveDays <= 0) {
            return 0;
        }

        $cutoff = time() - ($autoArchiveDays * 86400);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $affected = $queryBuilder
            ->update(self::TABLE)
            ->set('archived', 1)
            ->where(
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter('idle')),
                $queryBuilder->expr()->eq('archived', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->lt('tstamp', $queryBuilder->createNamedParameter($cutoff, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeStatement();

        if ($affected > 0) {
            $output->writeln(sprintf('<comment>Auto-archived %d inactive conversation(s)</comment>', $affected));
        }

        return $affected;
    }

    /**
     * Hard-delete archived conversations older than the given number of days.
     */
    private function deleteOldArchivedConversations(OutputInterface $output, int $deleteAfterDays): int
    {
        if ($deleteAfterDays <= 0) {
            return 0;
        }

        $cutoff = time() - ($deleteAfterDays * 86400);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $affected = $queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('archived', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
                $queryBuilder->expr()->lt('tstamp', $queryBuilder->createNamedParameter($cutoff, Connection::PARAM_INT)),
            )
            ->executeStatement();

        if ($affected > 0) {
            $output->writeln(sprintf('<comment>Deleted %d old archived conversation(s)</comment>', $affected));
        }

        return $affected;
    }
}
