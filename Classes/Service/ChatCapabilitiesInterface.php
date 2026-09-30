<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

interface ChatCapabilitiesInterface
{
    /**
     * @return array{visionSupported: bool, maxFileSize: int, supportedFormats: list<string>}
     */
    public function getProviderCapabilities(): array;
}
