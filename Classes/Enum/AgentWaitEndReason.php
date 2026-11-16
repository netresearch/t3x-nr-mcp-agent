<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Enum;

/**
 * Why a wait for a remote agent (nr-llm ADR-281) ended in a failure, as the
 * conversation's error code. The reader gets a sentence from the chat's
 * language file, in their language, as for the hand-back reasons.
 */
enum AgentWaitEndReason: string
{
    /** At most 32 characters, like every error code: error_code is a varchar(32). */
    case RunFailed = 'agentWait.runFailed';

    /** The run is gone or may not be read any more, e.g. purged by nr-llm's retention. */
    case RunGone = 'agentWait.runGone';

    /** The key of the reader-facing sentence in locallang_chat.xlf. */
    public function labelKey(): string
    {
        return 'error.' . $this->value;
    }
}
