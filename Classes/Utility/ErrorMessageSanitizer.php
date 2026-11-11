<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Utility;

use Throwable;

use function is_string;
use function method_exists;

final class ErrorMessageSanitizer
{
    /**
     * The message of an exception as it may be stored: an exception that
     * offers a storable form gives that one. nr-llm's guardrail refusal does
     * (nr-llm ADR-288): its message quotes the passage of the refused text,
     * for the person looking at the screen, and its storable form leaves the
     * passage out. A stored conversation outlives the request and does not
     * follow the run's privacy level (nr-llm ADR-064).
     *
     * Read by its shape: nr-llm releases before ADR-288 have no such method.
     */
    public static function sanitizeException(Throwable $e, int $maxLength = 500): string
    {
        $storable = method_exists($e, 'storableMessage') ? $e->storableMessage() : null;

        return self::sanitize(is_string($storable) ? $storable : $e->getMessage(), $maxLength);
    }

    public static function sanitize(string $message, int $maxLength = 500): string
    {
        $clean = preg_replace('/Bearer\s+[A-Z0-9\-._~+\/]+=*/i', 'Bearer [REDACTED]', $message) ?? $message;
        $clean = preg_replace('/\b(key|sk|api[_-]?key|token)[_-][A-Z0-9\-._]{8,}\b/i', '[REDACTED]', $clean) ?? $clean;
        $clean = preg_replace('#https?://[^\s]+#', '[URL]', $clean) ?? $clean;

        return mb_strlen($clean) > $maxLength ? mb_substr($clean, 0, $maxLength - 1) . "\u{2026}" : $clean;
    }
}
