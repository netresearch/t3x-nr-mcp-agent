<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Mcp;

use Netresearch\NrMcpAgent\Mcp\McpConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
        $connection->open('/nonexistent/binary', [], '/tmp');
    }
}
