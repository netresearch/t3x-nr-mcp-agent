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

    #[Test]
    public function setMessagesUpdatesMessageCount(): void
    {
        $conversation = new Conversation();
        $conversation->setMessages([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi'],
            ['role' => 'user', 'content' => 'How are you?'],
        ]);
        self::assertSame(3, $conversation->getMessageCount());
    }

    #[Test]
    public function setSystemPromptTruncatesAt10000Chars(): void
    {
        $conversation = new Conversation();
        $longPrompt = str_repeat('a', 10001);
        $conversation->setSystemPrompt($longPrompt);
        self::assertSame(10000, mb_strlen($conversation->getSystemPrompt()));
    }

    #[Test]
    public function setSystemPromptKeepsExact10000Chars(): void
    {
        $conversation = new Conversation();
        $exactPrompt = str_repeat('b', 10000);
        $conversation->setSystemPrompt($exactPrompt);
        self::assertSame(10000, mb_strlen($conversation->getSystemPrompt()));
        self::assertSame($exactPrompt, $conversation->getSystemPrompt());
    }

    #[Test]
    public function fromRowHydratesAllFields(): void
    {
        $row = [
            'uid' => 42,
            'be_user' => 7,
            'title' => 'Test Title',
            'messages' => '[]',
            'message_count' => 0,
            'status' => 'processing',
            'current_request_id' => 'req_123',
            'system_prompt' => 'You are helpful',
            'archived' => 1,
            'pinned' => 1,
            'error_message' => 'Some error',
            'tstamp' => 1700000000,
            'crdate' => 1699000000,
        ];
        $conversation = Conversation::fromRow($row);

        self::assertSame(42, $conversation->getUid());
        self::assertSame(7, $conversation->getBeUser());
        self::assertSame('Test Title', $conversation->getTitle());
        self::assertSame(ConversationStatus::Processing, $conversation->getStatus());
        self::assertSame('req_123', $conversation->getCurrentRequestId());
        self::assertSame('You are helpful', $conversation->getSystemPrompt());
        self::assertTrue($conversation->isArchived());
        self::assertTrue($conversation->isPinned());
        self::assertSame('Some error', $conversation->getErrorMessage());
        self::assertSame(1700000000, $conversation->getTstamp());
    }

    #[Test]
    public function toRowSerializesCorrectly(): void
    {
        $conversation = new Conversation();
        $conversation->setBeUser(5);
        $conversation->setTitle('Test');
        $conversation->setArchived(true);
        $conversation->setPinned(true);
        $conversation->setErrorMessage('err');
        $conversation->setSystemPrompt('prompt');

        $row = $conversation->toRow();

        self::assertSame(5, $row['be_user']);
        self::assertSame('Test', $row['title']);
        self::assertSame(1, $row['archived']);
        self::assertSame(1, $row['pinned']);
        self::assertSame('err', $row['error_message']);
        self::assertSame('prompt', $row['system_prompt']);
    }
}
