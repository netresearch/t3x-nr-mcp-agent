<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Domain\Model;

use JsonException;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $conversation->appendMessage(MessageRole::User, 'Hello');

        self::assertSame(1, $conversation->getMessageCount());
    }

    #[Test]
    public function appendMessageAddsToMessages(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage(MessageRole::User, 'Hello');

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
        $conversation->appendMessage(MessageRole::Assistant, $content);

        $messages = $conversation->getDecodedMessages();
        self::assertSame($content, $messages[0]['content']);
    }

    #[Test]
    public function autoTitleUsesFirstUserMessage(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage(MessageRole::User, 'Übersetze Seite 5 ins Englische');

        self::assertSame('Übersetze Seite 5 ins Englische', $conversation->getTitle());
    }

    #[Test]
    public function autoTitleTruncatesLongMessages(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage(MessageRole::User, str_repeat('a', 300));

        self::assertSame(255, mb_strlen($conversation->getTitle()));
    }

    #[Test]
    public function autoTitleDoesNotOverwriteExistingTitle(): void
    {
        $conversation = new Conversation();
        $conversation->setTitle('My custom title');
        $conversation->appendMessage(MessageRole::User, 'Hello');

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

    #[Test]
    public function hasPendingToolCallsReturnsTrueWhenLastMessageHasToolCalls(): void
    {
        $conversation = new Conversation();
        $conversation->setMessages([
            ['role' => 'user', 'content' => 'Do thing'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'tool', 'arguments' => '{}']],
            ]],
        ]);

        self::assertTrue($conversation->hasPendingToolCalls());
    }

    #[Test]
    public function hasPendingToolCallsReturnsFalseForUserMessage(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage(MessageRole::User, 'Hello');

        self::assertFalse($conversation->hasPendingToolCalls());
    }

    #[Test]
    public function hasPendingToolCallsReturnsFalseForEmptyConversation(): void
    {
        $conversation = new Conversation();
        self::assertFalse($conversation->hasPendingToolCalls());
    }

    #[Test]
    public function hasPendingToolCallsReturnsFalseForAssistantWithoutToolCalls(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage(MessageRole::User, 'Hi');
        $conversation->appendMessage(MessageRole::Assistant, 'Hello!');

        self::assertFalse($conversation->hasPendingToolCalls());
    }

    #[Test]
    public function getMessagesReturnsRawJsonString(): void
    {
        $conversation = new Conversation();
        self::assertSame('', $conversation->getMessages());

        $conversation->appendMessage(MessageRole::User, 'Hello');
        self::assertNotSame('', $conversation->getMessages());
        self::assertJson($conversation->getMessages());
    }

    #[Test]
    public function autoTitleNotOverwrittenBySecondUserMessage(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage(MessageRole::User, 'First message');
        $conversation->appendMessage(MessageRole::Assistant, 'Reply');
        $conversation->appendMessage(MessageRole::User, 'Second message');

        self::assertSame('First message', $conversation->getTitle());
    }

    #[Test]
    public function toRowDoesNotIncludeUid(): void
    {
        $conversation = new Conversation();
        $row = $conversation->toRow();

        self::assertArrayNotHasKey('uid', $row);
    }

    #[Test]
    public function setCurrentRequestIdAndGet(): void
    {
        $conversation = new Conversation();
        $conversation->setCurrentRequestId('req_abc123');
        self::assertSame('req_abc123', $conversation->getCurrentRequestId());
    }

    #[Test]
    public function getStatusFallsBackToIdleForUnknownStatus(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 1,
            'status' => 'nonexistent_status',
        ]);
        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
    }

    #[Test]
    public function fromRowWithMinimalRowUsesDefaults(): void
    {
        $conversation = Conversation::fromRow([]);

        self::assertSame(0, $conversation->getUid());
        self::assertSame(0, $conversation->getBeUser());
        self::assertSame('', $conversation->getTitle());
        self::assertSame(0, $conversation->getMessageCount());
        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame('', $conversation->getCurrentRequestId());
        self::assertSame('', $conversation->getSystemPrompt());
        self::assertFalse($conversation->isArchived());
        self::assertFalse($conversation->isPinned());
        self::assertSame('', $conversation->getErrorMessage());
        self::assertSame(0, $conversation->getTstamp());
    }

    #[Test]
    public function toRowFromRowRoundtripConsistency(): void
    {
        $original = new Conversation();
        $original->setBeUser(7);
        $original->setTitle('Roundtrip Test');
        $original->setSystemPrompt('Be helpful');
        $original->setArchived(true);
        $original->setPinned(true);
        $original->setErrorMessage('some error');
        $original->setCurrentRequestId('req_42');
        $original->setStatus(ConversationStatus::Processing);
        $original->appendMessage(MessageRole::User, 'Hello');
        $original->appendMessage(MessageRole::Assistant, 'Hi there');

        $row = $original->toRow();
        $hydrated = Conversation::fromRow($row);

        self::assertSame($original->getBeUser(), $hydrated->getBeUser());
        self::assertSame($original->getTitle(), $hydrated->getTitle());
        self::assertSame($original->getSystemPrompt(), $hydrated->getSystemPrompt());
        self::assertSame($original->isArchived(), $hydrated->isArchived());
        self::assertSame($original->isPinned(), $hydrated->isPinned());
        self::assertSame($original->getErrorMessage(), $hydrated->getErrorMessage());
        self::assertSame($original->getStatus(), $hydrated->getStatus());
        self::assertSame($original->getMessageCount(), $hydrated->getMessageCount());
        self::assertSame($original->getDecodedMessages(), $hydrated->getDecodedMessages());
    }

    #[Test]
    public function appendMessageWithArrayContentWorksForToolRole(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage(MessageRole::Tool, ['result' => 'Page created']);

        $messages = $conversation->getDecodedMessages();
        self::assertCount(1, $messages);
        self::assertSame('tool', $messages[0]['role']);
        self::assertSame(['result' => 'Page created'], $messages[0]['content']);
    }

    #[Test]
    public function appendMultipleMessagesIncrementsCountCorrectly(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage(MessageRole::User, 'Q1');
        $conversation->appendMessage(MessageRole::Assistant, 'A1');
        $conversation->appendMessage(MessageRole::User, 'Q2');

        self::assertSame(3, $conversation->getMessageCount());
    }

    #[Test]
    public function getDecodedMessagesThrowsOnMalformedJson(): void
    {
        $conversation = Conversation::fromRow([
            'messages' => '{invalid json',
        ]);

        $this->expectException(JsonException::class);
        $conversation->getDecodedMessages();
    }

    #[Test]
    public function setTitleTruncatesLongTitle(): void
    {
        $conversation = new Conversation();
        $longTitle = str_repeat('x', 300);
        $conversation->setTitle($longTitle);

        self::assertSame(255, mb_strlen($conversation->getTitle()));
        self::assertSame(str_repeat('x', 255), $conversation->getTitle());
    }

    #[Test]
    public function setTitleKeepsExact255Chars(): void
    {
        $conversation = new Conversation();
        $exactTitle = str_repeat('y', 255);
        $conversation->setTitle($exactTitle);

        self::assertSame(255, mb_strlen($conversation->getTitle()));
        self::assertSame($exactTitle, $conversation->getTitle());
    }

    #[Test]
    public function setSystemPromptNormalValuePassesThrough(): void
    {
        $conversation = new Conversation();
        $conversation->setSystemPrompt('You are a helpful assistant.');
        self::assertSame('You are a helpful assistant.', $conversation->getSystemPrompt());
    }

    #[Test]
    public function setMessagesWithEmptyArrayResetsCount(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage(MessageRole::User, 'Hello');
        self::assertSame(1, $conversation->getMessageCount());

        $conversation->setMessages([]);
        self::assertSame(0, $conversation->getMessageCount());
    }

    /**
     * @return array<string, array{ConversationStatus, bool}>
     */
    public static function isResumableProvider(): array
    {
        return [
            'idle' => [ConversationStatus::Idle, false],
            'processing' => [ConversationStatus::Processing, true],
            'locked' => [ConversationStatus::Locked, false],
            'tool_loop' => [ConversationStatus::ToolLoop, true],
            'failed' => [ConversationStatus::Failed, true],
        ];
    }

    #[Test]
    #[DataProvider('isResumableProvider')]
    public function isResumableForAllStatuses(ConversationStatus $status, bool $expected): void
    {
        $conversation = new Conversation();
        $conversation->setStatus($status);
        self::assertSame($expected, $conversation->isResumable());
    }

    #[Test]
    public function autoTitleIgnoresAssistantMessage(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage(MessageRole::Assistant, 'Hello there');
        self::assertSame('', $conversation->getTitle());
    }

    #[Test]
    public function autoTitleIgnoresArrayContent(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage(MessageRole::User, ['type' => 'text', 'text' => 'Hello']);
        self::assertSame('', $conversation->getTitle());
    }

    #[Test]
    public function setStatusUpdatesValue(): void
    {
        $conversation = new Conversation();
        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());

        $conversation->setStatus(ConversationStatus::Processing);
        self::assertSame(ConversationStatus::Processing, $conversation->getStatus());

        $conversation->setStatus(ConversationStatus::Failed);
        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
    }

    #[Test]
    public function setBeUserUpdatesValue(): void
    {
        $conversation = new Conversation();
        self::assertSame(0, $conversation->getBeUser());

        $conversation->setBeUser(42);
        self::assertSame(42, $conversation->getBeUser());
    }

    #[Test]
    public function setArchivedUpdatesValue(): void
    {
        $conversation = new Conversation();
        self::assertFalse($conversation->isArchived());

        $conversation->setArchived(true);
        self::assertTrue($conversation->isArchived());

        $conversation->setArchived(false);
        self::assertFalse($conversation->isArchived());
    }

    #[Test]
    public function setPinnedUpdatesValue(): void
    {
        $conversation = new Conversation();
        self::assertFalse($conversation->isPinned());

        $conversation->setPinned(true);
        self::assertTrue($conversation->isPinned());

        $conversation->setPinned(false);
        self::assertFalse($conversation->isPinned());
    }

    #[Test]
    public function setErrorMessageUpdatesValue(): void
    {
        $conversation = new Conversation();
        self::assertSame('', $conversation->getErrorMessage());

        $conversation->setErrorMessage('Something went wrong');
        self::assertSame('Something went wrong', $conversation->getErrorMessage());

        $conversation->setErrorMessage('');
        self::assertSame('', $conversation->getErrorMessage());
    }

    #[Test]
    public function setCurrentRequestIdUpdatesValue(): void
    {
        $conversation = new Conversation();
        self::assertSame('', $conversation->getCurrentRequestId());

        $conversation->setCurrentRequestId('req_xyz');
        self::assertSame('req_xyz', $conversation->getCurrentRequestId());
    }

    #[Test]
    public function appendMessageAutoGeneratesTitleFromFirstUserMessage(): void
    {
        $conversation = new Conversation();
        self::assertSame('', $conversation->getTitle());

        $conversation->appendMessage(MessageRole::User, 'Translate page 5 to English');
        self::assertSame('Translate page 5 to English', $conversation->getTitle());
    }

    #[Test]
    public function appendMessageDoesNotOverwriteExistingTitle(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage(MessageRole::User, 'First question');
        self::assertSame('First question', $conversation->getTitle());

        $conversation->appendMessage(MessageRole::Assistant, 'Answer');
        $conversation->appendMessage(MessageRole::User, 'Second question');
        self::assertSame('First question', $conversation->getTitle());
    }

    #[Test]
    public function fromRowWithUnknownStatusDefaultsToIdle(): void
    {
        $conversation = Conversation::fromRow([
            'uid' => 1,
            'status' => 'completely_invalid_status',
        ]);
        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
    }

    #[Test]
    public function getDecodedMessagesReEncodesToolCallArgumentsAsJsonString(): void
    {
        // After json_decode, arguments become arrays — getDecodedMessages must re-encode them
        $messages = [
            ['role' => 'user', 'content' => 'Do something'],
            [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => [
                            'name' => 'WriteTable',
                            'arguments' => ['action' => 'create', 'table' => 'pages'],
                        ],
                    ],
                ],
            ],
        ];

        $conversation = Conversation::fromRow([
            'uid' => 1,
            'be_user' => 1,
            'status' => 'idle',
            'messages' => json_encode($messages),
            'message_count' => 2,
        ]);

        $decoded = $conversation->getDecodedMessages();
        $arguments = $decoded[1]['tool_calls'][0]['function']['arguments'];

        // Must be a JSON string, not an array
        self::assertIsString($arguments);
        self::assertSame('{"action":"create","table":"pages"}', $arguments);
    }

    #[Test]
    public function getDecodedMessagesPreservesStringArguments(): void
    {
        $messages = [
            [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => [
                            'name' => 'test',
                            'arguments' => '{"key":"value"}',
                        ],
                    ],
                ],
            ],
        ];

        $conversation = Conversation::fromRow([
            'uid' => 1,
            'be_user' => 1,
            'status' => 'idle',
            'messages' => json_encode($messages),
            'message_count' => 1,
        ]);

        $decoded = $conversation->getDecodedMessages();
        // String arguments should stay as-is (but json_decode already turned them into arrays...)
        // After json_decode + json_encode roundtrip, they should be valid JSON strings
        $arguments = $decoded[0]['tool_calls'][0]['function']['arguments'];
        self::assertIsString($arguments);
    }
}
