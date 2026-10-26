<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Service\AltText;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Service\AltText\AltTextImage;
use Netresearch\NrMcpAgent\Service\AltText\ImageAnalysisPolicy;
use Netresearch\NrMcpAgent\Service\ChatService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The privacy setting "Bildanalyse" on real files in two storages (ADR-026,
 * slice 3): the card's note and what the model gets agree in each case.
 *
 * Reference 1 is fileadmin/team/board/anna.png, reference 2
 * fileadmin/teamwork/flyer.png, reference 3 media/team/ben.png, in a second
 * storage.
 */
final class ImageAnalysisSettingTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected array $configurationToUseInTestInstance = [
        'GFX' => ['processor_enabled' => false],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['fileadmin/team/board/anna.png', 'fileadmin/teamwork/flyer.png', 'media/team/ben.png'] as $path) {
            $target = $this->instancePath . '/' . $path;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0o775, true);
            }

            copy(__DIR__ . '/../../Fixtures/Frontend/pattern.png', $target);
        }

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/alt_text_image_analysis.csv');
        $this->setUpBackendUser(1);
    }

    /**
     * The image service with the setting as given, read the way the
     * extension reads it: from the extension configuration.
     *
     * @param array<string, string> $settings
     */
    private function image(array $settings): AltTextImage
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_mcp_agent'] = $settings;

        return new AltTextImage(
            $this->get(ResourceFactory::class),
            new ImageAnalysisPolicy(new ExtensionConfiguration(), $this->get(StorageRepository::class)),
            $this->get(Context::class),
        );
    }

    /** Without a setting, as installed: the image goes to the model, and the card says nothing is missing. */
    #[Test]
    public function asInstalledTheImageIsSent(): void
    {
        $image = $this->get(AltTextImage::class);

        self::assertSame(['imageNote' => ''], $image->cardNote(1, true));
        self::assertNotNull($image->modelBlock(1, true));
    }

    /**
     * The chat's attachments are decided by the same policy: the container
     * hands it to ChatService, whose parameter is optional, so a missing
     * injection would send attached images without any error.
     */
    #[Test]
    public function theChatGetsThePolicyFromTheContainer(): void
    {
        $property = new ReflectionProperty(ChatService::class, 'imagePolicy');

        self::assertInstanceOf(ImageAnalysisPolicy::class, $property->getValue($this->get(ChatService::class)));
    }

    #[Test]
    public function withContextOnlyNoImageIsSentAndTheCardSaysTheAnalysisIsOff(): void
    {
        $image = $this->image(['imageAnalysis' => 'contextOnly']);

        foreach ([1, 2, 3] as $reference) {
            self::assertSame(['imageNote' => ImageAnalysisPolicy::NOTE_ANALYSIS_OFF], $image->cardNote($reference, true));
            self::assertNull($image->modelBlock($reference, true));
        }
    }

    #[Test]
    public function anImageInASubfolderOfAnExcludedFolderIsNotSentAndTheCardNamesTheFolder(): void
    {
        $image = $this->image(['imageAnalysis' => 'analyse', 'imageAnalysisExcludedFolders' => 'fileadmin/team/']);

        self::assertSame(['imageNote' => ImageAnalysisPolicy::NOTE_EXCLUDED_FOLDER, 'imageNoteFolder' => 'fileadmin/team/'], $image->cardNote(1, true));
        self::assertNull($image->modelBlock(1, true));
    }

    /** fileadmin/team/ neither takes in fileadmin/teamwork/ nor the folder team/ of the second storage. */
    #[Test]
    public function imagesBesideTheExcludedFolderAreSent(): void
    {
        $image = $this->image(['imageAnalysisExcludedFolders' => 'fileadmin/team/']);

        self::assertSame(['imageNote' => ''], $image->cardNote(2, true));
        self::assertNotNull($image->modelBlock(2, true));
        self::assertSame(['imageNote' => ImageAnalysisPolicy::NOTE_EXCLUDED_FOLDER, 'imageNoteFolder' => 'media/team/'], $this->image(['imageAnalysisExcludedFolders' => '2:/team/'])->cardNote(3, true));
        self::assertSame(['imageNote' => ''], $this->image(['imageAnalysisExcludedFolders' => '2:/team/'])->cardNote(1, true));
    }
}
