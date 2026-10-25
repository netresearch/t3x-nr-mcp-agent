<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Service\AltText;

use Netresearch\NrMcpAgent\Service\AltText\ReferenceWriteAccess;
use Netresearch\NrMcpAgent\Service\AltText\WritableReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The rights of the records that own an image through an inline field
 * (ADR-026), against the fixture of the list of missing alt texts
 * (missing_alt_texts.csv): reference 27 is the image of carousel item 1,
 * which belongs to content element 116. The editor may change it, until the
 * content element is locked or of a type the editor may not use; the list
 * hides it then, and the writer refuses it, for the alt text and for the mark
 * alike.
 */
final class ReferenceWriteAccessTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
        __DIR__ . '/../../Fixtures/Extensions/alt_text_test',
    ];

    private const EDITOR = 2;

    private const CAROUSEL_IMAGE = 27;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/missing_alt_texts.csv');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function columns(): iterable
    {
        yield 'the alt text' => ['alternative'];
        yield 'the decorative mark' => ['tx_nrmcpagent_decorative'];
    }

    #[Test]
    #[DataProvider('columns')]
    public function theEditorMayChangeTheImageOfACarouselItem(string $column): void
    {
        $result = $this->get(ReferenceWriteAccess::class)->resolve($this->setUpBackendUser(self::EDITOR), self::CAROUSEL_IMAGE, $column);

        self::assertInstanceOf(WritableReference::class, $result, is_string($result) ? $result : '');
        self::assertSame('tx_alttexttest_child', $result->table);
    }

    #[Test]
    #[DataProvider('columns')]
    public function aLockedOuterContentElementRefusesTheImage(string $column): void
    {
        $this->getConnectionPool()->getConnectionForTable('tt_content')->update('tt_content', ['editlock' => 1], ['uid' => 116]);

        $result = $this->get(ReferenceWriteAccess::class)->resolve($this->setUpBackendUser(self::EDITOR), self::CAROUSEL_IMAGE, $column);

        self::assertSame(ReferenceWriteAccess::NOT_PERMITTED, $result);
    }

    /**
     * A container of content elements, as an extension declares it: an
     * inline field of tt_content on tt_content (gridelements, for example),
     * here content element 118 holding element 101, and 118 locked.
     */
    private function containContentElement101InLocked118(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['tx_alttexttest_children_grid'] = [
            'label'  => 'Children',
            'config' => ['type' => 'inline', 'foreign_table' => 'tt_content', 'foreign_field' => 'tx_alttexttest_container'],
        ];
        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $connection->update('tt_content', ['tx_alttexttest_container' => 118], ['uid' => 101]);
        $connection->update('tt_content', ['editlock' => 1], ['uid' => 118]);
    }

    /**
     * A content element inside a container is edited on its own: the locked
     * container does not refuse the image of the element (reference 10).
     */
    #[Test]
    #[DataProvider('columns')]
    public function aLockedContainerOfTheSameTableDoesNotRefuseTheImage(string $column): void
    {
        $this->containContentElement101InLocked118();

        $result = $this->get(ReferenceWriteAccess::class)->resolve($this->setUpBackendUser(self::EDITOR), 10, $column);

        self::assertInstanceOf(WritableReference::class, $result, is_string($result) ? $result : '');
        self::assertSame(['tt_content', 101], [$result->table, (int) ($result->record['uid'] ?? 0)]);
    }

    #[Test]
    #[DataProvider('columns')]
    public function anOuterContentElementOfAForbiddenTypeRefusesTheImage(string $column): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_groups')->update(
            'be_groups',
            ['explicit_allowdeny' => 'tt_content:CType:textpic,tt_content:CType:image,tt_content:CType:text,tt_content:CType:textmedia'],
            ['uid' => 1],
        );

        $result = $this->get(ReferenceWriteAccess::class)->resolve($this->setUpBackendUser(self::EDITOR), self::CAROUSEL_IMAGE, $column);

        self::assertSame(ReferenceWriteAccess::NOT_PERMITTED, $result);
    }
}
