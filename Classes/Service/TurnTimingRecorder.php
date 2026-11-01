<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Throwable;

/**
 * Stamps the phases of a chat turn, in milliseconds since the epoch, on the
 * conversation's `turn_timing` column.
 *
 * nr-llm's run record starts inside the turn. What the reader waits for before
 * that (the HTTP request, the start of the process) and after it (storing the
 * message) is invisible there, and that is the part this class exists for. The
 * chat's API returns the stamps beside the activity, so a measurement needs no
 * access to the server.
 *
 * Phases, in the order they happen: `request` (the browser's request reached
 * PHP), `dispatched` (the turn was handed over), `processStart` (the process
 * running the turn started), `claimed` (it holds the turn, TYPO3 is booted),
 * `runBegin`, `firstLlm` (the first model call returned), `runEnd` and `stored`.
 * A process and the web request run on one host, so their clocks agree.
 *
 * A failed write is logged and dropped: a turn must never fail over its
 * measurement.
 */
class TurnTimingRecorder implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var array<string, int> */
    private array $marks = [];

    private int $conversationUid = 0;

    public function __construct(
        private readonly ConversationRepository $repository,
    ) {}

    /**
     * The turn leaves the web request. Written before it is handed over, so
     * the process that claims it finds the stamps; a new turn starts a new list.
     */
    public function dispatched(int $conversationUid): void
    {
        $this->write($conversationUid, [
            'request'    => $this->stampOf($_SERVER['REQUEST_TIME_FLOAT'] ?? null),
            'dispatched' => $this->now(),
        ]);
    }

    /**
     * The process holds the turn. Only a process started for this turn has a
     * `processStart`: a long-running worker's start says nothing about it.
     */
    public function begin(Conversation $conversation, bool $startedForTurn = true): void
    {
        $this->conversationUid = $conversation->getUid();
        $this->marks           = $conversation->getTurnTiming();

        // A list that ended belongs to the last turn: a waiting worker can hold
        // this one before the request has stamped it.
        if (isset($this->marks['stored'])) {
            $this->marks = [];
        }

        if ($startedForTurn) {
            $this->marks['processStart'] = $this->stampOf($_SERVER['REQUEST_TIME_FLOAT'] ?? null);
        }

        $this->marks['claimed'] = $this->now();
    }

    /** The first stamp of a phase counts: a continuation repeats phases. */
    public function mark(string $phase): void
    {
        $this->marks[$phase] ??= $this->now();
    }

    public function finish(): void
    {
        if ($this->conversationUid <= 0) {
            return;
        }

        $this->marks['stored'] = $this->now();
        $this->write($this->conversationUid, $this->marks);
    }

    /**
     * @param array<string, int> $marks
     */
    private function write(int $conversationUid, array $marks): void
    {
        try {
            $this->repository->updateTurnTiming($conversationUid, json_encode($marks, JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->logger?->warning('Could not record the timing of conversation {uid}: {message}', [
                'uid'     => $conversationUid,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function now(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    private function stampOf(mixed $seconds): int
    {
        return is_numeric($seconds) ? (int) round((float) $seconds * 1000) : 0;
    }
}
