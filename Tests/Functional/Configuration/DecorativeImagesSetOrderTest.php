<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Site\Set\SetDefinition;
use TYPO3\CMS\Core\Site\Set\SetRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * With fluid_styled_content installed, a site that lists only the set for
 * decorative images gets fluid_styled_content's set too, and before it:
 * fluid_styled_content's TypoScript starts with `lib.contentElement >`, which
 * would remove the set's partial path if it came second.
 */
final class DecorativeImagesSetOrderTest extends FunctionalTestCase
{
    private const SET = 'netresearch/nr-mcp-agent-decorative-images';

    private const FLUID_STYLED_CONTENT = 'typo3/fluid-styled-content';

    protected array $coreExtensionsToLoad = ['filelist', 'fluid_styled_content'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    #[Test]
    public function theSetAloneBringsFluidStyledContentFirst(): void
    {
        $sets = $this->get(SetRegistry::class)->getSets(self::SET);

        self::assertSame(
            [self::FLUID_STYLED_CONTENT, self::SET],
            array_values(array_filter(
                array_map(static fn (SetDefinition $set): string => $set->name, $sets),
                static fn (string $name): bool => in_array(
                    $name,
                    [self::FLUID_STYLED_CONTENT, self::SET],
                    true,
                ),
            )),
        );
    }
}
