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

    #[Test]
    public function conversationStatusTryFromWithValidValues(): void
    {
        self::assertSame(ConversationStatus::Idle, ConversationStatus::tryFrom('idle'));
        self::assertSame(ConversationStatus::Processing, ConversationStatus::tryFrom('processing'));
        self::assertSame(ConversationStatus::Locked, ConversationStatus::tryFrom('locked'));
        self::assertSame(ConversationStatus::ToolLoop, ConversationStatus::tryFrom('tool_loop'));
        self::assertSame(ConversationStatus::Failed, ConversationStatus::tryFrom('failed'));
    }

    #[Test]
    public function conversationStatusTryFromWithInvalidValueReturnsNull(): void
    {
        self::assertNull(ConversationStatus::tryFrom('nonexistent'));
        self::assertNull(ConversationStatus::tryFrom(''));
        self::assertNull(ConversationStatus::tryFrom('IDLE'));
    }

    #[Test]
    public function messageRoleValueProperty(): void
    {
        self::assertSame('user', MessageRole::User->value);
        self::assertSame('assistant', MessageRole::Assistant->value);
        self::assertSame('tool', MessageRole::Tool->value);
    }

    #[Test]
    public function messageRoleTryFromWithValidValues(): void
    {
        self::assertSame(MessageRole::User, MessageRole::tryFrom('user'));
        self::assertSame(MessageRole::Assistant, MessageRole::tryFrom('assistant'));
        self::assertSame(MessageRole::Tool, MessageRole::tryFrom('tool'));
    }

    #[Test]
    public function messageRoleTryFromWithInvalidValueReturnsNull(): void
    {
        self::assertNull(MessageRole::tryFrom('system'));
        self::assertNull(MessageRole::tryFrom(''));
        self::assertNull(MessageRole::tryFrom('USER'));
    }

    #[Test]
    public function conversationStatusValueProperty(): void
    {
        self::assertSame('idle', ConversationStatus::Idle->value);
        self::assertSame('processing', ConversationStatus::Processing->value);
        self::assertSame('locked', ConversationStatus::Locked->value);
        self::assertSame('tool_loop', ConversationStatus::ToolLoop->value);
        self::assertSame('failed', ConversationStatus::Failed->value);
    }
}
