<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;

/**
 * Reads the pending tool call of a suspended run.
 *
 * A seam of this extension's own, for one reason: nr-llm's WaitingRunViewFactory
 * is final, so a test cannot double it, and building a real one means building
 * its three collaborators as well. Everything behind this interface is nr-llm's;
 * the interface exists so the chat service can be tested without it.
 */
interface PendingApprovalReaderInterface
{
    /**
     * The pending call as the approvals inbox describes it, or null when the run
     * does not exist, is not suspended, or the actor may not read it — the three
     * are deliberately indistinguishable from the outside.
     */
    public function read(AiActorContext $actor, string $runUuid): ?WaitingRunView;
}
