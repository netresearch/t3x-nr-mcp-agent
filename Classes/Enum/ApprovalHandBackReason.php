<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Enum;

use Netresearch\NrLlm\Service\Agent\Exception\ApproverNotPermittedException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAlreadyResumingException;
use Netresearch\NrLlm\Service\Agent\Exception\RunConfigurationInactiveException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingApprovalException;
use Netresearch\NrLlm\Service\Agent\Exception\StaleApprovalTurnException;
use Throwable;

/**
 * Why nr-llm handed a decided run back still pending, as the conversation's
 * error code.
 *
 * nr-llm's exception messages are English sentences for developers and may
 * name internals, so the chat never shows them: the reason is stored as this
 * code and rendered per reader from the chat's language file (editorial rule
 * 27: what happened, and what the reader can do now). The exception itself
 * goes to the log.
 */
enum ApprovalHandBackReason: string
{
    /** The pending turn changed after the card was rendered; its digest no longer matches. */
    case StaleTurn = 'handBack.staleTurn';

    /** Another decision on the same run is already being carried out. */
    case AlreadyResuming = 'handBack.alreadyResuming';

    /** The reader is not allowed to decide this run. */
    case ApproverNotPermitted = 'handBack.approverNotPermitted';

    /** The run's LLM configuration was deactivated while it waited. */
    case ConfigurationInactive = 'handBack.configurationInactive';

    /** The run no longer waits for a decision. */
    case NotAwaitingApproval = 'handBack.notAwaitingApproval';

    /** Any other reason; the generic wording. */
    case Unknown = 'handBack.unknown';

    public static function fromException(Throwable $e): self
    {
        return match (true) {
            $e instanceof StaleApprovalTurnException        => self::StaleTurn,
            $e instanceof RunAlreadyResumingException       => self::AlreadyResuming,
            $e instanceof ApproverNotPermittedException     => self::ApproverNotPermitted,
            $e instanceof RunConfigurationInactiveException => self::ConfigurationInactive,
            $e instanceof RunNotAwaitingApprovalException   => self::NotAwaitingApproval,
            default                                         => self::Unknown,
        };
    }

    /** The key of the reader-facing sentence in locallang_chat.xlf. */
    public function labelKey(): string
    {
        return 'error.' . $this->value;
    }
}
