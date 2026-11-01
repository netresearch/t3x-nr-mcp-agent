<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Throwable;

/**
 * Keeps the text the model is writing on the conversation's `live_text`
 * column while the turn runs (ADR-028).
 *
 * The turn runs in its own process, so this column is how the text reaches the
 * request that delivers it to the browser. The column holds the whole text so
 * far, not a queue of pieces, which is what makes a second tab, a reload in the
 * middle of an answer and a reconnect work: every reader starts from the same
 * state. It is emptied when the turn has stored its messages, so the transcript
 * stays the only record.
 *
 * Writes are throttled: a write per token would be a query per 11 ms. A failed
 * write is logged and dropped, because the answer also arrives through the
 * transcript; a turn must not fail over its preview.
 */
class LiveTextRecorder implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** The shortest time between two writes. A reader polls the column at about this rate. */
    private const WRITE_INTERVAL_NS = 100_000_000;

    private const ROUND_SEPARATOR = "\n\n";

    private string $earlierRounds = '';

    private string $currentRound = '';

    private int $round = 0;

    private int $lastWriteNs = 0;

    private bool $unwritten = false;

    public function __construct(
        private readonly ConversationRepository $repository,
    ) {}

    public function append(int $conversationUid, int $round, string $text): void
    {
        if ($round !== $this->round) {
            $this->closeRound();
            $this->round = $round;
        }

        $this->currentRound .= $text;
        $this->unwritten = true;

        if (hrtime(true) - $this->lastWriteNs >= self::WRITE_INTERVAL_NS) {
            $this->write($conversationUid);
        }
    }

    /** The provider call was sent again: this round's text so far is void. */
    public function restart(int $conversationUid): void
    {
        $this->currentRound = '';
        $this->unwritten    = true;
        $this->write($conversationUid);
    }

    /** What the throttle held back goes out. */
    public function flush(int $conversationUid): void
    {
        if ($this->unwritten) {
            $this->write($conversationUid);
        }
    }

    /**
     * A turn begins. The column is written whatever this process knows: a turn
     * that died left its text there, and a later turn must not show it.
     */
    public function reset(int $conversationUid): void
    {
        $this->clear($conversationUid);
        $this->store($conversationUid, '');
    }

    /** The turn is over and its messages are stored. */
    public function clear(int $conversationUid): void
    {
        $hadText             = $this->earlierRounds !== '' || $this->currentRound !== '';
        $this->earlierRounds = '';
        $this->currentRound  = '';
        $this->round         = 0;
        $this->unwritten     = false;

        if ($hadText) {
            $this->store($conversationUid, '');
        }
    }

    private function closeRound(): void
    {
        if ($this->currentRound !== '') {
            $this->earlierRounds .= $this->currentRound . self::ROUND_SEPARATOR;
            $this->currentRound = '';
        }
    }

    private function write(int $conversationUid): void
    {
        $this->lastWriteNs = hrtime(true);
        $this->unwritten   = false;
        $this->store($conversationUid, $this->earlierRounds . $this->currentRound);
    }

    private function store(int $conversationUid, string $text): void
    {
        try {
            $this->repository->updateLiveText($conversationUid, $text);
        } catch (Throwable $e) {
            $this->logger?->warning('Could not store the live text of conversation {uid}: {message}', [
                'uid'     => $conversationUid,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
