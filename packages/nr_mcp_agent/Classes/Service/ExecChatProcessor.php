<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use TYPO3\CMS\Core\Core\Environment;

final readonly class ExecChatProcessor implements ChatProcessorInterface
{
    public function dispatch(int $conversationUid): void
    {
        $typo3Bin = Environment::getProjectPath() . '/vendor/bin/typo3';
        $cmd = sprintf(
            '%s %s ai-chat:process %d > /dev/null 2>&1 &',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($typo3Bin),
            $conversationUid,
        );

        // Fire-and-forget: exec() with & does not block the HTTP request
        // and avoids proc_open resource handle leaks.
        exec($cmd);
    }
}
