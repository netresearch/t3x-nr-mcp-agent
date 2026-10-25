<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

use Throwable;
use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * The image of one reference as the chat uses it (ADR-026), always with the
 * reference's crop: a small preview for the backend, and a scaled copy for
 * the model, never the original file. The copy goes to the model only where
 * the provider can see images and the image policy lets it go; otherwise the
 * card says why the image itself was not looked at.
 */
class AltTextImage
{
    /** The image was not sent: the provider cannot see images. */
    public const NOTE_NO_VISION = 'noVision';

    /** The image was not sent: it could not be read or scaled. */
    public const NOTE_UNREADABLE = 'unreadable';

    /** The longest side of the copy the model gets. */
    private const MODEL_SIZE = 1024;

    /** The format of the copy the model gets. */
    private const MODEL_FORMAT = 'png';

    /** What a provider reads as an image. */
    private const RASTER_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    /** The longest side of the preview in the chat. */
    private const PREVIEW_WIDTH = 320;

    private const PREVIEW_HEIGHT = 240;

    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly AltTextImagePolicyInterface $policy,
    ) {}

    /** The URL of the backend preview, empty where there is none. */
    public function previewUrl(int $referenceUid): string
    {
        $processed = $this->scaled($referenceUid, self::PREVIEW_WIDTH, self::PREVIEW_HEIGHT);

        return $processed instanceof ProcessedFile ? (string) $processed->getPublicUrl() : '';
    }

    /**
     * Why the model will not see the image, as a note code; empty when it
     * will. Asked when the card is written, from what the provider reports,
     * and decided as modelBlock() decides it: the note is empty exactly when
     * a raster copy can be sent.
     */
    public function note(int $referenceUid, bool $vision): string
    {
        return $this->prepared($referenceUid, $vision)['note'];
    }

    /**
     * The scaled copy as a content block for the model ('image_url' with a
     * data URI), or null when it may not or cannot be sent.
     *
     * @return array{type: string, image_url: array{url: string}}|null
     */
    public function modelBlock(int $referenceUid, bool $vision): ?array
    {
        return $this->prepared($referenceUid, $vision)['block'];
    }

    /**
     * The copy for the model and the note that goes with it. The copy is a
     * raster image (PNG) of at most MODEL_SIZE pixels: a provider reads no
     * SVG, and an SVG the image processor cannot convert is not sent.
     *
     * @return array{note: string, block: array{type: string, image_url: array{url: string}}|null}
     */
    private function prepared(int $referenceUid, bool $vision): array
    {
        if (!$vision) {
            return ['note' => self::NOTE_NO_VISION, 'block' => null];
        }

        $reference = $this->reference($referenceUid);
        if (!$reference instanceof FileReference) {
            return ['note' => self::NOTE_UNREADABLE, 'block' => null];
        }

        $refusal = $this->policy->refusal($reference);
        if ($refusal !== null) {
            return ['note' => $refusal, 'block' => null];
        }

        $processed = $this->scaled($referenceUid, self::MODEL_SIZE, self::MODEL_SIZE, self::MODEL_FORMAT);
        if (!$processed instanceof ProcessedFile) {
            return ['note' => self::NOTE_UNREADABLE, 'block' => null];
        }

        try {
            $contents = $processed->getContents();
            $mimeType = $processed->getMimeType();
        } catch (Throwable) {
            return ['note' => self::NOTE_UNREADABLE, 'block' => null];
        }

        if ($contents === '' || !in_array($mimeType, self::RASTER_TYPES, true)) {
            return ['note' => self::NOTE_UNREADABLE, 'block' => null];
        }

        return ['note' => '', 'block' => ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mimeType . ';base64,' . base64_encode($contents)]]];
    }

    private function scaled(int $referenceUid, int $width, int $height, string $format = ''): ?ProcessedFile
    {
        $reference = $this->reference($referenceUid);
        if (!$reference instanceof FileReference) {
            return null;
        }

        try {
            $file = $reference->getOriginalFile();
            if (!$file->exists()) {
                return null;
            }

            $configuration = ['maxWidth' => $width, 'maxHeight' => $height, ...($format !== '' ? ['fileExtension' => $format] : [])];
            $crop          = $reference->getProperty('crop');
            $area          = CropVariantCollection::create(is_string($crop) ? $crop : '')->getCropArea();
            if (!$area->isEmpty()) {
                $configuration['crop'] = $area->makeAbsoluteBasedOnFile($file);
            }

            return $file->process(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, $configuration);
        } catch (Throwable) {
            return null;
        }
    }

    private function reference(int $referenceUid): ?FileReference
    {
        try {
            return $this->resourceFactory->getFileReferenceObject($referenceUid);
        } catch (Throwable) {
            return null;
        }
    }
}
