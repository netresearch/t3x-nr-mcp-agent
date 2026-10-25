<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Frontend;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A decorative image (ADR-026) in fluid_styled_content's output: alt="",
 * whatever alt text the reference or the file list carries; every other
 * image exactly as the core renders it. A content rendering of its own keeps
 * its own image partial.
 *
 * Six sites render the same content element with three images: one with an
 * alt text, one decorative with its own alt text, one decorative inheriting
 * the file list's.
 *
 * - "template": a sys_template record with fluid_styled_content's static template
 * - "set": the site sets typo3/fluid-styled-content and the extension's set
 * - "core": the site set typo3/fluid-styled-content alone
 * - "own": a set with a content rendering and an image partial of its own,
 *   like bootstrap_package (fixture extension alt_text_render)
 * - "import": a sys_template record that imports fluid_styled_content's
 *   TypoScript and then the extension's set setup, as documented
 * - "staticown": fluid_styled_content's static template followed by the
 *   own content rendering and the documented `partialRootPaths.5 >`
 */
final class DecorativeImageRenderingTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist', 'fluid_styled_content'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
        __DIR__ . '/../Fixtures/Extensions/alt_text_render',
    ];

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/nr_mcp_agent/Tests/Functional/Fixtures/Frontend/pattern.png' => 'fileadmin/test/pattern.png',
    ];

    // No image processor in the test container: the image is used as it is.
    protected array $configurationToUseInTestInstance = [
        'GFX' => ['processor_enabled' => false],
    ];

    private const SITES = [
        'template'  => [1, []],
        'set'       => [2, ['typo3/fluid-styled-content', 'netresearch/nr-mcp-agent-decorative-images', 'alt-text-render/page']],
        'core'      => [3, ['typo3/fluid-styled-content', 'alt-text-render/page']],
        'own'       => [4, ['alt-text-render/own-partial']],
        'import'    => [5, []],
        'staticown' => [6, []],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/decorative_rendering.csv');
        foreach (self::SITES as $identifier => [$rootPageId, $sets]) {
            $configuration = [
                'rootPageId' => $rootPageId,
                'base'       => 'https://' . $identifier . '.example/',
                'languages'  => [[
                    'languageId' => 0,
                    'title'      => 'Deutsch',
                    'locale'     => 'de_DE.UTF-8',
                    'base'       => '/',
                    'flag'       => 'de',
                ]],
            ];
            if ($sets !== []) {
                $configuration['dependencies'] = $sets;
            }

            $this->get(SiteWriter::class)->write($identifier, $configuration);
        }
    }

    /**
     * @return list<string> the img tags of the page, in order
     */
    private function images(string $site): array
    {
        $html = (string) $this->executeFrontendSubRequest(new InternalRequest('https://' . $site . '.example/'))->getBody();
        preg_match_all('/<img\b[^>]*>/', $html, $matches);

        return $matches[0];
    }

    /**
     * @param list<string> $images
     */
    private function assertDecorativeImagesHaveAnEmptyAltText(array $images): void
    {
        self::assertCount(3, $images);
        self::assertStringContainsString('alt="Unser Team"', $images[0]);
        self::assertStringContainsString('alt=""', $images[1]);
        self::assertStringNotContainsString('Ein Muster', $images[1]);
        self::assertStringContainsString('alt=""', $images[2]);
        self::assertStringNotContainsString('Text aus der Dateiliste', $images[2]);
        // W3C H67: a decorative image carries no title either, neither the
        // reference's nor, through f:image's fallback, the file list's.
        self::assertDoesNotMatchRegularExpression('/\\stitle="[^"]+"/', $images[1]);
        self::assertDoesNotMatchRegularExpression('/\\stitle="[^"]+"/', $images[2]);
    }

    #[Test]
    public function aSysTemplateWithFluidStyledContentOutputsADecorativeImageWithAnEmptyAltText(): void
    {
        $this->assertDecorativeImagesHaveAnEmptyAltText($this->images('template'));
    }

    #[Test]
    public function anImportedFluidStyledContentWithTheImportedSetupOutputsADecorativeImageWithAnEmptyAltText(): void
    {
        $this->assertDecorativeImagesHaveAnEmptyAltText($this->images('import'));
    }

    /** The static template, then an own rendering: the documented line gives it its partial back. */
    #[Test]
    public function anOwnRenderingAfterTheStaticTemplateKeepsItsPartialWithTheDocumentedLine(): void
    {
        $images = $this->images('staticown');

        self::assertCount(3, $images);
        foreach ($images as $image) {
            self::assertStringContainsString('data-own-partial="1"', $image);
        }
    }

    #[Test]
    public function theSiteSetOutputsADecorativeImageWithAnEmptyAltText(): void
    {
        $this->assertDecorativeImagesHaveAnEmptyAltText($this->images('set'));
    }

    /** Without the extension's set the core falls back to the alt text: the set is what makes the difference. */
    #[Test]
    public function withoutTheSiteSetTheCoreOutputsTheAltText(): void
    {
        $images = $this->images('core');

        self::assertCount(3, $images);
        self::assertStringContainsString('alt="Ein Muster"', $images[1]);
        self::assertStringContainsString('alt="Text aus der Dateiliste"', $images[2]);
        self::assertStringContainsString('title="Muster-Titel"', $images[1]);
        self::assertStringContainsString('title="Titel aus der Dateiliste"', $images[2]);
    }

    /** A content rendering of its own keeps its image partial for every image. */
    #[Test]
    public function aContentRenderingOfItsOwnKeepsItsImagePartial(): void
    {
        $images = $this->images('own');

        self::assertCount(3, $images);
        foreach ($images as $image) {
            self::assertStringContainsString('data-own-partial="1"', $image);
        }

        self::assertStringContainsString('alt="Ein Muster"', $images[1]);
    }

    #[Test]
    public function anImageThatIsNotDecorativeIsOutputAsTheCoreOutputsIt(): void
    {
        $core = $this->images('core')[0];

        self::assertSame($core, $this->images('set')[0]);
        self::assertSame($core, $this->images('template')[0]);
        self::assertSame($core, $this->images('import')[0]);
    }

    /** The decorative image keeps what the core's image carries apart from the alt text. */
    #[Test]
    public function aDecorativeImageKeepsTheCoresOtherAttributes(): void
    {
        $core = $this->images('core')[1];

        foreach (['set', 'template', 'import'] as $site) {
            $decorative = $this->images($site)[1];
            foreach (['class', 'src', 'width', 'height', 'loading'] as $attribute) {
                preg_match('/\s' . $attribute . '="([^"]*)"/', $core, $expected);
                preg_match('/\s' . $attribute . '="([^"]*)"/', $decorative, $actual);
                self::assertSame($expected[1] ?? null, $actual[1] ?? null, $site . ' ' . $attribute);
            }
        }
    }
}
