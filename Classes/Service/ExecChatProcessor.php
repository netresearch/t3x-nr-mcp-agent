<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Exception\Typo3CliBinaryNotFoundException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Core\Environment;

final readonly class ExecChatProcessor implements ChatProcessorInterface
{
    /** Shown in the chat; the path and the reason go to the log only. */
    public const WORKER_NOT_STARTED_MESSAGE = 'The chat could not start its background process: the TYPO3 console binary was not found. An administrator finds the details in the TYPO3 log.';

    public function __construct(
        private Typo3CliBinaryResolver $binaryResolver,
        private ConversationRepository $repository,
        private LoggerInterface $logger,
    ) {}

    public function dispatch(int $conversationUid): void
    {
        try {
            $cmd = $this->buildCommand($conversationUid);
        } catch (Typo3CliBinaryNotFoundException $e) {
            $this->logger->error('AI chat worker for conversation {uid} not started: {reason}', [
                'uid' => $conversationUid,
                'reason' => $e->getMessage(),
                'exception' => $e,
            ]);
            $this->markFailed($conversationUid);
            return;
        }

        exec($cmd);
    }

    /**
     * The shell command that starts `typo3 ai-chat:process` in the background.
     *
     * Every path is passed through escapeshellarg(); the conversation uid is
     * an int.
     *
     * @internal public for tests
     *
     * @throws Typo3CliBinaryNotFoundException
     */
    public function buildCommand(int $conversationUid): string
    {
        $typo3Bin = $this->binaryResolver->resolve();
        $logFile = Environment::getProjectPath() . '/var/log/ai-chat-process.log';

        // PHP_BINARY may be php-fpm in web context — resolve CLI binary instead.
        $phpBin = $this->resolvePhpCliBinary();

        return sprintf(
            '%s %s ai-chat:process %d >> %s 2>&1 &',
            escapeshellarg($phpBin),
            escapeshellarg($typo3Bin),
            $conversationUid,
            escapeshellarg($logFile),
        );
    }

    /**
     * The controller has already claimed the conversation (status processing).
     * Without a worker nothing would release it until ai-chat:cleanup, so it
     * fails here, where the chat's next poll shows the message.
     */
    private function markFailed(int $conversationUid): void
    {
        $conversation = $this->repository->findByUid($conversationUid);
        if ($conversation === null || $conversation->getStatus() !== ConversationStatus::Processing) {
            return;
        }

        $conversation->setStatus(ConversationStatus::Failed);
        $conversation->setErrorMessage(self::WORKER_NOT_STARTED_MESSAGE);

        $this->repository->updateIf($conversation, ConversationStatus::Processing);
    }

    /**
     * Resolve the PHP CLI binary path.
     *
     * PHP_BINARY points to php-fpm when running in web context,
     * which cannot execute CLI scripts. Fall back to common CLI paths.
     */
    private function resolvePhpCliBinary(): string
    {
        $binary = PHP_BINARY;

        // Already a CLI binary — check SAPI type, not binary path
        if (PHP_SAPI !== 'fpm-fcgi' && PHP_SAPI !== 'cgi-fcgi') {
            return $binary;
        }

        // Try php CLI binary in same directory (e.g. /usr/bin/php8.4 alongside /usr/sbin/php-fpm8.4)
        $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $candidates = [
            '/usr/bin/php' . $version,
            '/usr/bin/php',
            '/usr/local/bin/php' . $version,
            '/usr/local/bin/php',
        ];

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        // Last resort — hope "php" is in PATH
        return 'php';
    }
}
