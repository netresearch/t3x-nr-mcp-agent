<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Controller;

use Netresearch\NrLlm\Controller\Backend\AgentRunController;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionParameter;

/**
 * The approval link points at nr-llm's read-only agent run detail.
 *
 * A backend module route resolves whether or not the action behind it is
 * registered, so building the URI succeeds either way and the failure appears
 * only when someone clicks: Extbase rejects the unknown action and the user
 * gets an exception page instead of the approval. That is exactly the
 * impression this feature exists to remove, and no gate in this repository
 * sees it — which is how it shipped once already.
 *
 * nr-llm gained the detail view in 0.29.0 (ADR-153); composer.json,
 * ext_emconf.php and the installation docs name that floor. This asserts the
 * floor buys what the link needs, against the nr-llm that is installed rather
 * than against the constraint that was written down.
 *
 * The action's registration in `nrllm_aitasks` arrived in the same release and
 * is not asserted separately: reading nr-llm's Modules.php means `require`-ing
 * a file for its return value, and the controller method is the half that can
 * be checked without loading anything.
 */
#[CoversNothing]
final class ApprovalLinkTargetTest extends TestCase
{
    #[Test]
    public function nrLlmOffersTheRunDetailTheApprovalLinkPointsAt(): void
    {
        self::assertTrue(
            method_exists(AgentRunController::class, 'showAction'),
            'nr-llm has no AgentRunController::showAction, so the approval link resolves '
            . 'to an action that does not exist. It arrived in nr-llm 0.29.0 — check the '
            . 'floor in composer.json and ext_emconf.php.',
        );

        $action = new ReflectionMethod(AgentRunController::class, 'showAction');
        $parameters = array_map(
            static fn(ReflectionParameter $p): string => $p->getName(),
            $action->getParameters(),
        );

        self::assertContains(
            'runUuid',
            $parameters,
            'showAction no longer takes a runUuid, so the link cannot name a run.',
        );
    }
}
