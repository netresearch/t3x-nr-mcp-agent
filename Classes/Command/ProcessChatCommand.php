<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Command;

use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Utility\BackendUserInitializer;
use Netresearch\NrMcpAgent\Utility\ErrorMessageSanitizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
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
        // Claim the turn in one UPDATE, as ai-chat:worker does: a second
        // ai-chat:process for the same conversation, or a worker that took it
        // first, leaves this one with nothing to do.
        $claimId = 'process_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $conversation = $this->repository->claimForProcess($uid, $claimId);

        if ($conversation === null) {
            if ($this->repository->findByUid($uid) === null) {
                $output->writeln('<error>Conversation not found</error>');
                return Command::FAILURE;
            }

            // Not an error: another ai-chat:process or a worker has the turn,
            // or it is already over. Nothing is left to do here.
            $output->writeln(sprintf(
                '<info>Conversation %d is not in processing state: another process or worker has claimed it, or the turn is over. Nothing to do.</info>',
                $uid,
            ));
            return Command::SUCCESS;
        }

        BackendUserInitializer::initialize($conversation->getBeUser(), $this->connectionPool);

        try {
            if ($conversation->hasPendingToolCalls()) {
                $this->chatService->resumeConversation($conversation);
            } else {
                $this->chatService->processConversation($conversation);
            }
        } catch (Throwable $e) {
            // The output ends up in var/log/ai-chat-process.log: the same
            // sanitised text as the stored error message, never the raw one.
            $message = ErrorMessageSanitizer::sanitize($e->getMessage());
            $output->writeln(sprintf('<error>Error: %s</error>', OutputFormatter::escape($message)));
            $conversation->setStatus(ConversationStatus::Failed);
            $conversation->setErrorMessage($message);
            $this->repository->update($conversation);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
