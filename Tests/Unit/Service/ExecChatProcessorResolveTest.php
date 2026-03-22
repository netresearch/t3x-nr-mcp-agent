<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrMcpAgent\Service\ExecChatProcessor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Targets mutation survivors in ExecChatProcessor::resolvePhpCliBinary() (line 42).
 *
 * The private method is tested via reflection. When PHP_SAPI is 'cli' (always
 * true in unit tests), the method must return PHP_BINARY without checking
 * candidate paths — killing the LogicalAnd mutations on the SAPI check.
 */
class ExecChatProcessorResolveTest extends TestCase
{
    #[Test]
    public function resolvePhpCliBinaryReturnsPHPBinaryWhenSapiIsCli(): void
    {
        // In unit test context, PHP_SAPI is always 'cli'
        self::assertSame('cli', PHP_SAPI);

        $processor = new ExecChatProcessor();
        $reflection = new ReflectionClass($processor);
        $method = $reflection->getMethod('resolvePhpCliBinary');
        $method->setAccessible(true);

        $result = $method->invoke($processor);

        // When not running as fpm/cgi, must return PHP_BINARY directly
        self::assertSame(PHP_BINARY, $result);
    }

    #[Test]
    public function resolvePhpCliBinaryReturnsString(): void
    {
        $processor = new ExecChatProcessor();
        $reflection = new ReflectionClass($processor);
        $method = $reflection->getMethod('resolvePhpCliBinary');
        $method->setAccessible(true);

        $result = $method->invoke($processor);

        self::assertIsString($result);
        self::assertNotEmpty($result);
    }
}
