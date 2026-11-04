<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Command;

use DateTimeImmutable;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ChatWorkerRepository;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\TurnTimingRecorder;
use Netresearch\NrMcpAgent\Utility\BackendUserInitializer;
use Netresearch\NrMcpAgent\Utility\ErrorMessageSanitizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Keeps a booted TYPO3 waiting for the next chat turn (ADR-029).
 *
 * It ends by itself, so that whatever supervises it starts a fresh one:
 * after --max-turns turns, after --time-limit seconds, above --memory-limit,
 * when the settings were saved since it started (it would run on the old ones),
 * and on SIGTERM or SIGINT, which a running turn finishes first. A turn that exceeds
 * --turn-limit ends the process and is marked failed, so the reader gets a Retry;
 * a turn is never run a second time by itself, because it may have written.
 * The last two need the `pcntl` extension; without it the process dies on SIGTERM
 * and a stuck turn waits for `ai-chat:cleanup`. A signal is handled between two
 * calls, so a turn blocked in a provider call ends when that call does.
 */
#[AsCommand(name: 'ai-chat:worker', description: 'Long-running worker that processes chat conversations from queue')]
final class ChatWorkerCommand extends Command
{
    private const DEFAULT_POLL_INTERVAL_MS = 200;

    private const DEFAULT_TIME_LIMIT_SECONDS = 3600;

    private const DEFAULT_MEMORY_LIMIT_MB = 256;

    private const DEFAULT_TURN_LIMIT_SECONDS = 600;

    private const TURN_LIMIT_MESSAGE = 'The turn took too long and was stopped.';

    /** How often an idle worker says so. ChatWorkerRepository::IDLE_SECONDS is above it. */
    private const HEARTBEAT_SECONDS = 2;

    private bool $stopRequested = false;

    private ?Conversation $running = null;

    public function __construct(
        private readonly ChatService $chatService,
        private readonly ConversationRepository $repository,
        private readonly ConnectionPool $connectionPool,
        private readonly ?TurnTimingRecorder $turnTiming = null,
        private readonly ?ChatWorkerRepository $workers = null,
        // The file the backend writes the extension settings to. Null reads
        // TYPO3's own; a test passes a file of its own.
        private readonly ?string $settingsFile = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('poll-interval', null, InputOption::VALUE_OPTIONAL, 'Poll interval in milliseconds', self::DEFAULT_POLL_INTERVAL_MS)
            ->addOption('max-turns', null, InputOption::VALUE_REQUIRED, 'Stop after this many turns (0 = no limit)', 0)
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many seconds (0 = no limit)', self::DEFAULT_TIME_LIMIT_SECONDS)
            ->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'Stop above this memory use in MB (0 = no limit)', self::DEFAULT_MEMORY_LIMIT_MB)
            ->addOption('turn-limit', null, InputOption::VALUE_REQUIRED, 'End a turn that runs longer than this many seconds (0 = no limit)', self::DEFAULT_TURN_LIMIT_SECONDS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pollInterval = $this->intOption($input, 'poll-interval', self::DEFAULT_POLL_INTERVAL_MS) * 1000; // to microseconds
        $maxTurns     = $this->intOption($input, 'max-turns', 0);
        $timeLimit    = $this->intOption($input, 'time-limit', self::DEFAULT_TIME_LIMIT_SECONDS);
        $memoryLimit  = $this->intOption($input, 'memory-limit', self::DEFAULT_MEMORY_LIMIT_MB) * 1024 * 1024;
        $turnLimit    = $this->intOption($input, 'turn-limit', self::DEFAULT_TURN_LIMIT_SECONDS);
        $workerId     = 'worker_' . getmypid() . '_' . bin2hex(random_bytes(4));

        $this->installSignalHandlers($output);

        $output->writeln(sprintf('<info>AI Chat worker %s started. Polling every %dms</info>', $workerId, $pollInterval / 1000));

        $startedAt      = time();
        $lastBeat       = 0;
        $turns          = 0;
        $settingsFile   = $this->settingsFile ?? $this->systemSettingsFile();
        $settingsAtBoot = $this->modificationTime($settingsFile);
        // TYPO3 read the settings before this line; a save between the two
        // reads would go unseen, so a file written between the second the
        // process started and now counts as changed too. File times have a
        // resolution of one second, so a worker that starts in the second of a
        // save stops again: a save costs restarts for up to one second. A time
        // after now (a clock ahead of this host) is not a save during the boot
        // and must not stop every worker that starts.
        $processStart = is_int($_SERVER['REQUEST_TIME'] ?? null) ? $_SERVER['REQUEST_TIME'] : $startedAt;

        try {
            while (!$this->stopRequested) {
                $settingsNow = $this->modificationTime($settingsFile);
                if ($settingsNow !== $settingsAtBoot || ($settingsNow !== null && $settingsNow >= $processStart && $settingsNow <= $startedAt)) {
                    $output->writeln('<info>The settings changed since this worker started; stopping so a new one reads them.</info>');

                    break;
                }

                if (time() - $lastBeat >= self::HEARTBEAT_SECONDS) {
                    $this->workers?->reportIdle($workerId);
                    $lastBeat = time();
                }

                $conversation = $this->runNextTurn($workerId, $turnLimit, $output);
                if ($conversation instanceof Conversation) {
                    ++$turns;
                    $lastBeat = 0;
                }

                if ($this->limitReached($turns, $maxTurns, $startedAt, $timeLimit, $memoryLimit)) {
                    break;
                }

                if (!$conversation instanceof Conversation) {
                    usleep($pollInterval);
                }
            }
        } finally {
            $this->workers?->withdraw($workerId);
        }

        $output->writeln(sprintf('<info>AI Chat worker %s stopped after %d turns.</info>', $workerId, $turns));

        return Command::SUCCESS;
    }

