<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

use Throwable;
use TYPO3\CMS\Core\Context\AspectInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\FileProcessingAspect;
use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * The image of one reference as the chat uses it (ADR-026), always with the
 * reference's crop: a small preview for the backend, and a scaled copy for
 * the model, never the original file. The copy goes to the model only where
 * the provider can see images and the image policy (the privacy setting
 * "Bildanalyse") lets it go; otherwise the card says why the image itself was
 * not looked at.
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
        private readonly Context $context,
    ) {}

    /** The URL of the backend preview, empty where there is none. */
    public function previewUrl(int $referenceUid): string
    {
        $processed = $this->scaled($referenceUid, self::PREVIEW_WIDTH, self::PREVIEW_HEIGHT);

        return $processed instanceof ProcessedFile ? (string) $processed->getPublicUrl() : '';
    }

    /**
     * Why the model will not see the image, as the card keeps it: a note
     * code, empty when it will, and the folder it names where the image lies
     * in a folder whose images are never sent. Asked when the card is
     * written, from what the provider reports, and decided as modelBlock()
     * decides it: the note is empty exactly when a raster copy can be sent.
     *
     * @return array{imageNote: string, imageNoteFolder?: string}
     */
    public function cardNote(int $referenceUid, bool $vision): array
    {
        $prepared = $this->prepared($referenceUid, $vision);

        return $prepared['folder'] === ''
            ? ['imageNote' => $prepared['note']]
            : ['imageNote' => $prepared['note'], 'imageNoteFolder' => $prepared['folder']];
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
     * The privacy setting is asked first: an image the settings keep back
     * is named as such also where the provider could not see it anyway, as
     * the settings are the reason that holds whatever the provider.
     *
     * @return array{note: string, folder: string, block: array{type: string, image_url: array{url: string}}|null}
     */
    private function prepared(int $referenceUid, bool $vision): array
    {
        $reference = $this->reference($referenceUid);
        $refusal   = $reference instanceof FileReference ? $this->policy->refusal($reference) : null;
        if ($refusal instanceof AltTextImageRefusal) {
            return ['note' => $refusal->note, 'folder' => $refusal->folder, 'block' => null];
        }

        if (!$vision) {
            return ['note' => self::NOTE_NO_VISION, 'folder' => '', 'block' => null];
        }

        if (!$reference instanceof FileReference) {
            return ['note' => self::NOTE_UNREADABLE, 'folder' => '', 'block' => null];
        }

        $processed = $this->processedNow(fn (): ?ProcessedFile => $this->scaled($referenceUid, self::MODEL_SIZE, self::MODEL_SIZE, self::MODEL_FORMAT));
        if (!$processed instanceof ProcessedFile) {
            return ['note' => self::NOTE_UNREADABLE, 'folder' => '', 'block' => null];
        }

        try {
            $contents = $processed->getContents();
            $mimeType = $processed->getMimeType();
        } catch (Throwable) {
            return ['note' => self::NOTE_UNREADABLE, 'folder' => '', 'block' => null];
        }

        if ($contents === '' || !in_array($mimeType, self::RASTER_TYPES, true)) {
            return ['note' => self::NOTE_UNREADABLE, 'folder' => '', 'block' => null];
        }

        return ['note' => '', 'folder' => '', 'block' => ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mimeType . ';base64,' . base64_encode($contents)]]];
    }

    /**
     * The copy processed at once, also in a backend request. There the core's
     * DeferredBackendImageProcessor only records a processing and leaves the
     * file to the first request of its URL; the model's copy is read here and
     * never requested by URL. The core's ImageProcessingService turns the
     * deferral off the same way; the request's own setting is restored, so the
     * preview stays deferred.
     *
     * @param callable(): ?ProcessedFile $process
     */
    private function processedNow(callable $process): ?ProcessedFile
    {
        $previous = $this->context->hasAspect('fileProcessing') ? $this->context->getAspect('fileProcessing') : null;
        $this->context->setAspect('fileProcessing', new FileProcessingAspect(false));
        try {
            return $process();
        } finally {
            if ($previous instanceof AspectInterface) {
                $this->context->setAspect('fileProcessing', $previous);
            } else {
                $this->context->unsetAspect('fileProcessing');
            }
        }
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
