<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Enum;

use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Service\Agent\Exception\ApproverNotPermittedException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAlreadyResumingException;
use Netresearch\NrLlm\Service\Agent\Exception\RunConfigurationInactiveException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingApprovalException;
use Netresearch\NrLlm\Service\Agent\Exception\SelfApprovalDeniedException;
use Netresearch\NrLlm\Service\Agent\Exception\StaleApprovalTurnException;

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

    /**
     * An administrator switched the pending tool off while the run waited. Not
     * the reader's permissions: nobody can approve it until the tool is on again.
     */
    case ToolDisabled = 'handBack.toolDisabled';

    /** The run's LLM configuration was deactivated while it waited. */
    case ConfigurationInactive = 'handBack.configurationInactive';

    /** The run no longer waits for a decision. */
    case NotAwaitingApproval = 'handBack.notAwaitingApproval';

    /**
     * Four-eyes (nr-llm ADR-172): the run's configuration requires a second
     * person, and the reader started the run.
     */
    case SecondApproverRequired = 'handBack.secondApprover';

    /**
     * Whether the run is a guided process could not be checked; nothing was
     * decided and the same decision can be tried again (ProcessRefusal).
     */
    case VerdictUnavailable = 'handBack.verdictUnavailable';

    /** Only the person who started the guided process may decide it (ProcessRefusal). */
    case DecidedInChat = 'handBack.decidedInChat';

    /**
     * Only the six refusals that RELEASE the run are hand-backs; ChatService
     * catches exactly these. The refusals of a guided process that leave the
     * run waiting map through {@see ProcessRefusal::approvalHandBack()}. Any other exception leaves the run's state
     * unknown and fails the conversation instead, so there is no generic
     * hand-back reason to map it to.
     */
    public static function fromException(
        StaleApprovalTurnException|RunAlreadyResumingException|ApproverNotPermittedException|RunConfigurationInactiveException|RunNotAwaitingApprovalException|SelfApprovalDeniedException $e,
    ): self {
        return match (true) {
            $e instanceof StaleApprovalTurnException        => self::StaleTurn,
            $e instanceof RunAlreadyResumingException       => self::AlreadyResuming,
            $e instanceof ApproverNotPermittedException     => self::forApproverRefusal(self::denialReasonOf($e)),
            $e instanceof RunConfigurationInactiveException => self::ConfigurationInactive,
            $e instanceof SelfApprovalDeniedException       => self::SecondApproverRequired,
            default                                         => self::NotAwaitingApproval,
        };
    }

    /**
     * The approver gate's refusal, by the tool policy's reason: a switched-off
     * tool gets its own sentence, every other reason — and no reason at all —
     * the permissions sentence.
     */
    public static function forApproverRefusal(?ToolDenialReason $reason): self
    {
        return $reason === ToolDenialReason::TOOL_DISABLED ? self::ToolDisabled : self::ApproverNotPermitted;
    }

    /**
     * The tool policy's reason behind an approver or submitter refusal, or null
     * where nr-llm does not say. nr-llm exposes it as getDenialReason() from the
     * release that ships t3x-nr-llm#1179 (0.41.5, the required floor); up to 0.41.4 the
     * reason is only in the message, which is not parsed. {@see InputHandBackReason} uses it too. Duck-typed on `object` so the check holds
     * against both versions without a version switch.
     */
    public static function denialReasonOf(object $refusal): ?ToolDenialReason
    {
        if (!method_exists($refusal, 'getDenialReason')) {
            return null;
        }

        $reason = $refusal->getDenialReason();

        return $reason instanceof ToolDenialReason ? $reason : null;
    }

    /**
     * Whether the sentence sends the reader to the run in AI Tasks, where the
     * answer or the run's state now is; the card then offers the link.
     */
    public function pointsToRun(): bool
    {
        return in_array($this, [self::AlreadyResuming, self::NotAwaitingApproval, self::SecondApproverRequired], true);
    }

    /** The key of the reader-facing sentence in locallang_chat.xlf. */
    public function labelKey(): string
    {
        return 'error.' . $this->value;
    }
}
