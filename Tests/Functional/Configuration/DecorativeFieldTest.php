<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * "Decorative image" on an image reference (ADR-026): a checkbox on
 * sys_file_reference, in the palette of "Alternative text", stored per
 * reference and per language version.
 */
final class DecorativeFieldTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    #[Test]
    public function theReferenceHasADecorativeCheckbox(): void
    {
        $column = $GLOBALS['TCA']['sys_file_reference']['columns']['tx_nrmcpagent_decorative'] ?? null;

        self::assertIsArray($column);
        self::assertSame('check', $column['config']['type'] ?? null);
        self::assertSame(0, $column['config']['default'] ?? null);
        // An exclude field like "Alternative text": granted per group.
        self::assertTrue($column['exclude'] ?? false);
        // Set per language version, not copied from the default language.
        self::assertArrayNotHasKey('l10n_mode', $column);
    }

    #[Test]
    public function theCheckboxFollowsTheAlternativeTextInItsPalette(): void
    {
        $showitem = $GLOBALS['TCA']['sys_file_reference']['palettes']['imageoverlayPalette']['showitem'] ?? '';
        $fields   = array_values(array_filter(array_map(trim(...), explode(',', is_string($showitem) ? $showitem : ''))));

        $alternative = array_search('alternative', $fields, true);
        self::assertIsInt($alternative);
        self::assertSame('tx_nrmcpagent_decorative', $fields[$alternative + 1] ?? null);
    }

    /** Only on the use of an image, never on the file itself. */
    #[Test]
    public function theFileListHasNoDecorativeField(): void
    {
        self::assertArrayNotHasKey('tx_nrmcpagent_decorative', $GLOBALS['TCA']['sys_file_metadata']['columns'] ?? []);
    }

    #[Test]
    public function theDatabaseStoresTheMarkPerReference(): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference');
        $connection->insert('sys_file_reference', ['uid' => 1, 'uid_local' => 1, 'tablenames' => 'tt_content', 'fieldname' => 'image']);
        $connection->insert('sys_file_reference', ['uid' => 2, 'uid_local' => 1, 'tablenames' => 'tt_content', 'fieldname' => 'image', 'tx_nrmcpagent_decorative' => 1]);

        $marks = $connection->select(['uid', 'tx_nrmcpagent_decorative'], 'sys_file_reference', [], [], ['uid' => 'ASC'])->fetchAllKeyValue();

        self::assertSame([1 => 0, 2 => 1], array_map(intval(...), $marks));
    }
}
