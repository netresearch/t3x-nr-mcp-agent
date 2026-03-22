<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Utility;

use Netresearch\NrMcpAgent\Utility\ErrorMessageSanitizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ErrorMessageSanitizerTest extends TestCase
{
    #[Test]
    public function bearerTokenIsRedacted(): void
    {
        $message = 'Authorization failed: Bearer sk-abc123def456.xyz';
        $result = ErrorMessageSanitizer::sanitize($message);

        self::assertStringNotContainsString('sk-abc123def456.xyz', $result);
        self::assertStringContainsString('Bearer [REDACTED]', $result);
    }

    #[Test]
    public function urlIsRedacted(): void
    {
        $message = 'Failed to connect to https://api.example.com/v1/chat';
        $result = ErrorMessageSanitizer::sanitize($message);

        self::assertStringNotContainsString('https://api.example.com', $result);
        self::assertStringContainsString('[URL]', $result);
    }

    #[Test]
    public function httpUrlIsRedacted(): void
    {
        $message = 'Request to http://localhost:8080/api failed';
        $result = ErrorMessageSanitizer::sanitize($message);

        self::assertStringNotContainsString('http://localhost:8080', $result);
        self::assertStringContainsString('[URL]', $result);
    }

    #[Test]
    public function apiKeyPatternIsRedacted(): void
    {
        $message = 'Error with key-abc123def456 token';
        $result = ErrorMessageSanitizer::sanitize($message);

        self::assertStringNotContainsString('key-abc123def456', $result);
        self::assertStringContainsString('[REDACTED]', $result);
    }

    #[Test]
    public function skPrefixedKeyIsRedacted(): void
    {
        $message = 'Invalid sk-proj-abcdefghijklmnop key';
        $result = ErrorMessageSanitizer::sanitize($message);

        self::assertStringNotContainsString('sk-proj-abcdefghijklmnop', $result);
        self::assertStringContainsString('[REDACTED]', $result);
    }

    #[Test]
    public function truncationRespectMaxLength(): void
    {
        $longMessage = str_repeat('a', 600);
        $result = ErrorMessageSanitizer::sanitize($longMessage);

        self::assertLessThanOrEqual(500, mb_strlen($result));
    }

    #[Test]
    public function truncationAddsEllipsis(): void
    {
        $longMessage = str_repeat('a', 600);
        $result = ErrorMessageSanitizer::sanitize($longMessage);

        self::assertStringEndsWith("\u{2026}", $result);
    }

    #[Test]
    public function customMaxLengthIsRespected(): void
    {
        $message = str_repeat('b', 200);
        $result = ErrorMessageSanitizer::sanitize($message, 100);

        self::assertLessThanOrEqual(100, mb_strlen($result));
        self::assertStringEndsWith("\u{2026}", $result);
    }

    #[Test]
    public function shortMessagePassesThroughUnchanged(): void
    {
        $message = 'Connection refused';
        $result = ErrorMessageSanitizer::sanitize($message);

        self::assertSame('Connection refused', $result);
    }

    #[Test]
    public function emptyMessageReturnsEmpty(): void
    {
        self::assertSame('', ErrorMessageSanitizer::sanitize(''));
    }

    #[Test]
    public function multiplePatternsInOneMessage(): void
    {
        $message = 'Bearer sk-123456 failed at https://api.openai.com/v1/chat with key-abcdefghijklm';
        $result = ErrorMessageSanitizer::sanitize($message);

        self::assertStringNotContainsString('sk-123456', $result);
        self::assertStringNotContainsString('https://api.openai.com', $result);
        self::assertStringNotContainsString('key-abcdefghijklm', $result);
        self::assertStringContainsString('Bearer [REDACTED]', $result);
        self::assertStringContainsString('[URL]', $result);
    }

    #[Test]
    public function exactMaxLengthPassesThroughWithoutTruncation(): void
    {
        $message = str_repeat('c', 500);
        $result = ErrorMessageSanitizer::sanitize($message);

        self::assertSame(500, mb_strlen($result));
        self::assertStringEndsNotWith("\u{2026}", $result);
    }

    #[Test]
    public function bearerTokenCaseInsensitive(): void
    {
        $message = 'BEARER my-secret-token.foo';
        $result = ErrorMessageSanitizer::sanitize($message);

        self::assertStringNotContainsString('my-secret-token', $result);
    }

    // -------------------------------------------------------------------------
    // MBString mutation (line 14): mb_strlen / mb_substr vs strlen / substr
    // Tests must use multibyte characters at the truncation boundary
    // -------------------------------------------------------------------------

    #[Test]
    public function truncationCountsMultibyteCharactersCorrectly(): void
    {
        // Each 'ü' is 2 bytes in UTF-8 but 1 mb_strlen unit.
        // If substr() (byte-based) were used instead of mb_substr(), a 500-byte
        // limit would cut in the middle of a multibyte character or count chars wrong.
        $message = str_repeat('ü', 600);
        $result = ErrorMessageSanitizer::sanitize($message);

        // Result must be exactly 500 multibyte chars (not bytes)
        self::assertSame(500, mb_strlen($result));
        // Must end with ellipsis, not a broken byte
        self::assertStringEndsWith("\u{2026}", $result);
    }

    #[Test]
    public function truncationResultLengthIsExactlyMaxLengthInCharsNotBytes(): void
    {
        // ConcatOperandRemoval mutation removes the ellipsis from truncation output
        $message = str_repeat('a', 600);
        $result = ErrorMessageSanitizer::sanitize($message, 50);

        self::assertSame(50, mb_strlen($result));
        // Ellipsis must be present — 49 content chars + 1 ellipsis = 50
        self::assertStringEndsWith("\u{2026}", $result);
        self::assertSame(49, mb_strlen(str_replace("\u{2026}", '', $result)));
    }

    #[Test]
    public function urlRedactionReplacesBothProtocols(): void
    {
        // ConcatOperandRemoval on the [URL] replacement text
        $result1 = ErrorMessageSanitizer::sanitize('See https://example.com for details');
        $result2 = ErrorMessageSanitizer::sanitize('See http://example.com for details');

        self::assertStringContainsString('[URL]', $result1);
        self::assertStringContainsString('[URL]', $result2);
        self::assertStringNotContainsString('example.com', $result1);
        self::assertStringNotContainsString('example.com', $result2);
    }
}
