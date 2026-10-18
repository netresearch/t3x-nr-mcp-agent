<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Enum;

/**
 * Why nr-llm stopped the run of a guided process when a decision was taken
 * on it (nr-llm ADR-214 item 6), as the conversation's error code.
 *
 * The sibling of the hand-back reasons for a run that does not wait any
 * longer: the conversation is idle again and usable, and the reader gets a
 * sentence from the chat's language file instead of nr-llm's message.
 */
enum ProcessStopReason: string
{
    /** At most 32 characters, like every error code: error_code is a varchar(32). */
    case NeedsSecondApprover = 'process.needsSecondApprover';

    /** The key of the reader-facing sentence in locallang_chat.xlf. */
    public function labelKey(): string
    {
        return 'error.' . $this->value;
    }
}