    private function runNextTurn(string $workerId, int $turnLimit, OutputInterface $output): ?Conversation
    {
        $conversation = null;
        try {
            $conversation = $this->repository->dequeueForWorker($workerId);
            if (!$conversation instanceof Conversation) {
                return null;
            }

            $this->workers?->withdraw($workerId);
            $this->running = $conversation;
            $this->armTurnLimit($turnLimit);

            $output->writeln(sprintf(
                '<info>Processing conversation %d for user %d</info>',
                $conversation->getUid(),
                $conversation->getBeUser(),
            ));

            $this->setTheClock();
            BackendUserInitializer::initialize($conversation->getBeUser(), $this->connectionPool);
            $this->turnTiming?->begin($conversation, startedForTurn: false);

            if ($conversation->hasPendingToolCalls()) {
                $this->chatService->resumeConversation($conversation);
            } else {
                $this->chatService->processConversation($conversation);
            }
        } catch (Throwable $e) {
            // The output ends up in the log the worker's supervisor keeps:
            // the same sanitised text as the stored error message, never
            // the raw one.
            $message = ErrorMessageSanitizer::sanitize($e->getMessage());
            $output->writeln(sprintf('<error>Error: %s</error>', OutputFormatter::escape($message)));
            if ($conversation instanceof Conversation) {
                $conversation->setStatus(ConversationStatus::Failed);
                $conversation->setErrorMessage($message);
                $this->repository->update($conversation);
            }
        } finally {
            $this->armTurnLimit(0);
            $this->running = null;
            // Clear BE_USER after processing to prevent leaking auth context between jobs.
            $GLOBALS['BE_USER'] = null;
        }

        return $conversation;
    }

    /**
     * TYPO3 reads the time once, at boot, and this process may have waited since:
     * a record written now would carry the time the worker started.
     */
    private function setTheClock(): void
    {
        $now = time();
        foreach (['EXEC_TIME', 'SIM_EXEC_TIME'] as $global) {
            $GLOBALS[$global] = $now;
        }

        foreach (['ACCESS_TIME', 'SIM_ACCESS_TIME'] as $global) {
            $GLOBALS[$global] = $now - $now % 60;
        }

        GeneralUtility::makeInstance(Context::class)->setAspect('date', new DateTimeAspect(new DateTimeImmutable('@' . $now)));
    }

    /**
     * When the settings file was last written, or null when it cannot be read.
     * TYPO3 reads the extension settings once, at boot; a worker that keeps
     * waiting after an administrator saved new ones would run the next turn on
     * the old ones (ADR-029; ADR-023, round-cap amendment of 2026-11-04).
     */
    private function modificationTime(?string $file): ?int
    {
        if ($file === null) {
            return null;
        }

        clearstatcache(true, $file);
        if (!is_file($file)) {
            return null;
        }

        $time = filemtime($file);

        return $time === false ? null : $time;
    }

    /** TYPO3's settings file, or null where TYPO3 has not set up its paths. */
    private function systemSettingsFile(): ?string
    {
        try {
            return Environment::getConfigPath() . '/system/settings.php';
        } catch (Throwable) {
            return null;
        }
    }

    private function limitReached(int $turns, int $maxTurns, int $startedAt, int $timeLimit, int $memoryLimit): bool
    {
        return ($maxTurns > 0 && $turns >= $maxTurns)
            || ($timeLimit > 0 && time() - $startedAt >= $timeLimit)
            || ($memoryLimit > 0 && memory_get_usage(true) >= $memoryLimit);
    }

    private function armTurnLimit(int $seconds): void
    {
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm($seconds);
        }
    }

    private function installSignalHandlers(OutputInterface $output): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        $stop = function (): void {
            $this->stopRequested = true;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGALRM, function () use ($output): void {
            $this->abandonRunningTurn($output);
        });
    }

    /**
     * The turn took too long. Its process cannot be trusted to come back, so it
     * ends here; the supervisor starts a new one. The reader gets a Retry.
     */
    private function abandonRunningTurn(OutputInterface $output): void
    {
        if ($this->running instanceof Conversation) {
            $output->writeln(sprintf('<error>Conversation %d exceeded the turn limit.</error>', $this->running->getUid()));
            $this->failAtTheTurnLimit($this->running);
        }

        exit(Command::FAILURE);
    }

    /** The status drops a pending decision, which a Retry would carry out. */
    private function failAtTheTurnLimit(Conversation $conversation): void
    {
        $conversation->setStatus(ConversationStatus::Failed);
        $conversation->setErrorMessage(self::TURN_LIMIT_MESSAGE);

        $this->repository->updateIf($conversation, ConversationStatus::Locked);
    }

    private function intOption(InputInterface $input, string $name, int $default): int
    {
        $value = $input->getOption($name);

        return is_numeric($value) ? (int) $value : $default;
    }
}
