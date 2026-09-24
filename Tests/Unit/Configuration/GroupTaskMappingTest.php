<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Configuration;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The nr-llm Task per backend group (NEXT-172, ADR-015).
 */
final class GroupTaskMappingTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    /**
     * @param array<string, string> $settings
     */
    private function configured(array $settings): ExtensionConfiguration
    {
        $mock = $this->createMock(Typo3ExtensionConfiguration::class);
        $mock->method('get')->with('nr_mcp_agent')->willReturn($settings);
        GeneralUtility::addInstance(Typo3ExtensionConfiguration::class, $mock);

        return new ExtensionConfiguration();
    }

    #[Test]
    public function withoutAMappingEveryUserGetsTheDefaultTask(): void
    {
        $config = $this->configured(['llmTaskUid' => '7']);

        self::assertSame(7, $config->getLlmTaskUid([3, 5]));
        self::assertSame(7, $config->getLlmTaskUid([]));
    }

    #[Test]
    public function aMappedGroupGetsItsTask(): void
    {
        $config = $this->configured(['llmTaskUid' => '7', 'groupTaskMapping' => '3:12, 5:14']);

        self::assertSame(14, $config->getLlmTaskUid([5]));
        self::assertSame(12, $config->getLlmTaskUid([3]));
    }

    #[Test]
    public function theFirstMatchingPairInTheConfiguredOrderWins(): void
    {
        $config = $this->configured(['llmTaskUid' => '7', 'groupTaskMapping' => '5:14,3:12']);

        // The user is in both groups; the order of the setting decides, not
        // the order of the user's groups.
        self::assertSame(14, $config->getLlmTaskUid([3, 5]));
    }

    #[Test]
    public function aUserInNoMappedGroupFallsBackToTheDefault(): void
    {
        $config = $this->configured(['llmTaskUid' => '7', 'groupTaskMapping' => '3:12']);

        self::assertSame(7, $config->getLlmTaskUid([4]));
    }

    #[Test]
    public function aMappingWorksWithoutADefaultTask(): void
    {
        $config = $this->configured(['llmTaskUid' => '0', 'groupTaskMapping' => '3:12']);

        self::assertSame(12, $config->getLlmTaskUid([3]));
        self::assertSame(0, $config->getLlmTaskUid([4]));
    }

    #[Test]
    public function malformedPairsAreSkippedAndTheRestStillApplies(): void
    {
        $config = $this->configured(['groupTaskMapping' => 'x:1, 3:, :4, 0:9, 3:-2, 6:8:1, 5:14']);

        self::assertSame([[5, 14]], $config->getGroupTaskMapping());
    }

    #[Test]
    public function withoutAnExplicitListTheCurrentBackendUsersEffectiveGroupsDecide(): void
    {
        $config = $this->configured(['llmTaskUid' => '7', 'groupTaskMapping' => '9:21']);

        $backendUser = $this->createMock(BackendUserAuthentication::class);
        // userGroupsUID holds the subgroups too: 9 is inherited here.
        $backendUser->userGroupsUID = [2, 9];
        $GLOBALS['BE_USER'] = $backendUser;

        self::assertSame(21, $config->getLlmTaskUid());
    }

    #[Test]
    public function withoutABackendUserOnlyTheDefaultApplies(): void
    {
        $config = $this->configured(['llmTaskUid' => '7', 'groupTaskMapping' => '9:21']);
        unset($GLOBALS['BE_USER']);

        self::assertSame(7, $config->getLlmTaskUid());
    }
}
