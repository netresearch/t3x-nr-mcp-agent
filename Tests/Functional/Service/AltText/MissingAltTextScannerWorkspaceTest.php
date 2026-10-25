<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Service\AltText;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Repository\MissingAltTextRepository;
use Netresearch\NrMcpAgent\Service\AltText\ImageFieldDiscovery;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltText;
use Netresearch\NrMcpAgent\Service\AltText\MissingAltTextScanner;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The "missing alt texts" check (ADR-026) in a workspace
 * (missing_alt_texts_workspace.csv): the workspace versions of the reference
 * and of the file list text count there, and only there.
 *
 * One content element with four image references:
 * 1. no alt text live; the file list text is set in the workspace;
 * 2. no alt text anywhere;
 * 3. no alt text live; the reference's alt text is set in the workspace;
 * 4. no alt text; the reference is deleted in the workspace.
 */
final class MissingAltTextScannerWorkspaceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist', 'workspaces'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/missing_alt_texts_workspace.csv');
    }

    /**
     * @return list<int> the open references as the admin sees them in the workspace
     */
    private function openReferences(int $workspace): array
    {
        $user = $this->setUpBackendUser(1);
        $user->setWorkspace($workspace);
        self::assertSame($workspace, $user->workspace);

        $scanner = new MissingAltTextScanner(
            new ImageFieldDiscovery(new ExtensionConfiguration()),
            new MissingAltTextRepository($this->get(ConnectionPool::class)),
            $this->get(Context::class),
        );

        return array_map(static fn (MissingAltText $hit): int => $hit->referenceUid, $scanner->scan($user)->hits);
    }

    #[Test]
    public function liveEveryReferenceIsOpen(): void
    {
        self::assertSame([1, 2, 3, 5], $this->openReferences(0));
    }

    #[Test]
    public function inTheWorkspaceItsVersionsDecide(): void
    {
        self::assertSame([2], $this->openReferences(1));
    }
}
