<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use TYPO3\CMS\Core\Core\Environment;

final readonly class ExecChatProcessor implements ChatProcessorInterface
{
    public function __construct(
        private ConversationRepository $repository,
    ) {}

    public function dispatch(int $conversationUid): void
    {
        $typo3Bin = Environment::getProjectPath() . '/vendor/bin/typo3';
        $cmd = sprintf(
            '%s %s ai-chat:process %d',
            PHP_BINARY,
            escapeshellarg($typo3Bin),
            $conversationUid,
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (is_resource($process)) {
            $status = proc_get_status($process);
            $pid = (string) $status['pid'];

            $conversation = $this->repository->findByUid($conversationUid);
            if ($conversation !== null) {
                $conversation->setCurrentRequestId($pid);
                $this->repository->update($conversation);
            }

            // Close stdin and abandon process handle — do NOT call proc_close()
            // as it waits for the child to exit, blocking the HTTP request.
            fclose($pipes[0]);
        }
    }
}
