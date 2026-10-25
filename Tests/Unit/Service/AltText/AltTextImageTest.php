<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\AltText;

use Netresearch\NrMcpAgent\Service\AltText\AltTextImage;
use Netresearch\NrMcpAgent\Service\AltText\AltTextImagePolicyInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * The image the model gets of one reference (ADR-026): a raster copy of at
 * most 1024 pixels, never the original and never an SVG, and the card's note
 * says so exactly when no copy goes to the model.
 */
#[CoversClass(AltTextImage::class)]
final class AltTextImageTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $processed = [];

    private function image(string $mimeType, string $contents = 'BYTES', bool $exists = true): AltTextImage
    {
        $processed = $this->createMock(ProcessedFile::class);
        $processed->method('getContents')->willReturn($contents);
        $processed->method('getMimeType')->willReturn($mimeType);
        $file = $this->createMock(File::class);
        $file->method('exists')->willReturn($exists);
        $file->method('process')->willReturnCallback(function (string $context, array $configuration) use ($processed): ProcessedFile {
            $this->processed[] = $configuration;

            return $processed;
        });
        $reference = $this->createMock(FileReference::class);
        $reference->method('getOriginalFile')->willReturn($file);
        $reference->method('getProperty')->willReturn('');
        $factory = $this->createMock(ResourceFactory::class);
        $factory->method('getFileReferenceObject')->willReturn($reference);
        $policy = $this->createMock(AltTextImagePolicyInterface::class);
        $policy->method('refusal')->willReturn(null);

        return new AltTextImage($factory, $policy);
    }

    #[Test]
    public function theModelGetsAPngCopyOfAtMost1024Pixels(): void
    {
        $image = $this->image('image/png');

        self::assertSame(['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('BYTES')]], $image->modelBlock(7, true));
        self::assertSame('', $image->note(7, true));
        self::assertSame(['maxWidth' => 1024, 'maxHeight' => 1024, 'fileExtension' => 'png'], $this->processed[0]);
    }

    /** An SVG the image processor did not convert is not sent, and the card says the image was not looked at. */
    #[Test]
    public function anSvgIsNeverSentAndTheCardSaysSo(): void
    {
        $image = $this->image('image/svg+xml', '<svg/>');

        self::assertNull($image->modelBlock(7, true));
        self::assertSame(AltTextImage::NOTE_UNREADABLE, $image->note(7, true));
    }

    #[Test]
    public function anUnreadableFileIsNotSentAndTheCardSaysSo(): void
    {
        $image = $this->image('image/png', 'BYTES', false);

        self::assertNull($image->modelBlock(7, true));
        self::assertSame(AltTextImage::NOTE_UNREADABLE, $image->note(7, true));
    }

    #[Test]
    public function aProviderWithoutVisionGetsNoImage(): void
    {
        $image = $this->image('image/png');

        self::assertNull($image->modelBlock(7, false));
        self::assertSame(AltTextImage::NOTE_NO_VISION, $image->note(7, false));
        self::assertSame([], $this->processed);
    }
}
