<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Domain\Model;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The two fields NEXT-167 added to a conversation (ADR-017): the kind of a
 * failure, and a notice on a message.
 */
#[CoversClass(Conversation::class)]
final class ConversationFeedbackTest extends TestCase
{
    #[Test]
    public function theErrorCodeRoundTripsThroughTheRow(): void
    {
        $conversation = new Conversation();
        $conversation->setErrorMessage('API key identifier is required for provider OpenAI', 'providerNotConfigured');

        $row = $conversation->toRow();
        self::assertSame('providerNotConfigured', $row['error_code']);

        self::assertSame('providerNotConfigured', Conversation::fromRow($row)->getErrorCode());
    }

    #[Test]
    public function aMessageWrittenWithoutACodeDropsTheCodeOfTheOneItReplaces(): void
    {
        $conversation = new Conversation();
        $conversation->setErrorMessage('API key identifier is required for provider OpenAI', 'providerNotConfigured');

        $conversation->setErrorMessage('Timed out');

        self::assertSame('', $conversation->getErrorCode());
    }

    #[Test]
    public function aNoticeIsStoredOnTheMessageAndOmittedWhenEmpty(): void
    {
        $conversation = new Conversation();
        $conversation->appendMessage(MessageRole::User, 'weiter');
        $conversation->appendMessage(MessageRole::Assistant, 'Erledigt.', 'nothingSaved');

        $messages = $conversation->getDecodedMessages();
        self::assertArrayNotHasKey('notice', $messages[0]);
        self::assertSame('nothingSaved', $messages[1]['notice'] ?? null);
    }
}
