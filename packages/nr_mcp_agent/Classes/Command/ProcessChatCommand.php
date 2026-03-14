<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Command;

use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Service\ChatService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(name: 'ai-chat:process', description: 'Process a single chat conversation')]
final class ProcessChatCommand extends Command
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
        $this->addArgument('conversationUid', InputArgument::REQUIRED, 'UID of the conversation to process');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uid = (int)$input->getArgument('conversationUid');
        $conversation = $this->repository->findByUid($uid);

        if ($conversation === null) {
            $output->writeln('<error>Conversation not found</error>');
            return Command::FAILURE;
        }

        $this->initializeBackendUser($conversation->getBeUser());

        $this->chatService->processConversation($conversation);

        return Command::SUCCESS;
    }

    private function initializeBackendUser(int $userUid): void
    {
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);

        $qb = $this->connectionPool->getQueryBuilderForTable('be_users');
        $userRecord = $qb->select('*')
            ->from('be_users')
            ->where($qb->expr()->eq('uid', $userUid))
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
