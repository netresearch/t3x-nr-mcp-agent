<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Mcp;

use Netresearch\NrMcpAgent\Mcp\McpConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

class McpConnectionTest extends TestCase
{
    #[Test]
    public function isOpenReturnsFalseByDefault(): void
    {
        $connection = new McpConnection();
        self::assertFalse($connection->isOpen());
    }

    #[Test]
    public function closeOnClosedConnectionIsNoop(): void
    {
        $connection = new McpConnection();
        $connection->close();
        self::assertFalse($connection->isOpen());
    }

    #[Test]
    public function openWithInvalidCommandThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        $connection = new McpConnection();
        // Suppress the PHP warning from proc_open when the binary does not exist
        @$connection->open('/nonexistent/binary', [], '/tmp');
    }

    #[Test]
    public function closeIsIdempotent(): void
    {
        $connection = new McpConnection();
        $connection->close();
        $connection->close();
        $connection->close();
        self::assertFalse($connection->isOpen());
    }

    #[Test]
    public function callThrowsWhenNotConnected(): void
    {
        $connection = new McpConnection();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MCP connection not open');

        $connection->call('test/method');
    }

    #[Test]
    public function notifyThrowsWhenNotConnected(): void
    {
        $connection = new McpConnection();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MCP connection not open');

        $connection->notify('test/notification');
    }

    #[Test]
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(McpConnection::class);
        self::assertTrue($reflection->isFinal());
    }

    #[Test]
    public function callWithParamsThrowsWhenNotConnected(): void
    {
        $connection = new McpConnection();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MCP connection not open');
        $connection->call('some/method', ['key' => 'value']);
    }

    #[Test]
    public function notifyWithParamsThrowsWhenNotConnected(): void
    {
        $connection = new McpConnection();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MCP connection not open');
        $connection->notify('some/notification', ['key' => 'value']);
    }

    #[Test]
    public function requestIdStartsAtZero(): void
    {
        $connection = new McpConnection();
        $reflection = new ReflectionClass($connection);

        $requestIdProp = $reflection->getProperty('requestId');
        self::assertSame(0, $requestIdProp->getValue($connection));
    }

    #[Test]
    public function openMethodSignatureAcceptsCommandArgsAndCwd(): void
    {
        $reflection = new ReflectionClass(McpConnection::class);
        $method = $reflection->getMethod('open');

        $params = $method->getParameters();
        self::assertCount(4, $params);
        self::assertSame('command', $params[0]->getName());
        self::assertSame('args', $params[1]->getName());
        self::assertSame('cwd', $params[2]->getName());
        self::assertSame('', $params[2]->getDefaultValue());
        self::assertSame('timeoutSeconds', $params[3]->getName());
        self::assertSame(30.0, $params[3]->getDefaultValue());
    }

    #[Test]
    public function callMethodReturnsArrayType(): void
    {
        $reflection = new ReflectionClass(McpConnection::class);
        $method = $reflection->getMethod('call');
        $returnType = $method->getReturnType();

        self::assertNotNull($returnType);
        self::assertSame('array', $returnType->getName());
    }

    #[Test]
    public function notifyMethodReturnsVoid(): void
    {
        $reflection = new ReflectionClass(McpConnection::class);
        $method = $reflection->getMethod('notify');
        $returnType = $method->getReturnType();

        self::assertNotNull($returnType);
        self::assertSame('void', $returnType->getName());
    }

    #[Test]
    public function closeResetsInitializedFlag(): void
    {
        $connection = new McpConnection();
        $reflection = new ReflectionClass($connection);

        $initializedProp = $reflection->getProperty('initialized');
        self::assertFalse($initializedProp->getValue($connection));

        $connection->close();
        self::assertFalse($initializedProp->getValue($connection));
    }
}
