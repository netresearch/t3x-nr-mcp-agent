<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Exception;

/**
 * The TYPO3 console entry point (`typo3`) the background worker is started
 * with does not exist where the installation says it should.
 */
final class Typo3CliBinaryNotFoundException extends Exception {}
