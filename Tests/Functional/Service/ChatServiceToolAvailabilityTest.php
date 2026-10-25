<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Service;

use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Tool\SetDecorativeImageTool;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Which tools the chat's runs may not call, asked through nr-llm's own policy
 * for the configuration the user's chat runs on (ADR-026): the two writers of
 * "Optimieren" ship disabled, and count as available once an administrator
 * enables them in nr-llm's Tools module.
 */
final class ChatServiceToolAvailabilityTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
        __DIR__ . '/../Fixtures/Extensions/alt_text_test',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => ['nr_mcp_agent' => ['llmTaskUid' => '1']],
    ];

    private const ALT_TEXT_TOOL = 'set_file_reference_alternative_text';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/missing_alt_texts.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_nrllm_configuration.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_nrllm_task.csv');
        $this->setUpBackendUser(2);
    }

    /**
     * @return list<string>
     */
    private function unavailable(): array
    {
        return $this->get(ChatService::class)->unavailableTools([self::ALT_TEXT_TOOL, SetDecorativeImageTool::NAME]);
    }

    #[Test]
    public function bothWritersAreUnavailableUntilEnabled(): void
    {
        self::assertSame([self::ALT_TEXT_TOOL, SetDecorativeImageTool::NAME], $this->unavailable());
    }

    #[Test]
    public function enabledWritersAreAvailable(): void
    {
        $state = $this->get(ToolStateRepository::class);
        $state->setEnabled(self::ALT_TEXT_TOOL, true);
        $state->setEnabled(SetDecorativeImageTool::NAME, true);

        self::assertSame([], $this->unavailable());
    }

    #[Test]
    public function aWriterDisabledAgainIsNamed(): void
    {
        $state = $this->get(ToolStateRepository::class);
        $state->setEnabled(self::ALT_TEXT_TOOL, true);
        $state->setEnabled(SetDecorativeImageTool::NAME, true);
        $state->setEnabled(SetDecorativeImageTool::NAME, false);

        self::assertSame([SetDecorativeImageTool::NAME], $this->unavailable());
    }
}
