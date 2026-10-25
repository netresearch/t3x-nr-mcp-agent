<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Enum;

use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Service\Agent\Exception\InvalidInputSubmissionException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAlreadyResumingException;
use Netresearch\NrLlm\Service\Agent\Exception\RunConfigurationInactiveException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingInputException;
use Netresearch\NrLlm\Service\Agent\Exception\StaleInputTurnException;
use Netresearch\NrLlm\Service\Agent\Exception\SubmitterNotPermittedException;

/**
 * Why nr-llm handed an answered question back still open, as the
 * conversation's error code (ADR-018).
 *
 * The sibling of {@see ApprovalHandBackReason}, for the same reason it exists:
 * nr-llm's messages are developer sentences and go to the log, the reader gets
 * a sentence from the chat's language file. Kept apart rather than widened,
 * because the refusals that release an input pause are not the ones that
 * release an approval (nr-llm ADR-105, ADR-150).
 */
enum InputHandBackReason: string
{
    /** The question changed after it was shown; the answer was given to an older one. */
    case StaleTurn = 'handBack.inputStale';

    /** The answer does not fit the question's schema; the run still waits. */
    case InvalidInput = 'handBack.inputInvalid';

    /** The user may not run the step the question belongs to. */
    case SubmitterNotPermitted = 'handBack.inputNotPermitted';

    /**
     * An administrator switched off the tool the question belongs to while the
     * run waited. Not the user's permissions: nobody can answer until it is on
     * again.
     */
    case ToolDisabled = 'handBack.inputToolOff';

    /** Another answer to the same question is already being carried out. */
    case AlreadyResuming = 'handBack.inputAlreadyResuming';

    /** The run's LLM configuration was deactivated while it waited. */
    /** At most 32 characters, like every value here: error_code is a varchar(32). */
    case ConfigurationInactive = 'handBack.inputConfigOff';

    /** The run no longer waits for an answer. */
    case NotAwaitingInput = 'handBack.notAwaitingInput';

    /** Whether the run is a guided process could not be checked; the answer can be sent again (ProcessRefusal). */
    case VerdictUnavailable = 'handBack.inputVerdictOpen';

    /** Only the person who started the guided process may answer it (ProcessRefusal). */
    case DecidedInChat = 'handBack.inputDecidedInChat';

    /**
     * Only the refusals that leave the run waiting for its answer are
     * hand-backs; ChatService catches exactly these. The refusals of a guided
     * process that leave the run waiting map through
     * {@see ProcessRefusal::inputHandBack()}. Anything else fails the
     * conversation, as for an approval.
     */
    public static function fromException(
        StaleInputTurnException|InvalidInputSubmissionException|SubmitterNotPermittedException|RunAlreadyResumingException|RunConfigurationInactiveException|RunNotAwaitingInputException $e,
    ): self {
        return match (true) {
            $e instanceof StaleInputTurnException           => self::StaleTurn,
            $e instanceof InvalidInputSubmissionException   => self::InvalidInput,
            $e instanceof SubmitterNotPermittedException    => self::forSubmitterRefusal(ApprovalHandBackReason::denialReasonOf($e)),
            $e instanceof RunAlreadyResumingException       => self::AlreadyResuming,
            $e instanceof RunConfigurationInactiveException => self::ConfigurationInactive,
            default                                         => self::NotAwaitingInput,
        };
    }

    /**
     * The submitter gate's refusal, by the tool policy's reason: a switched-off
     * tool gets its own sentence, every other reason — and no reason at all —
     * the permissions sentence. The reason is read through the same bridge as
     * for an approval, {@see ApprovalHandBackReason::denialReasonOf()}.
     */
    public static function forSubmitterRefusal(?ToolDenialReason $reason): self
    {
        return $reason === ToolDenialReason::TOOL_DISABLED ? self::ToolDisabled : self::SubmitterNotPermitted;
    }

    /** The key of the reader-facing sentence in locallang_chat.xlf. */
    public function labelKey(): string
    {
        return 'error.' . $this->value;
    }
}
