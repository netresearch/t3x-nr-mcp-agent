<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrMcpAgent\Domain\Repository\OpenPointRepository;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\OpenPoint\OpenPointTarget;
use Netresearch\NrMcpAgent\Service\OpenPoint\OpenPointTracker;
use Netresearch\NrMcpAgent\Service\OpenPoint\OpenPointVisibility;
use Netresearch\NrMcpAgent\Service\OpenPoint\VisibleOpenPoint;
use Netresearch\NrMcpAgent\Service\SkillProcessRunDetector;
use Netresearch\NrMcpAgent\Tool\ListOpenPointsTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Open points against a real database and real backend users (ADR-022):
 * one row per field, closed field by field, listed by a read tool only for a
 * page and tables the user may see, and wired into the chat.
 *
 * The fixture is the guided tools' (ADR-020): the editor may show page 20,
 * not page 30, and reads tt_content but not pages.
 */
final class OpenPointsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    private const EDITOR = 3;

    private const TABLE = 'tx_nrmcpagent_open_point';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/guided_tools.csv');
    }

    private function repository(): OpenPointRepository
    {
        return $this->get(OpenPointRepository::class);
    }

    private function rows(): int
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE)->count('uid', self::TABLE, []);
    }

    // ---- the store ---------------------------------------------------------

    #[Test]
    public function aSkippedWriteIsOneOpenPointPerField(): void
    {
        $this->repository()->record(7, 'pages', 20, new OpenPointTarget('tt_content', 100, ['header', 'bodytext']), self::EDITOR, 5);
        $this->repository()->record(7, 'pages', 20, new OpenPointTarget('tt_content', 100, ['bodytext']), self::EDITOR, 6);

        self::assertSame(2, $this->rows(), 'skipping the same field again stays one open point');
        self::assertSame(['bodytext', 'header'], array_column($this->repository()->findBySubject('pages', 20), 'field'));
    }

    #[Test]
    public function aWriteThatNamesNoFieldIsKeyedByTheRecord(): void
    {
        $this->repository()->record(7, 'pages', 20, new OpenPointTarget('tt_content', 100), self::EDITOR, 5);

        self::assertSame([''], array_column($this->repository()->findBySubject('pages', 20), 'field'));
    }

    #[Test]
    public function anAppliedWriteClosesOnlyItsRecordAndFields(): void
    {
        $repository = $this->repository();
        $repository->record(7, 'pages', 20, new OpenPointTarget('tt_content', 100, ['header', 'bodytext']), self::EDITOR, 5);
        $repository->record(8, 'pages', 20, new OpenPointTarget('tt_content', 100, ['bodytext']), self::EDITOR, 6);
        $repository->record(7, 'pages', 20, new OpenPointTarget('tt_content', 102, ['bodytext']), self::EDITOR, 5);
        $repository->record(7, 'pages', 20, new OpenPointTarget('tt_content', 100), self::EDITOR, 5);

        self::assertSame(2, $repository->close(new OpenPointTarget('tt_content', 100, ['bodytext'])), 'both processes\' bodytext points');

        $left = array_map(
            static fn(array $point): string => $point['targetUid'] . ':' . $point['field'],
            $repository->findBySubject('pages', 20),
        );
        sort($left);
        self::assertSame(['100:', '100:header', '102:bodytext'], $left);
    }

    #[Test]
    public function theListIsPerSubjectAndOptionallyPerProcess(): void
    {
        $repository = $this->repository();
        $repository->record(7, 'pages', 20, new OpenPointTarget('tt_content', 100, ['bodytext']), self::EDITOR, 5);
        $repository->record(8, 'pages', 20, new OpenPointTarget('tt_content', 100, ['header']), self::EDITOR, 6);
        $repository->record(7, 'pages', 30, new OpenPointTarget('tt_content', 101, ['bodytext']), self::EDITOR, 7);

        self::assertCount(2, $repository->findBySubject('pages', 20));
        self::assertSame(['header'], array_column($repository->findBySubject('pages', 20, 8), 'field'));
        self::assertCount(1, $repository->findBySubject('pages', 30));
    }

    // ---- the list tool -----------------------------------------------------

    private function list(array $arguments): \Netresearch\NrLlm\Domain\ValueObject\ToolResult
    {
        return $this->get(ListOpenPointsTool::class)->execute($arguments, ToolExecutionContext::fromBackendUser($this->setUpBackendUser(self::EDITOR)));
    }

    #[Test]
    public function nrLlmOffersTheListAsARead(): void
    {
        $tool = $this->get(ListOpenPointsTool::class);
        self::assertSame(ListOpenPointsTool::class, $this->get(ToolRegistry::class)->get(ListOpenPointsTool::NAME)::class);
        self::assertSame(ToolEffect::READ_ONLY, $tool->getEffect());

        $decision = $this->get(ToolCallPolicyInterface::class)->decide(ListOpenPointsTool::NAME, new LlmConfiguration(), $this->setUpBackendUser(self::EDITOR));
        self::assertTrue($decision->allowed);
        self::assertSame(ToolDenialReason::NONE, $decision->reason);
    }

    #[Test]
    public function theEditorSeesTheOpenPointsOfHerPage(): void
    {
        $this->repository()->record(7, 'pages', 20, new OpenPointTarget('tt_content', 100, ['bodytext']), self::EDITOR, 5);
        // The editor's group reads tt_content, not pages.
        $this->repository()->record(7, 'pages', 20, new OpenPointTarget('pages', 20, ['description']), self::EDITOR, 5);

        $result = $this->list(['pageUid' => 20]);

        self::assertFalse($result->isError);
        self::assertStringContainsString('- tt_content 100, field bodytext (skill 7, skipped ', $result->content);
        self::assertStringNotContainsString('description', $result->content);
        self::assertSame(2, $this->rows(), 'a read changes nothing');
    }

    #[Test]
    public function aPageWithoutOpenPointsSaysSo(): void
    {
        self::assertSame('Page 20 has no open points.', $this->list(['pageUid' => 20])->content);
    }

    /**
     * Pages beside the fixture's: 40 is outside the editor's mounts, 21 is in
     * her mount but deleted, 22 is below her mount without show permission
     * (30 is a mount point she may not show, which TYPO3 drops from her
     * mounts altogether).
     */
    private function addPages(): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('pages');
        $connection->insert('pages', ['uid' => 40, 'pid' => 0, 'title' => 'Außerhalb', 'doktype' => 1, 'perms_userid' => 1, 'perms_user' => 31, 'perms_everybody' => 1]);
        $connection->insert('pages', ['uid' => 21, 'pid' => 20, 'title' => 'Gelöscht', 'doktype' => 1, 'perms_userid' => 1, 'perms_user' => 31, 'perms_everybody' => 1, 'deleted' => 1]);
        $connection->insert('pages', ['uid' => 22, 'pid' => 20, 'title' => 'Gesperrt', 'doktype' => 1, 'perms_userid' => 1, 'perms_user' => 31, 'perms_everybody' => 0]);
    }

    /**
     * A page without show permission (30), one outside her mounts (40), a
     * deleted one (21) and one that does not exist (999) get the same answer,
     * although each has open points.
     */
    #[Test]
    #[DataProvider('pagesTheEditorMayNotShow')]
    public function aPageTheEditorMayNotShowIsRefused(int $pageUid): void
    {
        $this->addPages();
        $this->repository()->record(7, 'pages', $pageUid, new OpenPointTarget('tt_content', 100, ['bodytext']), self::EDITOR, 5);

        $result = $this->list(['pageUid' => $pageUid]);

        self::assertTrue($result->isError);
        self::assertSame('Error: no page with this uid that you may see.', $result->content);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function pagesTheEditorMayNotShow(): iterable
    {
        yield 'a mount point without show permission' => [30];
        yield 'below her mount without show permission' => [22];
        yield 'outside her mounts' => [40];
        yield 'deleted' => [21];
        yield 'missing' => [999];
    }

    /**
     * Element 102 is in a language her group does not allow, element 199 does
     * not exist (deleted, or never there): both are left out.
     */
    #[Test]
    public function pointsOnRecordsTheEditorMayNotReadAreLeftOut(): void
    {
        $this->repository()->record(7, 'pages', 20, new OpenPointTarget('tt_content', 100, ['header']), self::EDITOR, 5);
        $this->repository()->record(7, 'pages', 20, new OpenPointTarget('tt_content', 102, ['bodytext']), self::EDITOR, 5);
        $this->repository()->record(7, 'pages', 20, new OpenPointTarget('tt_content', 199, ['bodytext']), self::EDITOR, 5);

        $content = $this->list(['pageUid' => 20])->content;

        self::assertStringContainsString('tt_content 100, field header', $content);
        self::assertStringNotContainsString('tt_content 102', $content);
        self::assertStringNotContainsString('tt_content 199', $content);
    }

    /** The prompt and the tool decide by the same rule. */
    #[Test]
    public function theVisibilityRuleIsTheSameForThePrompt(): void
    {
        $this->repository()->record(7, 'pages', 20, new OpenPointTarget('tt_content', 100, ['header']), self::EDITOR, 5);
        $this->repository()->record(7, 'pages', 20, new OpenPointTarget('tt_content', 102, ['bodytext']), self::EDITOR, 5);
        $user = $this->setUpBackendUser(self::EDITOR);

        $points = $this->get(OpenPointVisibility::class)->forPage($user, 20, 7);

        self::assertSame([100], array_column($points ?? [], 'targetUid'));
        self::assertNull($this->get(OpenPointVisibility::class)->forPage($user, 30, 7));
    }

    #[Test]
    public function withoutAUserNothingIsListed(): void
    {
        $result = $this->get(ListOpenPointsTool::class)->execute(['pageUid' => 20], ToolExecutionContext::none());

        self::assertTrue($result->isError);
    }

    // ---- site-wide, for the dashboard -------------------------------------

    private function point(int $page, string $table, int $uid, string $field, int $crdate, int $skill = 7): void
    {
        $this->repository()->record($skill, 'pages', $page, new OpenPointTarget($table, $uid, $field !== '' ? [$field] : []), self::EDITOR, 5);
        $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            ['crdate' => $crdate],
            ['subject_uid' => $page, 'target_table' => $table, 'target_uid' => $uid, 'target_field' => $field, 'skill_uid' => $skill],
        );
    }

    /**
     * @return list<VisibleOpenPoint>
     */
    private function siteWide(int $limit): array
    {
        return $this->get(OpenPointVisibility::class)->visibleFor($this->setUpBackendUser(self::EDITOR), $limit);
    }

    /** An invisible point is left out before the limit counts, not after. */
    #[Test]
    public function aNewerInvisiblePointDoesNotShrinkTheLimit(): void
    {
        $this->point(20, 'tt_content', 100, 'header', 100);
        $this->point(20, 'tt_content', 100, 'bodytext', 200);
        $this->point(30, 'tt_content', 101, 'header', 300);

        $points = $this->siteWide(2);

        self::assertSame([['tt_content', 100, 'bodytext'], ['tt_content', 100, 'header']], array_map(
            static fn(VisibleOpenPoint $point): array => [$point->targetTable, $point->targetUid, $point->field],
            $points,
        ));
        self::assertSame(['bodytext'], array_map(static fn(VisibleOpenPoint $point): string => $point->field, $this->siteWide(1)), 'the limit cuts');
    }

    /**
     * The summary is in the user's backend language: template and labels.
     * This instance has no core language packs, so the header's label is
     * pointed at a label this extension ships in German.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function backendLanguages(): iterable
    {
        yield 'German' => ['de', 'KI-Chat auf „Über uns“ offen', '%s fehlt auf „Über uns“'];
        yield 'English' => ['', 'AI Chat open on “Über uns”', '%s is missing on “Über uns”'];
    }

    #[Test]
    #[DataProvider('backendLanguages')]
    public function eachPointCarriesItsPageLanguageSkillAndASummaryInTheUsersLanguage(string $lang, string $headerSummary, string $bodySummary): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrllm_skill')->insert('tx_nrllm_skill', [
            'uid' => 7, 'pid' => 0, 'source' => 3, 'identifier' => '3:seo/page-tour', 'name' => 'SEO', 'enabled' => 1,
        ]);
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->update('be_users', ['lang' => $lang], ['uid' => self::EDITOR]);
        $GLOBALS['TCA']['tt_content']['columns']['header']['label'] = 'LLL:EXT:nr_mcp_agent/Resources/Private/Language/locallang_chat.xlf:panel.title';
        $this->point(20, 'tt_content', 100, 'header', 100);
        $this->point(20, 'tt_content', 100, 'bodytext', 200);
        $bodyLabel = trim($this->get(LanguageServiceFactory::class)->create($lang !== '' ? $lang : 'en')->sL($GLOBALS['TCA']['tt_content']['columns']['bodytext']['label']));

        [$body, $header] = $this->siteWide(10);

        self::assertSame(20, $header->pageUid);
        self::assertSame(0, $header->languageUid);
        self::assertSame(7, $header->skillUid);
        self::assertSame('3:seo/page-tour', $header->skillIdentifier);
        self::assertSame(100, $header->crdate);
        self::assertNotSame('', $bodyLabel);
        self::assertSame($headerSummary, $header->summary, 'the header has a value');
        self::assertSame(sprintf($bodySummary, $bodyLabel), $body->summary, 'the fixture\'s bodytext is empty');
    }

    #[Test]
    public function aDeletedTargetAndAnotherLanguageAreLeftOut(): void
    {
        $this->point(20, 'tt_content', 100, 'header', 100);
        $this->point(20, 'tt_content', 102, 'header', 200);
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->update('tt_content', ['deleted' => 1], ['uid' => 100]);

        self::assertSame([], $this->siteWide(10));
    }

    /** A field the editor's group may not edit (`exclude`, no `non_exclude_fields`) stays hidden. */
    #[Test]
    public function aPointOnAnExcludedFieldIsLeftOut(): void
    {
        self::assertTrue((bool) ($GLOBALS['TCA']['tt_content']['columns']['hidden']['exclude'] ?? false), 'precondition: core excludes tt_content.hidden');
        $this->point(20, 'tt_content', 100, 'hidden', 300);
        $this->point(20, 'tt_content', 100, 'header', 100);

        self::assertSame(['header'], array_map(static fn(VisibleOpenPoint $point): string => $point->field, $this->siteWide(10)));
        self::assertSame(['header'], array_column($this->get(OpenPointVisibility::class)->forPage($this->setUpBackendUser(self::EDITOR), 20) ?? [], 'field'));
    }

    #[Test]
    public function withoutABackendUserThereIsNothing(): void
    {
        $this->point(20, 'tt_content', 100, 'header', 100);
        unset($GLOBALS['BE_USER']);

        self::assertSame([], $this->get(OpenPointVisibility::class)->visibleForCurrentUser(10));
        self::assertSame([], $this->siteWide(0));
    }

    // ---- wiring ------------------------------------------------------------

    /** The chat gets the tracker and the process detector through autowiring. */
    #[Test]
    public function theChatIsWiredToOpenPointsAndProcessDetection(): void
    {
        $service = $this->get(ChatService::class);

        self::assertInstanceOf(OpenPointTracker::class, (new ReflectionProperty(ChatService::class, 'openPoints'))->getValue($service));
        self::assertInstanceOf(SkillProcessRunDetector::class, (new ReflectionProperty(ChatService::class, 'processRuns'))->getValue($service));
    }
}
