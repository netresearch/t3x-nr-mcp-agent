<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Controller;

use Closure;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * A repository that lets a competing claim run each time the user's running
 * conversations have been counted, as a simultaneous request would.
 */
readonly class CountThenCompeteConversationRepository extends ConversationRepository
{
    public function __construct(ConnectionPool $connectionPool, private Closure $competitor)
    {
        parent::__construct($connectionPool);
    }

    public function countActiveByBeUser(int $beUserUid): int
    {
        $count = parent::countActiveByBeUser($beUserUid);
        ($this->competitor)();

        return $count;
    }
}
