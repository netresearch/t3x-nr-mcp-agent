<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Domain\Enum\ServiceAccountScope;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunViewFactory;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The nr-llm side of {@see PendingApprovalReaderInterface}.
 *
 * Reads the row through the repository, not through AgentRuntime::status().
 * status() answers with withoutSuspendedState() by design (ADR-101): the raw
 * suspended state bypasses the privacy filter that every persisted event goes
 * through, so a status projection must not carry it. Handing that stripped run
 * to the view factory yields `unreadable` every single time — the card would
 * never render and no digest would ever be obtainable. nr-llm's own approvals
 * inbox reads the same way, through the persister.
 *
 * status() also performed the per-run authorisation, so with it gone the check
 * is done here explicitly, with the scope the inbox uses for the same read.
 *
 * The view factory owns the digest the resume path verifies (ADR-132), which is
 * why the state is decoded there and not here: a second implementation would
 * drift from the one that decides whether a decision is stale.
 */
final readonly class NrLlmPendingApprovalReader implements PendingApprovalReaderInterface
{
    public function __construct(
        private AgentRunRepositoryInterface $agentRunRepository,
        private WaitingRunViewFactory $waitingRunViewFactory,
    ) {}

    public function read(AiActorContext $actor, string $runUuid): ?WaitingRunView
    {
        if ($runUuid === '') {
            return null;
        }

        $run = $this->agentRunRepository->findByUuid($runUuid);
        if (!$run instanceof AgentRun || !$actor->mayActOnRun($run, ServiceAccountScope::AGENT_READ)) {
            // A run the actor may not read and one that does not exist answer
            // alike — knowing a uuid is never enough.
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
