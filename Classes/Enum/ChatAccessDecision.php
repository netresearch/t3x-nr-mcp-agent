<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Enum;

/** The answer of {@see \Netresearch\NrMcpAgent\Service\ChatAccess::decide()}. */
enum ChatAccessDecision: string
{
    case Allowed           = 'allowed';
    case NoTask            = 'noTask';
    case NotInAllowedGroup = 'notInAllowedGroup';

    public function isAllowed(): bool
    {
        return $this === self::Allowed;
    }
}
