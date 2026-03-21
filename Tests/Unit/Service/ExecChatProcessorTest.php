<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use Netresearch\NrMcpAgent\Service\ExecChatProcessor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ExecChatProcessorTest extends TestCase
{
    #[Test]
    public function implementsChatProcessorInterface(): void
    {
        $reflection = new ReflectionClass(ExecChatProcessor::class);
        self::assertTrue($reflection->implementsInterface(ChatProcessorInterface::class));
    }

    #[Test]
    public function classIsFinalAndReadonly(): void
    {
        $reflection = new ReflectionClass(ExecChatProcessor::class);
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadonly());
    }

    #[Test]
    public function dispatchMethodExistsWithCorrectSignature(): void
    {
        $reflection = new ReflectionClass(ExecChatProcessor::class);
        self::assertTrue($reflection->hasMethod('dispatch'));

        $method = $reflection->getMethod('dispatch');
        self::assertTrue($method->isPublic());

        $parameters = $method->getParameters();
        self::assertCount(1, $parameters);
        self::assertSame('conversationUid', $parameters[0]->getName());
        self::assertSame('int', $parameters[0]->getType()?->getName());

        $returnType = $method->getReturnType();
        self::assertNotNull($returnType);
        self::assertSame('void', $returnType->getName());
    }

    #[Test]
    public function constructorHasNoDependencies(): void
    {
        $reflection = new ReflectionClass(ExecChatProcessor::class);
        $constructor = $reflection->getConstructor();

        // readonly class with no constructor or empty constructor
        self::assertTrue($constructor === null || $constructor->getNumberOfParameters() === 0);
    }
}
