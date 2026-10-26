<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\AltText;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Service\AltText\AltTextImageRefusal;
use Netresearch\NrMcpAgent\Service\AltText\ImageAnalysisPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * The privacy setting "Bildanalyse" (ADR-026, slice 3): no image with
 * "Nur Kontext verwenden"; with "Bilder analysieren" every image except
 * those in an excluded folder, subfolders included, bound to its storage.
 */
#[CoversClass(ImageAnalysisPolicy::class)]
final class ImageAnalysisPolicyTest extends TestCase
{
    /**
     * Storage 1 is fileadmin/, storage 2 a second local storage under
     * storage2/, storage 3 one outside the web root, storage 4 a storage
     * with case-insensitive identifiers under media/.
     *
     * @param list<string> $folders
     */
    private function policy(bool $analyse, array $folders = []): ImageAnalysisPolicy
    {
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('isImageAnalysisEnabled')->willReturn($analyse);
        $configuration->method('getImageAnalysisExcludedFolders')->willReturn($folders);

        $storages = $this->createStub(StorageRepository::class);
        $storages->method('findAll')->willReturn([
            $this->storage(1, 'Local', ['basePath' => 'fileadmin/', 'pathType' => 'relative']),
            $this->storage(2, 'Local', ['basePath' => '/storage2', 'pathType' => 'relative']),
            $this->storage(3, 'Local', ['basePath' => '/var/data/', 'pathType' => 'absolute']),
            $this->storage(4, 'Local', ['basePath' => 'media/', 'pathType' => 'relative'], false),
        ]);

        return new ImageAnalysisPolicy($configuration, $storages);
    }

    /**
     * @param array<string, string> $configuration
     */
    private function storage(int $uid, string $driver, array $configuration, bool $caseSensitive = true): ResourceStorage
    {
        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getUid')->willReturn($uid);
        $storage->method('getDriverType')->willReturn($driver);
        $storage->method('getConfiguration')->willReturn($configuration);
        $storage->method('usesCaseSensitiveIdentifiers')->willReturn($caseSensitive);

        return $storage;
    }

    private function file(int $storageUid, string $identifier): File
    {
        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getUid')->willReturn($storageUid);
        $file = $this->createStub(File::class);
        $file->method('getStorage')->willReturn($storage);
        $file->method('getIdentifier')->willReturn($identifier);

        return $file;
    }

    private function reference(int $storageUid, string $identifier): FileReference
    {
        $reference = $this->createStub(FileReference::class);
        $reference->method('getOriginalFile')->willReturn($this->file($storageUid, $identifier));

        return $reference;
    }

    #[Test]
    public function withImageAnalysisOnAnImageMayBeSent(): void
    {
        $policy = $this->policy(true);

        self::assertNull($policy->refusal($this->reference(1, '/team/anna.jpg')));
        self::assertTrue($policy->analysesImages());
    }

    #[Test]
    public function withImageAnalysisOffNoImageIsSent(): void
    {
        $policy = $this->policy(false, ['fileadmin/team/']);

        self::assertEquals(new AltTextImageRefusal(ImageAnalysisPolicy::NOTE_ANALYSIS_OFF), $policy->refusal($this->reference(1, '/news/press.jpg')));
        self::assertEquals(new AltTextImageRefusal(ImageAnalysisPolicy::NOTE_ANALYSIS_OFF), $policy->refusalForFile($this->file(1, '/team/anna.jpg')));
        self::assertFalse($policy->analysesImages());
    }

    #[Test]
    public function anImageInAnExcludedFolderIsNotSentAndTheFolderIsNamed(): void
    {
        $refusal = $this->policy(true, ['fileadmin/team/'])->refusal($this->reference(1, '/team/anna.jpg'));

        self::assertEquals(new AltTextImageRefusal(ImageAnalysisPolicy::NOTE_EXCLUDED_FOLDER, 'fileadmin/team/'), $refusal);
    }

    #[Test]
    public function anExcludedFolderTakesInItsSubfolders(): void
    {
        $refusal = $this->policy(true, ['fileadmin/team'])->refusalForFile($this->file(1, '/team/board/2026/anna.jpg'));

        self::assertEquals(new AltTextImageRefusal(ImageAnalysisPolicy::NOTE_EXCLUDED_FOLDER, 'fileadmin/team/'), $refusal);
    }

