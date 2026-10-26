<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\AltText;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * The privacy setting "Bildanalyse" (ADR-026, slice 3): whether a file may
 * go to the AI provider as it is. With "Nur Kontext verwenden" no image goes;
 * with "Bilder analysieren" every image goes except those in an excluded
 * folder, subfolders included. It applies to every file this extension
 * sends: the scaled copy for an alt text proposal and a file attached in the
 * chat, where a refused document goes as its extracted text only.
 *
 * The setting is read on every decision, so a decision follows the
 * configuration of the turn it is made in (ImageAnalysisTurnSettings); the
 * folders are resolved once per configured value.
 *
 * A folder is bound to its storage: `fileadmin/team/` names the folder
 * `/team/` of the storage whose base path is `fileadmin/` (in any case),
 * `2:/team/` the folder `/team/` of storage 2, and neither excludes `/team/`
 * of another storage. Folders are compared by their identifiers, without
 * asking the storage, so a folder that does not exist (yet) costs nothing.
 * An entry that names no storage, or climbs above a storage's root, excludes
 * nothing and is logged.
 */
class ImageAnalysisPolicy implements AltTextImagePolicyInterface
{
    /** The image was not sent: image analysis is switched off in the settings. */
    public const NOTE_ANALYSIS_OFF = 'analysisOff';

    /** The image was not sent: it lies in a folder whose images are never sent. */
    public const NOTE_EXCLUDED_FOLDER = 'excludedFolder';

    /**
     * The folders as resolved, keyed by the configured entries they were
     * resolved from, so a changed value is resolved anew and an unchanged one
     * is logged once.
     *
     * @var array<string, list<array{storage: int, identifier: string, caseSensitive: bool, label: string}>>
     */
    private array $resolved = [];

    public function __construct(
        private readonly ExtensionConfiguration $configuration,
        private readonly StorageRepository $storages,
        private readonly ?LoggerInterface $logger = null,
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

    public function refusalForFile(File $file): ?AltTextImageRefusal
    {
        if (!$this->configuration->isImageAnalysisEnabled()) {
            return new AltTextImageRefusal(self::NOTE_ANALYSIS_OFF);
        }

        $folder = $this->excludedFolder($file->getStorage()->getUid(), $file->getIdentifier());

        return $folder === null ? null : new AltTextImageRefusal(self::NOTE_EXCLUDED_FOLDER, $folder);
    }

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
     * `/teamwork/`.
     *
     * @return list<array{storage: int, identifier: string, caseSensitive: bool, label: string}>
     */
    private function excluded(): array
    {
        $entries = $this->configuration->getImageAnalysisExcludedFolders();
        $key     = implode(',', $entries);
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        if ($entries === []) {
            return $this->resolved[$key] = [];
        }

        $storages = [];
        foreach ($this->storages->findAll() as $storage) {
            $storages[$storage->getUid()] = $storage;
        }

        $excluded = [];
        foreach ($entries as $entry) {
            $folders = $this->resolve($entry, $storages);
            if ($folders === []) {
                $this->logger?->warning('The folder "{entry}" excluded from image analysis names no folder of a file storage; it excludes nothing. Write it as fileadmin/folder/ or as 1:/folder/.', ['entry' => $entry]);
            }

            array_push($excluded, ...$folders);
        }

        return $this->resolved[$key] = $excluded;
    }

    /**
     * One entry as folders: the storage first, by its uid or by its base
     * path at the start of the entry (in any case), then the rest of the
     * entry as a folder of that storage. Resolving in this order keeps
     * `fileadmin/../user_upload/x/` from naming another storage's folder.
     *
     * @param array<int, ResourceStorage> $storages
     *
     * @return list<array{storage: int, identifier: string, caseSensitive: bool, label: string}>
     */
    private function resolve(string $entry, array $storages): array
    {
        if (preg_match('/^(\d+):(.*)$/', $entry, $parts) === 1) {
            $storage    = $storages[(int) $parts[1]] ?? null;
            $identifier = $this->identifier($parts[2]);

            return $storage instanceof ResourceStorage && $identifier !== null ? [$this->folder($storage, $identifier)] : [];
        }

        $path    = trim($entry, '/') . '/';
        $folders = [];
        foreach ($storages as $storage) {
            $basePath = $this->publicBasePath($storage);
            if ($basePath === '' || !str_starts_with(mb_strtolower($path), mb_strtolower($basePath))) {
                continue;
            }

            $identifier = $this->identifier(substr($path, strlen($basePath)));
            if ($identifier !== null) {
                $folders[] = $this->folder($storage, $identifier);
            }
        }

        return $folders;
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

    /**
     * A folder identifier with a slash at either end (`/team/`, `/` for the
     * root), with `.` and `..` resolved; null for a path that climbs above
     * the storage's root.
     */
    private function identifier(string $path): ?string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }

                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? '/' : '/' . implode('/', $segments) . '/';
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
