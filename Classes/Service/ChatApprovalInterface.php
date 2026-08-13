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
     * Record what the user decided and claim the conversation for the worker.
     *
     * The decision is not carried out here: approve() drives the whole
     * continuation, and doing that in a web request means a gateway timeout can
     * kill it with the write already done and nothing written back.
     *
     * Returns false when there was nothing to decide or the claim was lost to
     * another writer — a click that arrived late is not an error to report.
     */
    public function recordDecision(Conversation $conversation, bool $approve, string $turnDigest): bool;

    /**
     * Put a claimed conversation back in step with the run it waits on, for the
     * case where the worker never picked the decision up.
     *
     * Returns true when the conversation was changed.
     */
    public function reconcile(Conversation $conversation): bool;
}
