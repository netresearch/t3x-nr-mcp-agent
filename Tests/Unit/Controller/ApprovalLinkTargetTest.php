<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The approval link points into nr-llm's AI Tasks module.
 *
 * A backend module route resolves whether or not the action behind it is
 * registered, so building the URI succeeds either way and the failure only
 * appears when someone clicks: Extbase rejects the unknown action and the user
 * gets an exception page instead of the approval. That is precisely the
 * impression this whole feature exists to avoid.
 *
 * nr-llm gained the read-only run detail in 0.29.0 (ADR-153). composer.json,
 * ext_emconf.php and the installation docs all name that floor; this asserts
 * the floor buys what the link needs, against the nr-llm that is installed
 * rather than against the constraint that was written down.
 */
#[CoversNothing]
final class ApprovalLinkTargetTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../.Build/vendor/netresearch/nr-llm/Configuration/Backend/Modules.php';

    #[Test]
    public function nrLlmRegistersTheRunDetailTheApprovalLinkPointsAt(): void
    {
        if (!is_file(self::MODULES)) {
            self::markTestSkipped('nr-llm is not installed in this build.');
        }

        $modules = require self::MODULES;

        self::assertIsArray($modules);
        self::assertArrayHasKey('nrllm_aitasks', $modules, 'The module the link targets is gone.');

        $actions = [];
        foreach ($modules['nrllm_aitasks']['controllerActions'] ?? [] as $controller => $registered) {
            if (str_ends_with((string) $controller, '\\AgentRunController')) {
                $actions = $registered;
            }
        }

        self::assertContains(
            'show',
            $actions,
            'nr-llm no longer registers AgentRunController::showAction for nrllm_aitasks, '
            . 'so the approval link would resolve to an unknown action. Raise the nr-llm '
            . 'floor or point the link somewhere that exists.',
        );
    }
}
