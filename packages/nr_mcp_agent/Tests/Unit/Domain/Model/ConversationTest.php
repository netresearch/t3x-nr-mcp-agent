<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Domain\Model;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConversationTest extends TestCase
{
    #[Test]
    public function newConversationHasIdleStatus(): void
    {
        $conversation = new Conversation();
        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
    }

    #[Test]
    public function appendMessageIncreasesCount(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage('user', 'Hello');

        self::assertSame(1, $conversation->getMessageCount());
    }

    #[Test]
    public function appendMessageAddsToMessages(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage('user', 'Hello');

        $messages = $conversation->getDecodedMessages();
        self::assertCount(1, $messages);
        self::assertSame('user', $messages[0]['role']);
        self::assertSame('Hello', $messages[0]['content']);
    }

    #[Test]
    public function getDecodedMessagesReturnsEmptyArrayForNewConversation(): void
    {
        $conversation = new Conversation();
        self::assertSame([], $conversation->getDecodedMessages());
    }

    #[Test]
    public function appendAssistantMessageWithToolUse(): void
    {
        $conversation = new Conversation();
        $content = [
            ['type' => 'text', 'text' => 'I will translate that.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'translate', 'input' => ['page' => 5]],
        ];
        $conversation->appendMessage('assistant', $content);

        $messages = $conversation->getDecodedMessages();
        self::assertSame($content, $messages[0]['content']);
    }

    #[Test]
    public function autoTitleUsesFirstUserMessage(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage('user', 'Übersetze Seite 5 ins Englische');

        self::assertSame('Übersetze Seite 5 ins Englische', $conversation->getTitle());
    }

    #[Test]
    public function autoTitleTruncatesLongMessages(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage('user', str_repeat('a', 300));

        self::assertSame(255, mb_strlen($conversation->getTitle()));
    }

    #[Test]
    public function autoTitleDoesNotOverwriteExistingTitle(): void
    {
        $conversation = new Conversation();
        $conversation->setTitle('My custom title');
        $conversation->appendMessage('user', 'Hello');

        self::assertSame('My custom title', $conversation->getTitle());
    }

    #[Test]
    public function isResumableReturnsTrueForProcessingStatus(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Processing);

        self::assertTrue($conversation->isResumable());
    }

    #[Test]
    public function isResumableReturnsTrueForToolLoopStatus(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::ToolLoop);

        self::assertTrue($conversation->isResumable());
    }

    #[Test]
    public function isResumableReturnsTrueForFailedStatus(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Failed);

        self::assertTrue($conversation->isResumable());
    }

    #[Test]
    public function isResumableReturnsFalseForIdleStatus(): void
    {
        $conversation = new Conversation();
        self::assertFalse($conversation->isResumable());
    }

    #[Test]
    public function isResumableReturnsFalseForLockedStatus(): void
    {
        $conversation = new Conversation();
        $conversation->setStatus(ConversationStatus::Locked);

        self::assertFalse($conversation->isResumable());
    }
}
