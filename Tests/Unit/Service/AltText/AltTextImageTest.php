<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\AltText;

use Netresearch\NrMcpAgent\Service\AltText\AltTextImage;
use Netresearch\NrMcpAgent\Service\AltText\AltTextImagePolicyInterface;
use Netresearch\NrMcpAgent\Service\AltText\AltTextImageRefusal;
use Netresearch\NrMcpAgent\Service\AltText\ImageAnalysisPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * The image the model gets of one reference (ADR-026): a raster copy of at
 * most 1024 pixels, never the original and never an SVG, and the card's note
 * says so exactly when no copy goes to the model. The privacy setting
 * "Bildanalyse" decides before anything is processed, and its reason wins.
 */
#[CoversClass(AltTextImage::class)]
final class AltTextImageTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $processed = [];

    private function image(string $mimeType, string $contents = 'BYTES', bool $exists = true, ?AltTextImageRefusal $refusal = null): AltTextImage
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
        $policy->method('refusal')->willReturn($refusal);

        return new AltTextImage($factory, $policy, new Context());
    }

    #[Test]
    public function theModelGetsAPngCopyOfAtMost1024Pixels(): void
    {
        $image = $this->image('image/png');

        self::assertSame(['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('BYTES')]], $image->modelBlock(7, true));
        self::assertSame('', $image->cardNote(7, true)['imageNote']);
        self::assertSame(['maxWidth' => 1024, 'maxHeight' => 1024, 'fileExtension' => 'png'], $this->processed[0]);
    }

    /** An SVG the image processor did not convert is not sent, and the card says the image was not looked at. */
    #[Test]
    public function anSvgIsNeverSentAndTheCardSaysSo(): void
    {
        $image = $this->image('image/svg+xml', '<svg/>');

        self::assertNull($image->modelBlock(7, true));
        self::assertSame(AltTextImage::NOTE_UNREADABLE, $image->cardNote(7, true)['imageNote']);
    }

    #[Test]
    public function anUnreadableFileIsNotSentAndTheCardSaysSo(): void
    {
        $image = $this->image('image/png', 'BYTES', false);

        self::assertNull($image->modelBlock(7, true));
        self::assertSame(AltTextImage::NOTE_UNREADABLE, $image->cardNote(7, true)['imageNote']);
    }

    #[Test]
    public function aProviderWithoutVisionGetsNoImage(): void
    {
        $image = $this->image('image/png');

        self::assertNull($image->modelBlock(7, false));
        self::assertSame(AltTextImage::NOTE_NO_VISION, $image->cardNote(7, false)['imageNote']);
        self::assertSame([], $this->processed);
    }

    /** The card shows the code alone where nothing is missing or no folder is named. */
    #[Test]
    public function theCardNoteOfASentImageIsEmpty(): void
    {
        self::assertSame(['imageNote' => ''], $this->image('image/png')->cardNote(7, true));
    }

    #[Test]
    public function withImageAnalysisOffNothingIsProcessedOrSent(): void
    {
        $image = $this->image('image/png', refusal: new AltTextImageRefusal(ImageAnalysisPolicy::NOTE_ANALYSIS_OFF));

        self::assertNull($image->modelBlock(7, true));
        self::assertSame(['imageNote' => ImageAnalysisPolicy::NOTE_ANALYSIS_OFF], $image->cardNote(7, true));
        self::assertSame([], $this->processed);
    }

    /** The settings are the reason that holds whatever the provider can see, so they are named over "no vision". */
    #[Test]
    public function theSettingsReasonIsNamedAlsoWhereTheProviderCannotSeeImages(): void
    {
        $image = $this->image('image/png', refusal: new AltTextImageRefusal(ImageAnalysisPolicy::NOTE_ANALYSIS_OFF));

        self::assertSame(ImageAnalysisPolicy::NOTE_ANALYSIS_OFF, $image->cardNote(7, false)['imageNote']);
    }

    #[Test]
    public function anImageInAnExcludedFolderIsNotSentAndTheCardNamesTheFolder(): void
    {
        $image = $this->image('image/png', refusal: new AltTextImageRefusal(ImageAnalysisPolicy::NOTE_EXCLUDED_FOLDER, 'fileadmin/team/'));

        self::assertNull($image->modelBlock(7, true));
        self::assertSame(['imageNote' => ImageAnalysisPolicy::NOTE_EXCLUDED_FOLDER, 'imageNoteFolder' => 'fileadmin/team/'], $image->cardNote(7, true));
        self::assertSame([], $this->processed);
    }
}
