<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\AltText;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Service\AltText\ImageField;
use Netresearch\NrMcpAgent\Service\AltText\ImageFieldDiscovery;
use Netresearch\NrMcpAgent\Service\AltText\InlineOwner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The record types of the "missing alt texts" check come from the TCA at
 * runtime (ADR-026): every column of type `file`, nothing configured per
 * extension.
 */
final class ImageFieldDiscoveryTest extends TestCase
{
    /** @var array<mixed> */
    private array $tcaBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tcaBackup = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $GLOBALS['TCA']  = [
            'pages' => ['columns' => [
                'media'    => ['config' => ['type' => 'file']],
                'og_image' => ['config' => ['type' => 'file']],
                'title'    => ['config' => ['type' => 'input']],
            ]],
            'tt_content' => ['columns' => [
                'image'      => ['config' => ['type' => 'file']],
                'assets'     => ['config' => ['type' => 'file']],
                'news_owner' => ['config' => ['type' => 'passthrough']],
            ]],
            'tx_news_domain_model_news' => ['columns' => [
                'fal_media'        => ['config' => ['type' => 'file']],
                'content_elements' => ['config' => ['type' => 'inline', 'foreign_table' => 'tt_content', 'foreign_field' => 'tx_news_related_news']],
                'related'          => ['config' => ['type' => 'inline', 'foreign_table' => 'tt_content', 'MM' => 'tx_news_mm', 'foreign_field' => 'x']],
            ]],
            'tx_shop_product' => ['columns' => [
                'images' => ['config' => ['type' => 'file']],
                'parts'  => ['config' => ['type' => 'inline', 'foreign_table' => 'tx_shop_part', 'foreign_field' => 'parent', 'foreign_table_field' => 'parent_table']],
            ]],
            'sys_file_metadata'  => ['columns' => ['thumbnail' => ['config' => ['type' => 'file']]]],
            'sys_file_reference' => ['columns' => ['uid_local' => ['config' => ['type' => 'group']]]],
        ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->tcaBackup;
        parent::tearDown();
    }

    /**
     * @param list<string> $tables
     * @param list<string> $fields
     */
    private function discovery(array $tables = [], ?array $fields = null): ImageFieldDiscovery
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAltTextExcludedTables')->willReturn($tables);
        $config->method('getAltTextExcludedFields')->willReturn($fields ?? ['pages.og_image']);

        return new ImageFieldDiscovery($config);
    }

    /**
     * @param list<ImageField> $fields
     *
     * @return list<string>
     */
    private function names(array $fields): array
    {
        return array_map(static fn (ImageField $field): string => $field->table . '.' . $field->field, $fields);
    }

    #[Test]
    public function everyFileFieldOfEveryTableIsFound(): void
    {
        self::assertSame(
            ['pages.media', 'tt_content.assets', 'tt_content.image', 'tx_news_domain_model_news.fal_media', 'tx_shop_product.images'],
            $this->names($this->discovery()->fields()),
        );
    }

    #[Test]
    public function excludedTablesAndFieldsAreLeftOut(): void
    {
        $discovery = $this->discovery(['tx_shop_product'], ['tt_content.assets']);

        self::assertSame(
            ['pages.media', 'pages.og_image', 'tt_content.image', 'tx_news_domain_model_news.fal_media'],
            $this->names($discovery->fields()),
        );
        self::assertTrue($discovery->isExcludedTable('tx_shop_product'));
        self::assertFalse($discovery->isExcludedTable('tt_content'));
    }

    #[Test]
    public function theFieldsAreGroupedByTable(): void
    {
        self::assertSame(
            ['pages' => ['media'], 'tt_content' => ['assets', 'image'], 'tx_news_domain_model_news' => ['fal_media'], 'tx_shop_product' => ['images']],
            $this->discovery()->fieldsByTable(),
        );
    }

    /** A content element inside a news record names the record in its foreign field; an MM relation names none. */
    #[Test]
    public function theOwnersOfATableAreItsInlineParentsWithAForeignField(): void
    {
        self::assertEquals(
            [new InlineOwner('tx_news_domain_model_news', 'content_elements', 'tx_news_related_news')],
            $this->discovery()->ownersOf('tt_content'),
        );
        self::assertEquals(
            [new InlineOwner('tx_shop_product', 'parts', 'parent', 'parent_table')],
            $this->discovery()->ownersOf('tx_shop_part'),
        );
        self::assertSame([], $this->discovery()->ownersOf('pages'));
    }

    #[Test]
    public function withoutTcaNothingIsFound(): void
    {
        $GLOBALS['TCA'] = null;

        self::assertSame([], $this->discovery()->fields());
        self::assertSame([], $this->discovery()->ownersOf('tt_content'));
    }
}
