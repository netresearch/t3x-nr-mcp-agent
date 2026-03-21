<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use TYPO3\CMS\Core\Core\Environment;

final readonly class ExecChatProcessor implements ChatProcessorInterface
{
    public function dispatch(int $conversationUid): void
    {
        $projectPath = Environment::getProjectPath();
        $typo3Bin = $projectPath . '/vendor/bin/typo3';
        $logFile = $projectPath . '/var/log/ai-chat-process.log';

        // PHP_BINARY may be php-fpm in web context — resolve CLI binary instead.
        $phpBin = $this->resolvePhpCliBinary();

        $cmd = sprintf(
            '%s %s ai-chat:process %d >> %s 2>&1 &',
            escapeshellarg($phpBin),
            escapeshellarg($typo3Bin),
            $conversationUid,
            escapeshellarg($logFile),
        );

        exec($cmd);
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
