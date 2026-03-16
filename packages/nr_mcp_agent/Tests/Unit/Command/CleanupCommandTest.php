<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Command;

use Netresearch\NrMcpAgent\Command\CleanupCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;

class CleanupCommandTest extends TestCase
{
    #[Test]
    public function classHasAsCommandAttribute(): void
    {
        $reflection = new ReflectionClass(CleanupCommand::class);
        $attributes = $reflection->getAttributes(AsCommand::class);

        self::assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        self::assertSame('ai-chat:cleanup', $instance->name);
        self::assertSame('Clean up stuck, inactive and archived conversations', $instance->description);
    }

    #[Test]
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(CleanupCommand::class);
        self::assertTrue($reflection->isFinal());
    }

    #[Test]
    public function constructorRequiresConnectionPoolAndExtensionConfiguration(): void
    {
        $reflection = new ReflectionClass(CleanupCommand::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor);
        $parameters = $constructor->getParameters();
        self::assertCount(2, $parameters);
        self::assertSame('connectionPool', $parameters[0]->getName());
        self::assertSame('extensionConfiguration', $parameters[1]->getName());
    }
}
