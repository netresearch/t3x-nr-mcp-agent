<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\ChatTask;

/**
 * Why the chat cannot run for an actor: the step of Task → Configuration that
 * failed (see {@see ChatTaskResolver}).
 */
enum ChatTaskProblem: string
{
    case TaskMissing             = 'taskMissing';
    case ConfigurationMissing    = 'configurationMissing';
    case ConfigurationDisabled   = 'configurationDisabled';
    case ConfigurationRestricted = 'configurationRestricted';
}
