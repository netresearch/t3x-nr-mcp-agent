<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Exception;

use Exception;
use Netresearch\NrMcpAgent\Enum\SiteConfigurationRefusal;
use Throwable;

/**
 * The site of the conversation's page names an AI configuration the chat
 * cannot run on (ADR-033). The turn stops here: running it on the Task's
 * configuration instead would send the site's content to a provider the site
 * was set up to avoid.
 */
final class SiteConfigurationRefusedException extends Exception
{
    public function __construct(
        public readonly SiteConfigurationRefusal $reason,
        public readonly string $site,
        public readonly string $configuration = '',
        public readonly string $model = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'The AI configuration of site "%s"%s cannot be used by the chat (%s).',
                $site,
                $configuration !== '' ? sprintf(' ("%s")', $configuration) : '',
                $reason->value,
            ),
            1794393601,
            $previous,
        );
    }

    /** The message a refused turn stores beside the reason's code. */
    public function storedMessage(): string
    {
        return SiteConfigurationRefusal::encode($this->site, $this->configuration, $this->model);
    }
}
