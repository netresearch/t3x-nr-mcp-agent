<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Command;

use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Utility\BackendUserInitializer;
use Netresearch\NrMcpAgent\Utility\ErrorMessageSanitizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;

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
        $conversationUidArg = $input->getArgument('conversationUid');
        assert(is_string($conversationUidArg) || is_int($conversationUidArg));
        $uid = (int) $conversationUidArg;
        $conversation = $this->repository->findByUid($uid);

        if ($conversation === null) {
            $output->writeln('<error>Conversation not found</error>');
            return Command::FAILURE;
        }

        if ($conversation->getStatus() !== ConversationStatus::Processing) {
            $output->writeln('<error>Conversation is not in processing state</error>');
            return Command::FAILURE;
        }

        BackendUserInitializer::initialize($conversation->getBeUser(), $this->connectionPool);

        try {
            if ($conversation->hasPendingToolCalls()) {
                $this->chatService->resumeConversation($conversation);
            } else {
                $this->chatService->processConversation($conversation);
            }
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>Error: %s</error>', $e->getMessage()));
            $conversation->setStatus(ConversationStatus::Failed);
            $conversation->setErrorMessage(ErrorMessageSanitizer::sanitize($e->getMessage()));
            $this->repository->update($conversation);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
