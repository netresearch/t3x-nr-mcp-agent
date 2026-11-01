<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\LiveStream;

/**
 * Writes events as Server-Sent Events straight to the output and flushes each.
 *
 * The same transport as nr-llm's playground stream and the Cowriter dialog:
 * buffering and compression are switched off, `X-Accel-Buffering: no` tells
 * nginx not to hold the FastCGI response, `no-transform` keeps a compressing
 * proxy out of it, and every event is padded with an SSE comment line, so a
 * proxy that waits for a full buffer passes it on at once. The caller returns a
 * NullResponse afterwards, because the body has already been sent.
 *
 * @internal
 */
final class ServerSentEventEmitter implements EventEmitterInterface
{
    /** The size up to which an event is padded: the flush threshold of common proxy buffers. */
    public const MIN_EVENT_BYTES = 4096;

    public function open(): void
    {
        ini_set('zlib.output_compression', '0');
        ini_set('output_buffering', '0');
        ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        if (headers_sent()) {
            return;
        }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
    }

    public function send(array $event): void
    {
        echo self::format($event);
        flush();
    }

    public function heartbeat(): void
    {
        echo ": keep-alive\n\n";
        flush();
    }

    public function aborted(): bool
    {
        return connection_aborted() === 1;
    }

    /**
     * One event as it goes over the wire: the data line, padding as an SSE
     * comment line when the data line is shorter than the threshold, and the
     * blank line that ends the event.
     *
     * @param array<string, mixed> $event
     */
    public static function format(array $event): string
    {
        $data    = 'data: ' . json_encode($event, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        $padding = self::MIN_EVENT_BYTES - strlen($data) - 3;

        return $data . ($padding > 0 ? ':' . str_repeat(' ', $padding) . "\n" : '') . "\n";
    }
}
