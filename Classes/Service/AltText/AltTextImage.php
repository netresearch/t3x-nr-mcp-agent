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
     * will. Asked when the card is written, from what the provider reports.
     */
    public function note(int $referenceUid, bool $vision): string
    {
        if (!$vision) {
            return self::NOTE_NO_VISION;
        }

        $reference = $this->reference($referenceUid);
        if (!$reference instanceof FileReference || !$reference->getOriginalFile()->exists()) {
            return self::NOTE_UNREADABLE;
        }

        return $this->policy->refusal($reference) ?? '';
    }

    /**
     * The scaled copy as a content block for the model ('image_url' with a
     * data URI), or null when it may not or cannot be sent.
     *
     * @return array{type: string, image_url: array{url: string}}|null
     */
    public function modelBlock(int $referenceUid, bool $vision): ?array
    {
        if ($this->note($referenceUid, $vision) !== '') {
            return null;
        }

        $processed = $this->scaled($referenceUid, self::MODEL_SIZE, self::MODEL_SIZE);
        if (!$processed instanceof ProcessedFile) {
            return null;
        }

        try {
            $contents = $processed->getContents();
        } catch (Throwable) {
            return null;
        }

        $mimeType = $processed->getMimeType();
        if ($contents === '' || !str_starts_with($mimeType, 'image/')) {
            return null;
        }

        return ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mimeType . ';base64,' . base64_encode($contents)]];
    }

    private function scaled(int $referenceUid, int $width, int $height): ?ProcessedFile
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

            $configuration = ['maxWidth' => $width, 'maxHeight' => $height];
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
