<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Site\Set\SetRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * An installation without fluid_styled_content: the set for decorative images
 * stays valid, so TYPO3 does not log "Invalid set … Missing dependency" on
 * every boot.
 */
final class DecorativeImagesSetTest extends FunctionalTestCase
{
    private const SET = 'netresearch/nr-mcp-agent-decorative-images';

    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    #[Test]
    public function theSetIsValidWithoutFluidStyledContent(): void
    {
        $registry = $this->get(SetRegistry::class);

        self::assertFalse($registry->hasSet('typo3/fluid-styled-content'));
        self::assertArrayNotHasKey(self::SET, $registry->getInvalidSets());
        self::assertTrue($registry->hasSet(self::SET));
    }
}
