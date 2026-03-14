<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Command;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Service\ChatService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(name: 'ai-chat:worker', description: 'Long-running worker that processes chat conversations from queue')]
final class ChatWorkerCommand extends Command
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly ConversationRepository $repository,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('poll-interval', null, InputOption::VALUE_OPTIONAL, 'Poll interval in milliseconds', 200);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pollInterval = (int)$input->getOption('poll-interval') * 1000; // to microseconds
        $workerId = 'worker_' . getmypid() . '_' . bin2hex(random_bytes(4));

        $output->writeln(sprintf('<info>AI Chat worker %s started. Polling every %dms</info>', $workerId, $pollInterval / 1000));

        while (true) {
            try {
                $conversation = $this->dequeue($workerId);

                if ($conversation !== null) {
                    $output->writeln(sprintf(
                        '<info>Processing conversation %d for user %d</info>',
                        $conversation->getUid(),
                        $conversation->getBeUser(),
                    ));

                    $this->initializeBackendUser($conversation->getBeUser());
                    $this->chatService->processConversation($conversation);
                } else {
                    usleep($pollInterval);
                }
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>Error: %s</error>', $e->getMessage()));
            } finally {
                $GLOBALS['BE_USER'] = null;
            }
        }
    }

    private function dequeue(string $workerId): ?Conversation
    {
        $conn = $this->connectionPool->getConnectionForTable('tx_nrmcpagent_conversation');

        $affected = $conn->executeStatement(
            'UPDATE tx_nrmcpagent_conversation
             SET status = \'locked\', current_request_id = ?
             WHERE status = \'processing\' AND deleted = 0
             ORDER BY tstamp ASC LIMIT 1',
            [$workerId]
        );

        if ($affected === 0) {
            return null;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('tx_nrmcpagent_conversation');
        $row = $qb->select('*')
            ->from('tx_nrmcpagent_conversation')
            ->where(
                $qb->expr()->eq('current_request_id', $qb->createNamedParameter($workerId)),
                $qb->expr()->eq('status', $qb->createNamedParameter('locked')),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return Conversation::fromRow($row);
    }

    private function initializeBackendUser(int $userUid): void
    {
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);

        $qb = $this->connectionPool->getQueryBuilderForTable('be_users');
        $userRecord = $qb->select('*')
            ->from('be_users')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($userUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        if ($userRecord === false) {
            throw new \RuntimeException(sprintf('Backend user %d not found', $userUid));
        }

        $backendUser->user = $userRecord;
        $backendUser->fetchGroupData();
        $GLOBALS['BE_USER'] = $backendUser;
    }
}
