<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Service\Agent\Inbox\PendingCallView;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;

/**
 * Whether a pending call writes, as nr-llm tells the approval card.
 *
 * nr-llm's view says so per call (`PendingCallView::$declaresWrite`, nr-llm
 * ADR-214, merged with nr-llm PR 1024, first released in 0.41). On an nr-llm
 * without it the effect is nr-llm's own resolution by name. In both an
 * unknown tool counts as a write, and without the resolver every call does.
 */
final class PendingCallEffect
{
    public static function declaresWrite(PendingCallView $call, ?ToolEffectResolver $toolEffects): bool
    {
        if (property_exists($call, 'declaresWrite')) {
            return $call->declaresWrite === true;
        }

        return !$toolEffects instanceof ToolEffectResolver || $toolEffects->effectFor($call->name)->isWrite();
    }
}
