<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Enum;

use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EnumTest extends TestCase
{
    #[Test]
    public function conversationStatusHasAllExpectedCases(): void
    {
        $expected = ['idle', 'processing', 'locked', 'tool_loop', 'failed'];
        $actual = array_map(fn(ConversationStatus $s) => $s->value, ConversationStatus::cases());
        self::assertSame($expected, $actual);
    }

    #[Test]
    public function messageRoleHasAllExpectedCases(): void
    {
        $expected = ['user', 'assistant', 'tool'];
        $actual = array_map(fn(MessageRole $r) => $r->value, MessageRole::cases());
        self::assertSame($expected, $actual);
    }

    #[Test]
    public function conversationStatusCanBeCreatedFromString(): void
    {
        self::assertSame(ConversationStatus::Processing, ConversationStatus::from('processing'));
    }
}