    /**
     * Images beside the excluded folder are sent: a folder whose name
     * starts the same, the parent folder, a folder of the same name lower
     * down, and the same folder in another storage.
     *
     * @return iterable<string, array{string, int, string}>
     */
    public static function imagesOutsideTheExcludedFolder(): iterable
    {
        yield 'a folder whose name starts the same' => ['fileadmin/team/', 1, '/teamwork/anna.jpg'];
        yield 'the parent folder' => ['fileadmin/team/', 1, '/anna.jpg'];
        yield 'a folder of the same name lower down' => ['fileadmin/team/', 1, '/about/team/anna.jpg'];
        yield 'the same folder in another storage' => ['fileadmin/team/', 2, '/team/anna.jpg'];
        yield 'the uid form, in another storage' => ['1:/team/', 2, '/team/anna.jpg'];
        yield 'a base path no storage has' => ['uploads/team/', 1, '/team/anna.jpg'];
        yield 'a storage uid that does not exist' => ['9:/team/', 1, '/team/anna.jpg'];
        yield 'a storage outside the web root, named by its path' => ['var/data/team/', 3, '/team/anna.jpg'];
        yield 'another case on a case-sensitive storage' => ['fileadmin/Team/', 1, '/team/anna.jpg'];
    }

    #[Test]
    #[DataProvider('imagesOutsideTheExcludedFolder')]
    public function anImageOutsideTheExcludedFolderIsSent(string $folder, int $storageUid, string $identifier): void
    {
        self::assertNull($this->policy(true, [$folder])->refusalForFile($this->file($storageUid, $identifier)));
    }

    /**
     * The storage's own forms: its base path, its uid with or without the
     * slashes, the root of a storage, and a storage outside the web root,
     * which only the uid form reaches and the card names by it.
     *
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function excludedFolders(): iterable
    {
        yield 'the base path of the second storage' => ['storage2/team/', 2, '/team/anna.jpg', 'storage2/team/'];
        yield 'the uid form' => ['1:/team/', 1, '/team/anna.jpg', 'fileadmin/team/'];
        yield 'the uid form without slashes' => ['1:team', 1, '/team/anna.jpg', 'fileadmin/team/'];
        yield 'a leading slash' => ['/fileadmin/team/', 1, '/team/anna.jpg', 'fileadmin/team/'];
        yield 'a whole storage' => ['fileadmin/', 1, '/news/press.jpg', 'fileadmin/'];
        yield 'a storage outside the web root' => ['3:/team/', 3, '/team/anna.jpg', '3:/team/'];
        yield 'another case on a case-insensitive storage' => ['media/Team/', 4, '/team/anna.jpg', 'media/Team/'];
    }

    #[Test]
    #[DataProvider('excludedFolders')]
    public function anExcludedFolderIsFoundInItsStorage(string $folder, int $storageUid, string $identifier, string $label): void
    {
        $refusal = $this->policy(true, [$folder])->refusalForFile($this->file($storageUid, $identifier));

        self::assertEquals(new AltTextImageRefusal(ImageAnalysisPolicy::NOTE_EXCLUDED_FOLDER, $label), $refusal);
    }

    #[Test]
    public function theFirstExcludedFolderThatHoldsTheImageIsNamed(): void
    {
        $refusal = $this->policy(true, ['fileadmin/news/', 'fileadmin/team/', 'fileadmin/'])->refusalForFile($this->file(1, '/team/anna.jpg'));

        self::assertEquals(new AltTextImageRefusal(ImageAnalysisPolicy::NOTE_EXCLUDED_FOLDER, 'fileadmin/team/'), $refusal);
    }

    /** A reference whose file cannot be read is left to the card's own note: no copy can be made of it. */
    #[Test]
    public function aReferenceWithoutAFileIsNotRefusedHere(): void
    {
        $reference = $this->createStub(FileReference::class);
        $reference->method('getOriginalFile')->willThrowException(new RuntimeException('gone'));

        self::assertNull($this->policy(true, ['fileadmin/'])->refusal($reference));
    }
}
