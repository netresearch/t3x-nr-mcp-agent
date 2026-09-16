<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Document;

/**
 * Translates the file extensions an nr-llm provider advertises into the MIME
 * types `finfo` reports for the same files.
 *
 * The provider contracts speak extensions: `VisionCapableInterface::getSupportedImageFormats()`
 * and `DocumentCapableInterface::getSupportedDocumentFormats()` both return
 * things like `png` or `pdf`, because the frontend needs them for the file
 * picker's `accept` attribute. The upload endpoint validates the *detected*
 * MIME type instead, since a client-supplied `Content-Type` is untrusted. One
 * map, used by both sides, is what keeps the picker from offering a file the
 * endpoint then rejects — which is what happened to `heic`/`heif` while the
 * map lived privately in the controller and listed neither, so Gemini users
 * could pick a HEIC and got a 422 back.
 *
 * An extension that is not listed here is dropped from the advertised formats
 * rather than guessed at: guessing a MIME type would widen what the upload
 * endpoint accepts, and that list is a security boundary.
 */
final readonly class UploadMimeTypeMap
{
    /**
     * Every extension any nr-llm provider currently advertises.
     *
     * @var array<string, string>
     */
    private const EXTENSION_TO_MIME = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'pdf'  => 'application/pdf',
    ];

    public function resolve(string $extension): ?string
    {
        return self::EXTENSION_TO_MIME[strtolower($extension)] ?? null;
    }

    /**
     * The subset of `$extensions` this map can translate, lower-cased and
     * de-duplicated, order preserved.
     *
     * @param  iterable<string> $extensions
     * @return list<string>
     */
    public function knownExtensions(iterable $extensions): array
    {
        $known = [];
        foreach ($extensions as $extension) {
            $extension = strtolower($extension);
            if (isset(self::EXTENSION_TO_MIME[$extension])) {
                $known[] = $extension;
            }
        }

        return array_values(array_unique($known));
    }

    /**
     * The MIME types for `$extensions`, skipping any this map does not know.
     *
     * @param  iterable<string> $extensions
     * @return list<string>
     */
    public function toMimeTypes(iterable $extensions): array
    {
        $mimeTypes = [];
        foreach ($extensions as $extension) {
            $mime = $this->resolve($extension);
            if ($mime !== null) {
                $mimeTypes[] = $mime;
            }
        }

        return array_values(array_unique($mimeTypes));
    }
}
