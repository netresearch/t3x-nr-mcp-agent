<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunViewFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The nr-llm side of {@see PendingApprovalReaderInterface}.
 *
 * Deliberately thin: status() answers per run through the actor, and the view
 * factory owns the digest that the resume path verifies (ADR-132). Decoding the
 * suspended state here instead would be a second implementation of that digest,
 * and the two would drift.
 */
final readonly class NrLlmPendingApprovalReader implements PendingApprovalReaderInterface
{
    public function __construct(
        private AgentRuntimeInterface $agentRuntime,
        private WaitingRunViewFactory $waitingRunViewFactory,
    ) {}

    public function read(AiActorContext $actor, string $runUuid): ?WaitingRunView
    {
        if ($runUuid === '') {
            return null;
        }

        $run = $this->agentRuntime->status($actor, $runUuid);
        if ($run === null) {
            return null;
        }

        // The live backend user, when there is one: the factory uses it to say
        // whether the viewer may run the pending call themselves. Absent on the
        // CLI, where the view is still readable, only less specific.
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        $views = $this->waitingRunViewFactory->buildWaiting(
            [$run],
            $backendUser instanceof BackendUserAuthentication ? $backendUser : null,
        );

        return $views[0] ?? null;
    }
}
