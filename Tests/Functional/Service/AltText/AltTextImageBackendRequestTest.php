<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Service\AltText;

use Netresearch\NrMcpAgent\Service\AltText\AltTextImage;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The model's copy of the image while a backend request runs. "Optimieren"
 * writes the card, and with it the note whether the model sees the image,
 * in a backend request; there the core's DeferredBackendImageProcessor
 * would only record the processing and leave the file to the first request
 * of its URL. The copy for the model is never requested by URL, so it has
 * to be processed at once, while the preview stays deferred.
 */
final class AltTextImageBackendRequestTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/nr_mcp_agent/Tests/Functional/Fixtures/Frontend/pattern.png' => 'fileadmin/page-templates.png',
    ];

    protected array $configurationToUseInTestInstance = [
        'GFX' => ['processor_enabled' => false],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/alt_text_image.csv');
        $this->setUpBackendUser(1);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    private function inBackendRequest(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://example.com/typo3/module/web/layout'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/typo3/index.php']))
            ->withAttribute('route', new Route('/module/web/layout', []));
    }

    #[Test]
    public function inABackendRequestTheModelGetsTheImageAndTheCardSaysNothingIsMissing(): void
    {
        $this->inBackendRequest();
        $image = $this->get(AltTextImage::class);

        self::assertSame('', $image->cardNote(1, true)['imageNote']);
        $block = $image->modelBlock(1, true);
        self::assertNotNull($block);
        self::assertStringStartsWith('data:image/png;base64,', $block['image_url']['url']);
    }

    #[Test]
    public function theCopyForTheModelLeavesTheRequestsProcessingDeferred(): void
    {
        $this->inBackendRequest();
        $context = $this->get(Context::class);

        $this->get(AltTextImage::class)->modelBlock(1, true);

        self::assertFalse($context->hasAspect('fileProcessing'));
    }

    #[Test]
    public function withoutARequestTheModelGetsTheImageToo(): void
    {
        $image = $this->get(AltTextImage::class);

        self::assertSame('', $image->cardNote(1, true)['imageNote']);
        self::assertNotNull($image->modelBlock(1, true));
    }
}
