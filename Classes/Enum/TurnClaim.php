<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Enum;

/**
 * How the claim of a conversation for a new turn ended
 * (ConversationRepository::claimTurn()).
 */
enum TurnClaim
{
    /** The row is written with the new status. */
    case Claimed;

    /** The row no longer had the expected status; nothing is written. */
    case Lost;

    /** The user already runs as many conversations as allowed; nothing is written. */
    case OverLimit;
}
