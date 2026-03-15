<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Controller;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Controller\ChatModuleController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionParameter;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * ChatModuleController relies on ModuleTemplateFactory + ModuleTemplate,
 * both of which are final in TYPO3 13/14. Only PageRenderer (not final) can
 * be verified in a unit test. Full module rendering requires a functional test.
 */
class ChatModuleControllerTest extends TestCase
{
    #[Test]
    public function controllerClassExists(): void
    {
        self::assertTrue(class_exists(ChatModuleController::class));
    }

    #[Test]
    public function constructorAcceptsPageRenderer(): void
    {
        $ref = new ReflectionClass(ChatModuleController::class);
        $params = $ref->getConstructor()?->getParameters() ?? [];

        $types = array_map(
            static fn(ReflectionParameter $p): string => (string) $p->getType(),
            $params,
        );

        self::assertContains(PageRenderer::class, $types);
    }

    #[Test]
    public function constructorAcceptsExtensionConfiguration(): void
    {
        $ref = new ReflectionClass(ChatModuleController::class);
        $params = $ref->getConstructor()?->getParameters() ?? [];

        $types = array_map(
            static fn(ReflectionParameter $p): string => (string) $p->getType(),
            $params,
        );

        self::assertContains(ExtensionConfiguration::class, $types);
    }

    #[Test]
    public function controllerIsFinalAndReadonly(): void
    {
        $ref = new ReflectionClass(ChatModuleController::class);
        self::assertTrue($ref->isFinal());
        self::assertTrue($ref->isReadonly());
    }
}
