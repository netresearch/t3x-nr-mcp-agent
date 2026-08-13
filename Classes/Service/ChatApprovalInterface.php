<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;

/**
 * Reading and deciding the approval a conversation is parked on.
 *
 * Separate from ChatCapabilitiesInterface because the controller needs exactly
 * these two operations and nothing else of the chat service, and because a
 * narrow seam is easier to stub in a test than the whole service.
 */
interface ChatApprovalInterface
{
    /**
     * The pending tool call, as the approvals inbox would show it, or null when
     * nothing is pending or the actor may not read the run.
     */
    public function pendingApproval(Conversation $conversation): ?WaitingRunView;

    /**
     * Decide the pending call and carry the run to its end, writing the outcome
     * onto the conversation.
     *
     * A no-op when the conversation is not waiting: a decision on a run that has
     * already moved on is not an error to report, it is a click that arrived
     * late.
     */
    public function decideApproval(Conversation $conversation, bool $approve, string $turnDigest): void;
}
