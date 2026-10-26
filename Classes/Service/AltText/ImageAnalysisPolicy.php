<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Throwable;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * The privacy setting "Bildanalyse" (ADR-026, slice 3): whether an image may
 * go to the AI provider. With "Nur Kontext verwenden" no image goes; with
 * "Bilder analysieren" every image goes except those in an excluded folder,
 * subfolders included. It applies to every image the assistant would send:
 * the scaled copy for an alt text proposal and an image attached in the chat.
 *
 * A folder is bound to its storage: `fileadmin/team/` names the folder
 * `/team/` of the storage whose base path is `fileadmin/`, `2:/team/` the
 * folder `/team/` of storage 2, and neither excludes `/team/` of another
 * storage. Folders are compared by their identifiers, without asking the
 * storage, so a folder that does not exist (yet) costs nothing.
 */
class ImageAnalysisPolicy implements AltTextImagePolicyInterface
{
    /** The image was not sent: image analysis is switched off in the settings. */
    public const NOTE_ANALYSIS_OFF = 'analysisOff';

    /** The image was not sent: it lies in a folder whose images are never sent. */
    public const NOTE_EXCLUDED_FOLDER = 'excludedFolder';

    /** @var list<array{storage: int, identifier: string, caseSensitive: bool, label: string}>|null */
    private ?array $excluded = null;

    public function __construct(
        private readonly ExtensionConfiguration $configuration,
        private readonly StorageRepository $storages,
    ) {}

    /**
     * A reference whose file cannot be read is not refused here: no copy of
     * it can be made, and the card says so (AltTextImage::NOTE_UNREADABLE).
     */
    public function refusal(FileReference $reference): ?AltTextImageRefusal
    {
        try {
            return $this->refusalForFile($reference->getOriginalFile());
        } catch (Throwable) {
            return null;
        }
    }

    /** As refusal(), for a file: an image attached in the chat. */
    public function refusalForFile(File $file): ?AltTextImageRefusal
    {
        if (!$this->configuration->isImageAnalysisEnabled()) {
            return new AltTextImageRefusal(self::NOTE_ANALYSIS_OFF);
        }

        $folder = $this->excludedFolder($file->getStorage()->getUid(), $file->getIdentifier());

        return $folder === null ? null : new AltTextImageRefusal(self::NOTE_EXCLUDED_FOLDER, $folder);
    }

    /** Whether images go to the provider at all ("Bilder analysieren"). */
    public function analysesImages(): bool
    {
        return $this->configuration->isImageAnalysisEnabled();
    }

    /** The excluded folder, as the card names it, that holds the file; null when none does. */
    private function excludedFolder(int $storageUid, string $identifier): ?string
    {
        foreach ($this->excluded() as $folder) {
            if ($folder['storage'] !== $storageUid) {
                continue;
            }

            $matches = $folder['caseSensitive']
                ? str_starts_with($identifier, $folder['identifier'])
                : str_starts_with(mb_strtolower($identifier), mb_strtolower($folder['identifier']));
            if ($matches) {
                return $folder['label'];
            }
        }

        return null;
    }

    /**
     * The configured folders, each resolved to a storage and a folder
     * identifier with a slash at either end, so `/team/` does not take in
     * `/teamwork/`. An entry that names no storage is dropped.
     *
     * @return list<array{storage: int, identifier: string, caseSensitive: bool, label: string}>
     */
    private function excluded(): array
    {
        if ($this->excluded !== null) {
            return $this->excluded;
        }

        $entries = $this->configuration->getImageAnalysisExcludedFolders();
        if ($entries === []) {
            return $this->excluded = [];
        }

        $storages = [];
        foreach ($this->storages->findAll() as $storage) {
            $storages[$storage->getUid()] = $storage;
        }

        $excluded = [];
        foreach ($entries as $entry) {
            if (preg_match('/^(\d+):(.*)$/', $entry, $parts) === 1) {
                $storage = $storages[(int) $parts[1]] ?? null;
                if ($storage instanceof ResourceStorage) {
                    $excluded[] = $this->folder($storage, $this->identifier($parts[2]));
                }

                continue;
            }

            $path = trim($entry, '/') . '/';
            foreach ($storages as $storage) {
                $basePath = $this->publicBasePath($storage);
                if ($basePath !== '' && str_starts_with($path, $basePath)) {
                    $excluded[] = $this->folder($storage, $this->identifier(substr($path, strlen($basePath))));
                }
            }
        }

        return $this->excluded = $excluded;
    }

    /**
     * @return array{storage: int, identifier: string, caseSensitive: bool, label: string}
     */
    private function folder(ResourceStorage $storage, string $identifier): array
    {
        $basePath = $this->publicBasePath($storage);

        return [
            'storage'       => $storage->getUid(),
            'identifier'    => $identifier,
            'caseSensitive' => $storage->usesCaseSensitiveIdentifiers(),
            // As an editor knows the folder: fileadmin/team/, or 2:/team/
            // for a storage without a base path in the web root.
            'label' => $basePath !== '' ? rtrim($basePath, '/') . $identifier : $storage->getUid() . ':' . $identifier,
        ];
    }

    /** A folder identifier with a slash at either end: `/team/`, `/` for the root. */
    private function identifier(string $path): string
    {
        $path = trim($path, '/');

        return $path === '' ? '/' : '/' . $path . '/';
    }

    /**
     * The base path of a local storage relative to the web root, with one
     * trailing slash (`fileadmin/`); empty for any other storage.
     */
    private function publicBasePath(ResourceStorage $storage): string
    {
        $configuration = $storage->getConfiguration();
        $basePath      = $configuration['basePath'] ?? '';
        if ($storage->getDriverType() !== 'Local' || ($configuration['pathType'] ?? '') !== 'relative' || !is_string($basePath)) {
            return '';
        }

        $basePath = trim($basePath, '/');

        return $basePath === '' ? '' : $basePath . '/';
    }
}
