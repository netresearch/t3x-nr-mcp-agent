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
 * image exactly as the core renders it.
 *
 * Two sites render the same content element with three images: one with an
 * alt text, one decorative with its own alt text, one decorative inheriting
 * the file list's. Site "a" uses the extension's image partial, site "b"
 * removes it again (WithoutOverride.typoscript) and shows the core's output.
 */
final class DecorativeImageRenderingTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist', 'fluid_styled_content'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/nr_mcp_agent/Tests/Functional/Fixtures/Frontend/pattern.png' => 'fileadmin/test/pattern.png',
    ];

    // No image processor in the test container: the image is used as it is.
    protected array $configurationToUseInTestInstance = [
        'GFX' => ['processor_enabled' => false],
    ];

    private const FIXTURES = 'EXT:nr_mcp_agent/Tests/Functional/Fixtures/Frontend/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/decorative_rendering.csv');
        $constants = ['EXT:fluid_styled_content/Configuration/TypoScript/constants.typoscript'];
        $setup     = ['EXT:fluid_styled_content/Configuration/TypoScript/setup.typoscript', self::FIXTURES . 'Page.typoscript'];
        $this->setUpFrontendRootPage(1, ['constants' => $constants, 'setup' => $setup]);
        $this->setUpFrontendRootPage(2, ['constants' => $constants, 'setup' => [...$setup, self::FIXTURES . 'WithoutOverride.typoscript']]);
        foreach (['a' => 1, 'b' => 2] as $identifier => $rootPageId) {
            $this->get(SiteWriter::class)->write($identifier, [
                'rootPageId' => $rootPageId,
                'base'       => 'https://' . $identifier . '.example/',
                'languages'  => [[
                    'languageId' => 0,
                    'title'      => 'Deutsch',
                    'locale'     => 'de_DE.UTF-8',
                    'base'       => '/',
                    'flag'       => 'de',
                ]],
            ]);
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

    #[Test]
    public function aDecorativeImageIsOutputWithAnEmptyAltText(): void
    {
        $images = $this->images('a');

        self::assertCount(3, $images);
        self::assertStringContainsString('alt="Unser Team"', $images[0]);
        self::assertStringContainsString('alt=""', $images[1]);
        self::assertStringNotContainsString('Ein Muster', $images[1]);
        self::assertStringContainsString('alt=""', $images[2]);
        self::assertStringNotContainsString('Text aus der Dateiliste', $images[2]);
    }

    /** The core alone falls back to the alt text: the partial is what makes the difference. */
    #[Test]
    public function withoutThePartialTheCoreOutputsTheAltText(): void
    {
        $images = $this->images('b');

        self::assertCount(3, $images);
        self::assertStringContainsString('alt="Ein Muster"', $images[1]);
        self::assertStringContainsString('alt="Text aus der Dateiliste"', $images[2]);
    }

    #[Test]
    public function anImageThatIsNotDecorativeIsOutputAsTheCoreOutputsIt(): void
    {
        self::assertSame($this->images('b')[0], $this->images('a')[0]);
    }

    /** The decorative image keeps what the core's image carries apart from the alt text. */
    #[Test]
    public function aDecorativeImageKeepsTheCoresOtherAttributes(): void
    {
        $decorative = $this->images('a')[1];
        $core       = $this->images('b')[1];

        foreach (['class', 'src', 'width', 'height', 'loading'] as $attribute) {
            preg_match('/\s' . $attribute . '="([^"]*)"/', $core, $expected);
            preg_match('/\s' . $attribute . '="([^"]*)"/', $decorative, $actual);
            self::assertSame($expected[1] ?? null, $actual[1] ?? null, $attribute);
        }
    }
}
