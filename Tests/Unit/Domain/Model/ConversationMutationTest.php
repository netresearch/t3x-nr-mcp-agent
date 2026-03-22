<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Domain\Model;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Targeted at mutation survivors in Conversation::fromRow(), toRow(),
 * setTitle(), setSystemPrompt(), setMessages(), hasPendingToolCalls(),
 * and getDecodedMessages().
 */
class ConversationMutationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // fromRow(): cast mutations (CastInt, CastString on lines 39-51)
    // -------------------------------------------------------------------------

    #[Test]
    public function fromRowCastsUidToInt(): void
    {
        $c = Conversation::fromRow(['uid' => '42', 'be_user' => 1, 'status' => 'idle', 'messages' => '[]', 'message_count' => 0]);
        self::assertSame(42, $c->getUid());
    }

    #[Test]
    public function fromRowCastsBeUserToInt(): void
    {
        $c = Conversation::fromRow(['uid' => 1, 'be_user' => '7', 'status' => 'idle', 'messages' => '[]', 'message_count' => 0]);
        self::assertSame(7, $c->getBeUser());
    }

    #[Test]
    public function fromRowCastsMessageCountToInt(): void
    {
        $c = Conversation::fromRow(['uid' => 1, 'be_user' => 1, 'status' => 'idle', 'messages' => '[]', 'message_count' => '3']);
        self::assertSame(3, $c->getMessageCount());
    }

    #[Test]
    public function fromRowCastsTstampToInt(): void
    {
        $c = Conversation::fromRow(['uid' => 1, 'be_user' => 1, 'status' => 'idle', 'messages' => '[]', 'message_count' => 0, 'tstamp' => '1700000000']);
        self::assertSame(1700000000, $c->getTstamp());
    }

    #[Test]
    public function fromRowCastsTitleToString(): void
    {
        $c = Conversation::fromRow(['uid' => 1, 'be_user' => 1, 'status' => 'idle', 'messages' => '[]', 'message_count' => 0, 'title' => 99]);
        self::assertSame('99', $c->getTitle());
    }

    #[Test]
    public function fromRowCastsStatusToString(): void
    {
        $c = Conversation::fromRow(['uid' => 1, 'be_user' => 1, 'status' => 'processing', 'messages' => '[]', 'message_count' => 0]);
        self::assertSame(ConversationStatus::Processing, $c->getStatus());
    }

    #[Test]
    public function fromRowSetsNonZeroUidCorrectly(): void
    {
        $c = Conversation::fromRow(['uid' => 99, 'be_user' => 5, 'status' => 'idle', 'messages' => '[]', 'message_count' => 2]);
        self::assertSame(99, $c->getUid());
        self::assertSame(5, $c->getBeUser());
        self::assertSame(2, $c->getMessageCount());
    }

    // -------------------------------------------------------------------------
    // toRow(): ArrayItem mutation (line 77) — all keys must be present
    // -------------------------------------------------------------------------

    #[Test]
    public function toRowContainsAllExpectedKeys(): void
    {
        $c = Conversation::fromRow(['uid' => 1, 'be_user' => 2, 'status' => 'idle', 'messages' => '[]', 'message_count' => 0, 'title' => 'T', 'system_prompt' => 'S']);
        $row = $c->toRow();

        self::assertArrayHasKey('be_user', $row);
        self::assertArrayHasKey('title', $row);
        self::assertArrayHasKey('messages', $row);
        self::assertArrayHasKey('message_count', $row);
        self::assertArrayHasKey('status', $row);
        self::assertArrayHasKey('current_request_id', $row);
        self::assertArrayHasKey('system_prompt', $row);
        self::assertArrayHasKey('archived', $row);
        self::assertArrayHasKey('pinned', $row);
        self::assertArrayHasKey('error_message', $row);
    }

    #[Test]
    public function toRowReflectsCurrentValues(): void
    {
        $c = Conversation::fromRow(['uid' => 1, 'be_user' => 3, 'status' => 'idle', 'messages' => '[]', 'message_count' => 0]);
        $c->setTitle('My Title');
        $row = $c->toRow();

        self::assertSame(3, $row['be_user']);
        self::assertSame('My Title', $row['title']);
        self::assertSame('idle', $row['status']);
    }

    // -------------------------------------------------------------------------
    // setTitle(): MBString mutation (line 107) — must use mb_substr, not substr
    // -------------------------------------------------------------------------

    #[Test]
    public function setTitleTruncatesAtExactly255Chars(): void
    {
        $c = new Conversation();
        $c->setTitle(str_repeat('a', 300));
        self::assertSame(255, mb_strlen($c->getTitle()));
    }

    #[Test]
    public function setTitlePreservesMultibyteCharactersCorrectly(): void
    {
        // Each emoji is 1 mb_strlen unit but multiple bytes — substr would break them
        $c = new Conversation();
        $c->setTitle(str_repeat('ü', 300));
        // mb_substr preserves complete characters; title must be exactly 255 mb chars
        self::assertSame(255, mb_strlen($c->getTitle()));
        // Must not end with a broken byte sequence
        self::assertStringEndsWith('ü', $c->getTitle());
    }

    #[Test]
    public function setTitleShortStringIsNotTruncated(): void
    {
        $c = new Conversation();
        $c->setTitle('Hello');
        self::assertSame('Hello', $c->getTitle());
    }

    // -------------------------------------------------------------------------
    // setSystemPrompt(): MBString mutation (line 200)
    // -------------------------------------------------------------------------

    #[Test]
    public function setSystemPromptTruncatesAt10000MultibyteSafe(): void
    {
        $c = new Conversation();
        $c->setSystemPrompt(str_repeat('ä', 10500));
        self::assertSame(10000, mb_strlen($c->getSystemPrompt()));
        self::assertStringEndsWith('ä', $c->getSystemPrompt());
    }

    #[Test]
    public function setSystemPromptShortValueIsNotTruncated(): void
    {
        $c = new Conversation();
        $c->setSystemPrompt('Be helpful.');
        self::assertSame('Be helpful.', $c->getSystemPrompt());
    }

    // -------------------------------------------------------------------------
    // setMessages(): BitwiseOr mutation (line 150) — JSON_UNESCAPED_UNICODE
    // -------------------------------------------------------------------------

    #[Test]
    public function setMessagesPreservesUnicodeWithoutEscaping(): void
    {
        $c = new Conversation();
        $c->setMessages([['role' => 'user', 'content' => 'Hallo Welt 😀 こんにちは']]);

        // Without JSON_UNESCAPED_UNICODE, unicode chars become \uXXXX sequences
        self::assertStringContainsString('😀', $c->getMessages());
        self::assertStringContainsString('こんにちは', $c->getMessages());
    }

    #[Test]
    public function setMessagesUpdatesMessageCount(): void
    {
        $c = new Conversation();
        $c->setMessages([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi'],
        ]);
        self::assertSame(2, $c->getMessageCount());
    }

    // -------------------------------------------------------------------------
    // hasPendingToolCalls(): LogicalAnd mutation (line 242)
    // All three conditions must be true: is_array, role=assistant, !empty tool_calls
    // -------------------------------------------------------------------------

    #[Test]
    public function hasPendingToolCallsReturnsFalseWhenLastMessageHasNoToolCalls(): void
    {
        $c = Conversation::fromRow([
            'uid' => 1, 'be_user' => 1, 'status' => 'processing',
            'messages' => json_encode([['role' => 'assistant', 'content' => 'done']]),
            'message_count' => 1,
        ]);
        self::assertFalse($c->hasPendingToolCalls());
    }

    #[Test]
    public function hasPendingToolCallsReturnsFalseWhenLastMessageIsUserEvenWithToolCallsKey(): void
    {
        // Kills LogicalAnd: role check must be verified independently
        $c = Conversation::fromRow([
            'uid' => 1, 'be_user' => 1, 'status' => 'processing',
            'messages' => json_encode([
                ['role' => 'user', 'content' => 'Hello', 'tool_calls' => [['id' => 'tc_1']]],
            ]),
            'message_count' => 1,
        ]);
        self::assertFalse($c->hasPendingToolCalls());
    }

    #[Test]
    public function hasPendingToolCallsReturnsFalseWhenToolCallsArrayIsEmpty(): void
    {
        // Kills LogicalAnd: !empty() check must matter
        $c = Conversation::fromRow([
            'uid' => 1, 'be_user' => 1, 'status' => 'processing',
            'messages' => json_encode([
                ['role' => 'assistant', 'content' => '', 'tool_calls' => []],
            ]),
            'message_count' => 1,
        ]);
        self::assertFalse($c->hasPendingToolCalls());
    }

    #[Test]
    public function hasPendingToolCallsReturnsTrueWhenAllConditionsMet(): void
    {
        $c = Conversation::fromRow([
            'uid' => 1, 'be_user' => 1, 'status' => 'processing',
            'messages' => json_encode([
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'tc_1', 'function' => ['name' => 'test']]]],
            ]),
            'message_count' => 2,
        ]);
        self::assertTrue($c->hasPendingToolCalls());
    }

    // -------------------------------------------------------------------------
    // getDecodedMessages(): LogicalOr + Continue_ mutations (lines 133-134)
    // Tool call normalization: invalid call shapes must be skipped
    // -------------------------------------------------------------------------

    #[Test]
    public function getDecodedMessagesSkipsNormalizationWhenCallHasNoFunctionKey(): void
    {
        // Kills LogicalOr: all parts of the condition must be checked
        $messages = json_encode([
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'tc_1'],  // no 'function' key
            ]],
        ]);
        $c = Conversation::fromRow(['uid' => 1, 'be_user' => 1, 'status' => 'processing', 'messages' => $messages, 'message_count' => 1]);

        $decoded = $c->getDecodedMessages();
        // Must not crash and call without 'function' must remain unchanged
        self::assertArrayNotHasKey('function', $decoded[0]['tool_calls'][0]);
    }

    #[Test]
    public function getDecodedMessagesNormalizesToolCallArgumentsFromArrayToJsonString(): void
    {
        // When arguments is stored as array (from json_decode), re-encodes to JSON string
        $messages = json_encode([
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'tc_1', 'function' => ['name' => 'myTool', 'arguments' => ['key' => 'value']]],
            ]],
        ]);
        $c = Conversation::fromRow(['uid' => 1, 'be_user' => 1, 'status' => 'processing', 'messages' => $messages, 'message_count' => 1]);

        $decoded = $c->getDecodedMessages();
        $args = $decoded[0]['tool_calls'][0]['function']['arguments'];
        self::assertIsString($args, 'arguments must be re-encoded to JSON string');
        self::assertSame(['key' => 'value'], json_decode($args, true));
    }

    #[Test]
    public function getDecodedMessagesSkipsNormalizationWhenFunctionHasNoArgumentsArray(): void
    {
        // function.arguments is a string (already encoded) — must not double-encode
        $messages = json_encode([
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'tc_1', 'function' => ['name' => 'myTool', 'arguments' => '{"key":"value"}']],
            ]],
        ]);
        $c = Conversation::fromRow(['uid' => 1, 'be_user' => 1, 'status' => 'processing', 'messages' => $messages, 'message_count' => 1]);

        $decoded = $c->getDecodedMessages();
        // String arguments must remain a string (not wrapped again)
        self::assertIsString($decoded[0]['tool_calls'][0]['function']['arguments']);
        self::assertSame('{"key":"value"}', $decoded[0]['tool_calls'][0]['function']['arguments']);
    }

    #[Test]
    public function getDecodedMessagesProcessesValidCallAfterInvalidOne(): void
    {
        // Kills Continue_: loop must continue past invalid calls and still normalize valid ones
        $messages = json_encode([
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'tc_invalid'],                                                        // no function key — skip
                ['id' => 'tc_valid', 'function' => ['name' => 'tool', 'arguments' => ['x' => 1]]],  // normalize this
            ]],
        ]);
        $c = Conversation::fromRow(['uid' => 1, 'be_user' => 1, 'status' => 'processing', 'messages' => $messages, 'message_count' => 1]);

        $decoded = $c->getDecodedMessages();
        $validCall = $decoded[0]['tool_calls'][1];
        self::assertIsString($validCall['function']['arguments']);
        self::assertSame(['x' => 1], json_decode($validCall['function']['arguments'], true));
    }

    // -------------------------------------------------------------------------
    // fromRow(): CastString — non-scalar values must be coerced to '' via (string)null
    // val() returns null for non-scalar input; without cast, assigning null to
    // a non-nullable string property would throw TypeError.
    // -------------------------------------------------------------------------

    #[Test]
    public function fromRowCoercesNonScalarTitleToEmptyString(): void
    {
        // val() returns null for non-scalar — (string)null = '' prevents TypeError
        $c = Conversation::fromRow(['uid' => 1, 'be_user' => 1, 'status' => 'idle', 'messages' => '[]', 'message_count' => 0, 'title' => ['nested']]);
        self::assertSame('', $c->getTitle());
    }

    #[Test]
    public function fromRowCoercesNonScalarMessagesToEmptyString(): void
    {
        $c = Conversation::fromRow(['uid' => 1, 'be_user' => 1, 'status' => 'idle', 'messages' => ['invalid'], 'message_count' => 0]);
        self::assertSame('', $c->getMessages());
    }

    #[Test]
    public function fromRowCoercesNonScalarStatusToEmptyString(): void
    {
        $c = Conversation::fromRow(['uid' => 1, 'be_user' => 1, 'status' => new stdClass(), 'messages' => '[]', 'message_count' => 0]);
        // val() returns null → (string)null = '' → no ConversationStatus match → fallback
        self::assertNotNull($c->getStatus());
    }

    #[Test]
    public function fromRowCoercesNonScalarCurrentRequestIdToEmptyString(): void
    {
        $c = Conversation::fromRow(['uid' => 1, 'be_user' => 1, 'status' => 'idle', 'messages' => '[]', 'message_count' => 0, 'current_request_id' => ['invalid']]);
        self::assertSame('', $c->getCurrentRequestId());
    }
}
