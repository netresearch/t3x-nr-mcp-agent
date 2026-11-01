<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\LiveStream;

use Closure;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;

/**
 * Delivers a running turn to one reader (ADR-028).
 *
 * The turn writes its text and activity to the conversation's row. This class
 * reads that row in a request of its own and sends what changed, so the reader
 * sees the text while it is written, without a poll's delay. The final state of
 * the turn is not its business: the `done` event tells the chat to fetch it the
 * way a poll does, and the chat falls back to polling when this stream is not
 * available at all.
 *
 * A stream holds a PHP-FPM worker for as long as it runs. Three things bound
 * that: it opens only for a running turn and ends with it, it ends after
 * {@see self::MAX_SECONDS} in any case, and at most
 * {@see ExtensionConfiguration::getMaxLiveStreams()} conversations stream at a
 * time. A lease on the row counts them, so a stream that died without saying
 * so stops counting by itself.
 */
final readonly class ConversationStream
{
    private const TICK_MICROSECONDS = 100_000;

    /** Below the proxies' read timeouts, so a stream ends by itself; the chat reconnects. */
    public const MAX_SECONDS = 100;

    private const HEARTBEAT_SECONDS = 10;

    /** A lease outlives its renewal, so a slow tick does not free the slot. */
    private const LEASE_SECONDS = 15;

    private const LEASE_RENEWAL_SECONDS = 5;

    /** @var Closure(int): void */
    private Closure $sleep;

    /** @var Closure(): float */
    private Closure $clock;

    /**
     * @param (Closure(int): void)|null $sleep test seam: wait this many microseconds
     * @param (Closure(): float)|null   $clock test seam: seconds, monotonic
     */
    public function __construct(
        private ConversationRepository $repository,
        private ExtensionConfiguration $config,
        private EventEmitterInterface $emitter,
        ?Closure $sleep = null,
        ?Closure $clock = null,
    ) {
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    /**
     * Take a slot for this conversation. False when all are taken, or streaming
     * is switched off: the chat then keeps polling.
     */
    public function admit(int $conversationUid, int $beUserUid): bool
    {
        $max = $this->config->getMaxLiveStreams();

        return $max > 0 && $this->repository->takeStreamLease($conversationUid, $beUserUid, time() + self::LEASE_SECONDS, $max);
    }

    public function run(int $conversationUid, int $beUserUid): void
    {
        $this->emitter->open();

        try {
            $this->follow($conversationUid, $beUserUid);
        } finally {
            $this->repository->releaseStreamLease($conversationUid, $beUserUid);
        }
    }

    private function follow(int $conversationUid, int $beUserUid): void
    {
        $started      = ($this->clock)();
        $lastBeat     = $started;
        $lastRenewal  = $started;
        $sentText     = '';
        $sentStatus   = null;
        $sentActivity = null;
        $sentCount    = null;

        do {
            $state = $this->repository->findStreamState($conversationUid, $beUserUid);
            if ($state === null) {
                $this->emitter->send(['done' => true]);

                return;
            }

            $event = [];
            if ($state['status'] !== $sentStatus) {
                $sentStatus = $event['status'] = $state['status'];
            }

            // A message can arrive inside a turn that goes on: the answer before
            // the follow-up turn of an approved step (ADR-023). The chat fetches it.
            if ($state['message_count'] !== $sentCount) {
                $sentCount = $event['totalCount'] = $state['message_count'];
            }

            if ($state['activity'] !== $sentActivity) {
                $sentActivity = $event['activity'] = $state['activity'];
            }

            if ($state['live_text'] !== $sentText) {
                // A restarted round, or a reader that came in late: the text
                // is no continuation of what was sent, so it replaces it.
                if ($sentText !== '' && str_starts_with($state['live_text'], $sentText)) {
                    $event['append'] = substr($state['live_text'], strlen($sentText));
                } else {
                    $event['text'] = $state['live_text'];
                }

                $sentText = $state['live_text'];
            }

            $now = ($this->clock)();
            if ($event !== []) {
                $this->emitter->send($event);
                $lastBeat = $now;
            }

            if (!$this->isBusy($state['status'])) {
                $this->emitter->send(['done' => true]);

                return;
            }

            if ($now - $lastBeat >= self::HEARTBEAT_SECONDS) {
                $this->emitter->heartbeat();
                $lastBeat = $now;
            }

            if ($now - $lastRenewal >= self::LEASE_RENEWAL_SECONDS) {
                $this->repository->renewStreamLease($conversationUid, $beUserUid, time() + self::LEASE_SECONDS);
                $lastRenewal = $now;
            }

            if ($this->emitter->aborted()) {
                return;
            }

            ($this->sleep)(self::TICK_MICROSECONDS);
        } while (($this->clock)() - $started < self::MAX_SECONDS);

        $this->emitter->send(['reconnect' => true]);
    }

    private function isBusy(string $status): bool
    {
        return in_array(ConversationStatus::tryFrom($status), [ConversationStatus::Processing, ConversationStatus::Locked, ConversationStatus::ToolLoop], true);
    }
}
