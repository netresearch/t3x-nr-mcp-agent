<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Enum;

/**
 * Why the reader did not take a proposed change, chosen on the approval card
 * (ADR-018): another variant, or skip this point.
 *
 * Kept with the decision so the worker can hand it to nr-llm once nr-llm's
 * approval decision carries a reason; until then the denial is a plain one
 * and the reason reaches the model only through the transcript line the card
 * adds.
 */
enum DenyReason: string
{
    case Variant = 'variant';
    case Skip = 'skip';

    /** The key of the button label in locallang_chat.xlf, also the transcript line. */
    public function labelKey(): string
    {
        return match ($this) {
            self::Variant => 'chat.approvalVariant',
            self::Skip => 'chat.approvalSkip',
        };
    }
}
