<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Utility;

final class ErrorMessageSanitizer
{
    public static function sanitize(string $message, int $maxLength = 500): string
    {
        $clean = preg_replace('/Bearer\s+[A-Z0-9\-._~+\/]+=*/i', 'Bearer [REDACTED]', $message) ?? $message;
        $clean = preg_replace('/\b(key|sk|api[_-]?key|token)[_-][A-Z0-9\-._]{8,}\b/i', '[REDACTED]', $clean) ?? $clean;
        $clean = preg_replace('#https?://[^\s]+#', '[URL]', $clean) ?? $clean;
        return mb_strlen($clean) > $maxLength ? mb_substr($clean, 0, $maxLength - 1) . "\u{2026}" : $clean;
    }
}
