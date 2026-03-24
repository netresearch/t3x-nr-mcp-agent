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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;

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
        $pollIntervalOpt = $input->getOption('poll-interval');
        $pollInterval = (is_numeric($pollIntervalOpt) ? (int) $pollIntervalOpt : 200) * 1000; // to microseconds
        $workerId = 'worker_' . getmypid() . '_' . bin2hex(random_bytes(4));

        $output->writeln(sprintf('<info>AI Chat worker %s started. Polling every %dms</info>', $workerId, $pollInterval / 1000));

        /** @phpstan-ignore while.alwaysTrue */
        while (true) {
            $conversation = null;
            try {
                $conversation = $this->repository->dequeueForWorker($workerId);

                if ($conversation !== null) {
                    $output->writeln(sprintf(
                        '<info>Processing conversation %d for user %d</info>',
                        $conversation->getUid(),
                        $conversation->getBeUser(),
                    ));

                    BackendUserInitializer::initialize($conversation->getBeUser(), $this->connectionPool);

                    if ($conversation->hasPendingToolCalls()) {
                        $this->chatService->resumeConversation($conversation);
                    } else {
                        $this->chatService->processConversation($conversation);
                    }
                } else {
                    usleep($pollInterval);
                }
            } catch (Throwable $e) {
                $output->writeln(sprintf('<error>Error: %s</error>', $e->getMessage()));
                if ($conversation !== null) {
                    $conversation->setStatus(ConversationStatus::Failed);
                    $conversation->setErrorMessage(ErrorMessageSanitizer::sanitize($e->getMessage()));
                    $this->repository->update($conversation);
                }
            } finally {
                // Clear BE_USER after processing to prevent leaking auth context between jobs.
                $GLOBALS['BE_USER'] = null;
            }
        }
    }

}
