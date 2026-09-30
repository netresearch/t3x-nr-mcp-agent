<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Service\ExecChatProcessor;
use Netresearch\NrMcpAgent\Service\Typo3CliBinaryResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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

        $processor = $this->processor();
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
        $processor = $this->processor();
        $reflection = new ReflectionClass($processor);
        $method = $reflection->getMethod('resolvePhpCliBinary');
        $method->setAccessible(true);

        $result = $method->invoke($processor);

        self::assertIsString($result);
        self::assertNotEmpty($result);
    }

    private function processor(): ExecChatProcessor
    {
        return new ExecChatProcessor(
            $this->createMock(Typo3CliBinaryResolver::class),
            $this->createMock(ConversationRepository::class),
            $this->createMock(LoggerInterface::class),
        );
    }
}
