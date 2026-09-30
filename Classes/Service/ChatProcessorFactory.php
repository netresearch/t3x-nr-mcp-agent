<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;

/**
 * Builds the ChatProcessorInterface service from `processingStrategy`.
 *
 * Wired as the service's factory in Configuration/Services.yaml. The
 * container calls it when the service is first needed, at runtime, so it
 * sees the extension configuration of the installation, not of the build.
 */
final readonly class ChatProcessorFactory
{
    public const STRATEGY_WORKER = 'worker';

    public function __construct(
        private ExtensionConfiguration $configuration,
        private ExecChatProcessor $execProcessor,
        private WorkerChatProcessor $workerProcessor,
    ) {}

    /**
     * `worker` hands the turn to the long-running `ai-chat:worker`; every
     * other value, `exec` included, keeps the default of one process per turn.
     */
    public function create(): ChatProcessorInterface
    {
        return $this->configuration->getProcessingStrategy() === self::STRATEGY_WORKER
            ? $this->workerProcessor
            : $this->execProcessor;
    }
}
