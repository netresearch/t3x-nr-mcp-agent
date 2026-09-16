<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Document;

use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UploadMimeTypeMapTest extends TestCase
{
    /**
     * Every extension an nr-llm provider advertises must resolve, or the file
     * picker and the upload endpoint disagree about it.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function advertisedExtensions(): iterable
    {
        yield 'png' => ['png', 'image/png'];
        yield 'jpg' => ['jpg', 'image/jpeg'];
        yield 'jpeg' => ['jpeg', 'image/jpeg'];
        yield 'gif' => ['gif', 'image/gif'];
        yield 'webp' => ['webp', 'image/webp'];
        yield 'heic (Gemini)' => ['heic', 'image/heic'];
        yield 'heif (Gemini)' => ['heif', 'image/heif'];
        yield 'pdf' => ['pdf', 'application/pdf'];
    }

    #[Test]
    #[DataProvider('advertisedExtensions')]
    public function resolvesEveryExtensionAProviderAdvertises(string $extension, string $expected): void
    {
        self::assertSame($expected, (new UploadMimeTypeMap())->resolve($extension));
    }

    #[Test]
    public function resolvesRegardlessOfCase(): void
    {
        self::assertSame('image/png', (new UploadMimeTypeMap())->resolve('PNG'));
    }

    #[Test]
    public function returnsNullForAnUnknownExtension(): void
    {
        self::assertNull((new UploadMimeTypeMap())->resolve('avif'));
    }

    #[Test]
    public function knownExtensionsDropsWhatItCannotTranslate(): void
    {
        self::assertSame(
            ['png', 'heic'],
            (new UploadMimeTypeMap())->knownExtensions(['png', 'avif', 'heic']),
        );
    }

    #[Test]
    public function knownExtensionsLowerCasesAndDeduplicates(): void
    {
        self::assertSame(
            ['jpg', 'png'],
            (new UploadMimeTypeMap())->knownExtensions(['JPG', 'jpg', 'png']),
        );
    }

    #[Test]
    public function toMimeTypesCollapsesJpgAndJpegOntoOneType(): void
    {
        self::assertSame(
            ['image/jpeg'],
            (new UploadMimeTypeMap())->toMimeTypes(['jpg', 'jpeg']),
        );
    }

    #[Test]
    public function toMimeTypesSkipsWhatItCannotTranslate(): void
    {
        self::assertSame(
            ['image/png'],
            (new UploadMimeTypeMap())->toMimeTypes(['png', 'avif']),
        );
    }
}
