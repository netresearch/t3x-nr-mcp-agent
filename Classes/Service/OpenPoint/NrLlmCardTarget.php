<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\OpenPoint;

use Netresearch\NrLlm\Domain\ValueObject\PendingWriteTarget;
use Netresearch\NrLlm\Service\Agent\Inbox\PendingCallView;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;

/**
 * The target of the one write a card proposes, read from nr-llm's view:
 * `PendingCallView::$pendingTarget` (nr-llm ADR-214, merged with nr-llm PR
 * 1024, first released in 0.41). Nothing here parses the preview's prose.
 *
 * Null when the card holds more or fewer than one pending call, when the call
 * names no target (it creates its record, or its tool does not say), and on
 * an nr-llm whose view carries no target.
 */
final class NrLlmCardTarget
{
    public static function of(WaitingRunView $view): ?OpenPointTarget
    {
        if (count($view->pendingCalls) !== 1) {
            return null;
        }

        return self::ofCall($view->pendingCalls[0]);
    }

    public static function ofCall(PendingCallView $call): ?OpenPointTarget
    {
        if (!property_exists($call, 'pendingTarget')) {
            return null;
        }

        $target = $call->pendingTarget;
        if (!$target instanceof PendingWriteTarget) {
            return null;
        }

        return new OpenPointTarget($target->record->table, $target->record->uid, $target->fields);
    }
}
