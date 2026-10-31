<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\RunStateRepository;
use Netresearch\NrMcpAgent\EventListener\PageModuleHighlight;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\GuidedStateLinker;
use Netresearch\NrMcpAgent\Service\OpenPoint\OpenPointTarget;
use Netresearch\NrMcpAgent\Tool\HighlightElementTool;
use Netresearch\NrMcpAgent\Tool\SetProgressTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The guided-state tools against a real database and real backend users
 * (ADR-020): who may call them on what, that a repeated call converges on one
 * row, that the chat takes a run's state over only for its own user and page,
 * and that nr-llm offers the tools to an editor on a cloud configuration.
 *
 * The fixture has an editor whose group mounts two pages and reads
 * tt_content in the default language only: page 20 she may show, page 30
 * she may not (no "everybody" permission), and element 102 is in a language
 * her group does not allow. Of the excluded page properties her group allows
 * `description` only.
 */
final class GuidedToolsTest extends FunctionalTestCase
{
    // nr_mcp_agent depends on filelist (the FAL picker's element browser).
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    private const EDITOR = 3;

    private const RUN = '7f0c6a52-6d1e-4b8f-9a35-0c2f7b1e4d10';

    private const LABEL = 'Über uns · Deutsch';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/guided_tools.csv');
    }

    private function editorContext(?AgentRunReference $run = new AgentRunReference(1, self::RUN)): ToolExecutionContext
    {
        $user    = $this->setUpBackendUser(self::EDITOR);
        $context = ToolExecutionContext::fromBackendUser($user);

        return new ToolExecutionContext($context->actor, $context->backendUser, $run);
    }

    /**
     * @param class-string<ToolInterface> $class
     * @param array<string, mixed>        $arguments
     */
    private function call(string $class, array $arguments, ?ToolExecutionContext $context = null): ToolResult
    {
        $tool = $this->get($class);
        self::assertInstanceOf(ToolInterface::class, $tool);

        return $tool->execute($arguments, $context ?? $this->editorContext());
    }

    private function rows(string $table): int
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable($table)->count('uid', $table, []);
    }

    /**
     * @return iterable<string, array{class-string<ToolInterface>}>
     */
    public static function tools(): iterable
    {
        yield 'set progress' => [SetProgressTool::class];
        yield 'highlight element' => [HighlightElementTool::class];
    }

    /**
     * Each tool reaches the model of an ordinary editor on a cloud provider:
     * registered, enabled by default, not admin-only, and of a data class the
     * least trusted zone permits. Without the declared data class, nr-llm
     * would withhold an unknown group's tools from every cloud provider.
     *
     * @param class-string<ToolInterface> $class
     */
    #[Test]
    #[DataProvider('tools')]
    public function nrLlmOffersTheToolToAnEditorOnACloudProvider(string $class): void
    {
        $name = $this->get($class)->getSpec()->name;
        self::assertSame($class, $this->get(ToolRegistry::class)->get($name)::class);

        $user     = $this->setUpBackendUser(self::EDITOR);
        $decision = $this->get(ToolCallPolicyInterface::class)->decide($name, new LlmConfiguration(), $user);

        self::assertSame(TrustZone::EXTERNAL_GLOBAL, $decision->zone, 'a configuration without a provider counts as the least trusted zone');
        self::assertTrue($decision->allowed);
        self::assertSame(ToolDenialReason::NONE, $decision->reason);
    }

    /**
     * Without a user or outside a run there is nothing to attach the state
     * to, and nothing is written.
     *
     * @param class-string<ToolInterface> $class
     */
    #[Test]
    #[DataProvider('tools')]
    public function withoutAUserNothingIsWritten(string $class): void
    {
        $result = $this->call($class, ['label' => 'x', 'current' => 1, 'total' => 2, 'contentUid' => 100], ToolExecutionContext::none());

        self::assertTrue($result->isError);
        self::assertSame(0, $this->rows('tx_nrmcpagent_run_state'));
    }

    #[Test]
    public function progressOutsideARunIsRefused(): void
    {
        $result = $this->call(SetProgressTool::class, ['label' => self::LABEL, 'current' => 1, 'total' => 5], $this->editorContext(null));

        self::assertTrue($result->isError);
        self::assertSame(0, $this->rows('tx_nrmcpagent_run_state'));
    }

    #[Test]
    public function theSameProgressTwiceIsOneRow(): void
    {
        $arguments = ['label' => self::LABEL, 'current' => 2, 'total' => 5];
        self::assertFalse($this->call(SetProgressTool::class, $arguments)->isError);
        self::assertFalse($this->call(SetProgressTool::class, $arguments)->isError);

        self::assertSame(1, $this->rows('tx_nrmcpagent_run_state'));
        self::assertSame(
            ['label' => self::LABEL, 'current' => 2, 'total' => 5, 'completed' => false],
            $this->get(RunStateRepository::class)->find(self::RUN, self::EDITOR)['progress'] ?? null,
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidProgress(): iterable
    {
        yield 'no label' => [['label' => ' ', 'current' => 1, 'total' => 5]];
        yield 'no points' => [['label' => 'x', 'current' => 0, 'total' => 0]];
        yield 'past the end' => [['label' => 'x', 'current' => 6, 'total' => 5]];
        yield 'negative' => [['label' => 'x', 'current' => -1, 'total' => 5]];
        yield 'not a number' => [['label' => 'x', 'current' => 'zwei', 'total' => 5]];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('invalidProgress')]
    public function invalidProgressIsRefused(array $arguments): void
    {
        self::assertTrue($this->call(SetProgressTool::class, $arguments)->isError);
        self::assertSame(0, $this->rows('tx_nrmcpagent_run_state'));
    }

    #[Test]
    public function anElementTheEditorMaySeeIsHighlighted(): void
    {
        self::assertFalse($this->call(HighlightElementTool::class, ['contentUid' => 100])->isError);

        self::assertSame(
            ['table' => 'tt_content', 'uid' => 100, 'pid' => 20],
            $this->get(RunStateRepository::class)->find(self::RUN, self::EDITOR)['highlight'] ?? null,
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function elementsTheEditorMayNotSee(): iterable
    {
        yield 'on a page she may not show' => [101];
        yield 'in a language she may not edit' => [102];
        yield 'that does not exist' => [999];
    }

    /** One answer for all three, and nothing is written. */
    #[Test]
    #[DataProvider('elementsTheEditorMayNotSee')]
    public function anElementTheEditorMayNotSeeIsRefused(int $uid): void
    {
        $result = $this->call(HighlightElementTool::class, ['contentUid' => $uid]);

        self::assertTrue($result->isError);
        self::assertSame('Error: no content element with this uid that you may see.', $result->content);
        self::assertSame(0, $this->rows('tx_nrmcpagent_run_state'));
    }

    /**
     * A page property (NEXT-221): the run state names the column, not the
     * page; the chat fills in the conversation's page when it takes it over.
     *
     * @return iterable<string, array{string}>
     */
    public static function pagePropertiesTheEditorMayEdit(): iterable
    {
        yield 'an excluded field her group allows' => ['description'];
        yield 'a field that is not excluded' => ['title'];
    }

    #[Test]
    #[DataProvider('pagePropertiesTheEditorMayEdit')]
    public function aPagePropertyTheEditorMayEditIsNamed(string $field): void
    {
        self::assertFalse($this->call(HighlightElementTool::class, ['pageField' => $field])->isError);

        self::assertSame(
            ['table' => 'pages', 'field' => $field],
            $this->get(RunStateRepository::class)->find(self::RUN, self::EDITOR)['highlight'] ?? null,
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function pagePropertiesTheEditorMayNotEdit(): iterable
    {
        yield 'an excluded field her group does not allow' => ['nav_title'];
        yield 'a column pages does not have' => ['header'];
        yield 'not a column name' => ['description; DROP'];
        yield 'empty' => [''];
        yield 'not text' => [12];
    }

    /** One answer for all, and nothing is written. */
    #[Test]
    #[DataProvider('pagePropertiesTheEditorMayNotEdit')]
    public function aPagePropertyTheEditorMayNotEditIsRefused(mixed $field): void
    {
        $result = $this->call(HighlightElementTool::class, ['pageField' => $field]);

        self::assertTrue($result->isError);
        self::assertSame('Error: no page property with this name that you may edit.', $result->content);
        self::assertSame(0, $this->rows('tx_nrmcpagent_run_state'));
    }

    /** An administrator may name an excluded field no group grants. */
    #[Test]
    public function anAdministratorMayNameAnyPageProperty(): void
    {
        $admin   = ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1));
        $context = new ToolExecutionContext($admin->actor, $admin->backendUser, new AgentRunReference(1, self::RUN));

        self::assertFalse($this->call(HighlightElementTool::class, ['pageField' => 'nav_title'], $context)->isError);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function notExactlyOneTarget(): iterable
    {
        yield 'both' => [['contentUid' => 100, 'pageField' => 'description']];
        yield 'neither' => [[]];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('notExactlyOneTarget')]
    public function exactlyOneOfElementAndPagePropertyIsNamed(array $arguments): void
    {
        $result = $this->call(HighlightElementTool::class, $arguments);

        self::assertTrue($result->isError);
        self::assertSame('Error: give exactly one of contentUid and pageField.', $result->content);
        self::assertSame(0, $this->rows('tx_nrmcpagent_run_state'));
    }

    /** The chat takes a page property over with the conversation's page. */
    #[Test]
    public function theChatTakesAPagePropertyOverForItsPage(): void
    {
        $this->call(HighlightElementTool::class, ['pageField' => 'description']);

        $conversation = $this->conversation(self::EDITOR, 20);
        $this->get(GuidedStateLinker::class)->absorb($conversation, self::RUN);

        self::assertSame(['table' => 'pages', 'uid' => 20, 'field' => 'description'], $conversation->getGuidedState()['highlight']);
    }

    private function conversation(int $beUser, int $pageId): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser($beUser);
        $conversation->setViewContext($pageId, 'web_layout');

        return $conversation;
    }

    /**
     * The chat takes the run's state over once, for the conversation's own
     * user and page, and the run's row is gone afterwards.
     */
    #[Test]
    public function theChatTakesTheRunStateOverForItsUserAndPage(): void
    {
        $this->call(SetProgressTool::class, ['label' => self::LABEL, 'current' => 2, 'total' => 5]);
        $this->call(HighlightElementTool::class, ['contentUid' => 100]);

        $conversation = $this->conversation(self::EDITOR, 20);
        $this->get(GuidedStateLinker::class)->absorb($conversation, self::RUN);

        self::assertSame(
            ['progress' => ['label' => self::LABEL, 'current' => 2, 'total' => 5, 'completed' => false], 'highlight' => ['table' => 'tt_content', 'uid' => 100]],
            $conversation->getGuidedState(),
        );
        self::assertSame(0, $this->rows('tx_nrmcpagent_run_state'));
    }

    /**
     * A completion report ends the tour: the header says so, and nothing is
     * highlighted any more — not even an element the same run named.
     */
    #[Test]
    public function aCompletionReportEndsTheTour(): void
    {
        $this->call(HighlightElementTool::class, ['contentUid' => 100]);
        self::assertFalse($this->call(SetProgressTool::class, ['label' => self::LABEL, 'current' => 5, 'total' => 5, 'completed' => true])->isError);

        $conversation = $this->conversation(self::EDITOR, 20);
        $conversation->setGuidedState(['label' => self::LABEL, 'current' => 4, 'total' => 5, 'completed' => false], ['table' => 'tt_content', 'uid' => 100]);
        $this->get(GuidedStateLinker::class)->absorb($conversation, self::RUN);

        self::assertSame(
            ['progress' => ['label' => self::LABEL, 'current' => 5, 'total' => 5, 'completed' => true], 'highlight' => null],
            $conversation->getGuidedState(),
        );
    }

    /**
     * The conversation is at point 2 of 5 about element 100.
     */
    private function atPointTwoAboutTheElement(): Conversation
    {
        $conversation = $this->conversation(self::EDITOR, 20);
        $conversation->setGuidedState(['label' => self::LABEL, 'current' => 2, 'total' => 5, 'completed' => false], ['table' => 'tt_content', 'uid' => 100]);

        return $conversation;
    }

    /**
     * A run that moves to the next point and names nothing ends the element
     * chip of the point before (NEXT-221): that point is about no element.
     */
    #[Test]
    public function aNewPointWithoutAHighlightDropsTheHighlightOfThePointBefore(): void
    {
        $this->call(SetProgressTool::class, ['label' => self::LABEL, 'current' => 3, 'total' => 5]);

        $conversation = $this->atPointTwoAboutTheElement();
        $this->get(GuidedStateLinker::class)->absorb($conversation, self::RUN);

        self::assertSame(
            ['progress' => ['label' => self::LABEL, 'current' => 3, 'total' => 5, 'completed' => false], 'highlight' => null],
            $conversation->getGuidedState(),
        );
    }

    /**
     * A run on the same point that names nothing — another variant of the
     * proposal, a question in between — keeps the point's element.
     *
     * @return iterable<string, array{array<string, mixed>|null}>
     */
    public static function runsThatStayOnThePoint(): iterable
    {
        yield 'the same point reported again' => [['label' => self::LABEL, 'current' => 2, 'total' => 5]];
        yield 'no progress reported' => [null];
    }

    /**
     * @param array<string, mixed>|null $progress
     */
    #[Test]
    #[DataProvider('runsThatStayOnThePoint')]
    public function aRunOnTheSamePointWithoutAHighlightKeepsIt(?array $progress): void
    {
        if ($progress !== null) {
            $this->call(SetProgressTool::class, $progress);
        } else {
            // Something must be in the run's state for the linker to read it.
            $this->get(RunStateRepository::class)->store(self::RUN, self::EDITOR, 'progress', ['label' => '']);
        }

        $conversation = $this->atPointTwoAboutTheElement();
        $this->get(GuidedStateLinker::class)->absorb($conversation, self::RUN);

        self::assertSame(['table' => 'tt_content', 'uid' => 100], $conversation->getGuidedState()['highlight']);
        self::assertSame(2, $conversation->getGuidedState()['progress']['current'] ?? null);
    }

    /**
     * A new point that names something gets it, in either order of the two
     * calls: an element or a page property.
     *
     * @return iterable<string, array{bool, array<string, int|string>, array<string, int|string>}>
     */
    public static function newPointsWithAHighlight(): iterable
    {
        yield 'progress first, then a page property' => [true, ['pageField' => 'description'], ['table' => 'pages', 'uid' => 20, 'field' => 'description']];
        yield 'the highlight first, then progress' => [false, ['pageField' => 'description'], ['table' => 'pages', 'uid' => 20, 'field' => 'description']];
        yield 'progress first, then the same element again' => [true, ['contentUid' => 100], ['table' => 'tt_content', 'uid' => 100]];
    }

    /**
     * @param array<string, int|string> $arguments
     * @param array<string, int|string> $expected
     */
    #[Test]
    #[DataProvider('newPointsWithAHighlight')]
    public function aNewPointWithAHighlightSetsIt(bool $progressFirst, array $arguments, array $expected): void
    {
        $progress = ['label' => self::LABEL, 'current' => 3, 'total' => 5];
        if ($progressFirst) {
            $this->call(SetProgressTool::class, $progress);
        }

        self::assertFalse($this->call(HighlightElementTool::class, $arguments)->isError);
        if (!$progressFirst) {
            $this->call(SetProgressTool::class, $progress);
        }

        $conversation = $this->atPointTwoAboutTheElement();
        $this->get(GuidedStateLinker::class)->absorb($conversation, self::RUN);

        self::assertSame($expected, $conversation->getGuidedState()['highlight']);
        self::assertSame(3, $conversation->getGuidedState()['progress']['current'] ?? null);
    }

    /** An element of another page is not highlighted beside this one. */
    #[Test]
    public function aHighlightOnAnotherPageIsNotTakenOver(): void
    {
        $this->call(HighlightElementTool::class, ['contentUid' => 100]);

        $conversation = $this->conversation(self::EDITOR, 30);
        $this->get(GuidedStateLinker::class)->absorb($conversation, self::RUN);

        self::assertNull($conversation->getGuidedState()['highlight']);
    }

    /** A run uuid is no capability: another user's row stays where it is. */
    #[Test]
    public function anotherUsersRunStateIsNotTakenOver(): void
    {
        $this->call(SetProgressTool::class, ['label' => self::LABEL, 'current' => 2, 'total' => 5]);

        $conversation = $this->conversation(1, 20);
        $this->get(GuidedStateLinker::class)->absorb($conversation, self::RUN);

        self::assertSame(['progress' => null, 'highlight' => null], $conversation->getGuidedState());
        self::assertSame(1, $this->rows('tx_nrmcpagent_run_state'));
    }

    /**
     * A pause on a proposal after a settled point (amendment of 2026-10-31):
     * the point moves on by one and the highlight is what the proposal
     * writes, with nothing reported by the model.
     *
     * @return iterable<string, array{OpenPointTarget, array<string, int|string>}>
     */
    public static function proposalsTheEditorMaySee(): iterable
    {
        yield 'a property of the page' => [new OpenPointTarget('pages', 20, ['description']), ['table' => 'pages', 'uid' => 20, 'field' => 'description']];
        yield 'a content element of the page' => [new OpenPointTarget('tt_content', 100, ['bodytext', 'header']), ['table' => 'tt_content', 'uid' => 100]];
    }

    /**
     * @param array<string, int|string> $expected
     */
    #[Test]
    #[DataProvider('proposalsTheEditorMaySee')]
    public function aProposalAfterASettledPointMovesOnAndNamesWhatItWrites(OpenPointTarget $proposal, array $expected): void
    {
        $this->setUpBackendUser(self::EDITOR);
        $conversation = $this->atPointTwoAboutTheElement();
        $conversation->markGuidedPointSettled();

        $this->get(GuidedStateLinker::class)->absorb($conversation, self::RUN, true, $proposal);

        self::assertSame(3, $conversation->getGuidedState()['progress']['current'] ?? null);
        self::assertSame($expected, $conversation->getGuidedState()['highlight']);
        self::assertFalse($conversation->isGuidedPointSettled());
    }

    /** The first proposal after the overview is point 1, without a decision before it. */
    #[Test]
    public function theFirstProposalAfterTheOverviewIsPointOne(): void
    {
        $this->setUpBackendUser(self::EDITOR);
        $conversation = $this->conversation(self::EDITOR, 20);
        $conversation->setGuidedState(['label' => self::LABEL, 'current' => 0, 'total' => 5, 'completed' => false], null);

        $this->get(GuidedStateLinker::class)->absorb($conversation, self::RUN, true, new OpenPointTarget('pages', 20, ['title']));

        self::assertSame(1, $conversation->getGuidedState()['progress']['current'] ?? null);
        self::assertSame(['table' => 'pages', 'uid' => 20, 'field' => 'title'], $conversation->getGuidedState()['highlight']);
    }

    /** Without a settled point — another variant, a question in between — the point stays. */
    #[Test]
    public function aProposalOnAnUnsettledPointStaysOnIt(): void
    {
        $this->setUpBackendUser(self::EDITOR);
        $conversation = $this->atPointTwoAboutTheElement();

        $this->get(GuidedStateLinker::class)->absorb($conversation, self::RUN, true, new OpenPointTarget('pages', 20, ['title']));

        self::assertSame(2, $conversation->getGuidedState()['progress']['current'] ?? null);
        self::assertSame(['table' => 'pages', 'uid' => 20, 'field' => 'title'], $conversation->getGuidedState()['highlight']);
    }

    /**
     * A settled point survives a run that pauses on a question and reports
     * nothing, and the proposal after it moves on.
     */
    #[Test]
    public function aSettledPointWaitsForTheNextProposal(): void
    {
        $this->setUpBackendUser(self::EDITOR);
        $conversation = $this->atPointTwoAboutTheElement();
        $conversation->markGuidedPointSettled();

        $this->get(GuidedStateLinker::class)->absorb($conversation, self::RUN);
        self::assertTrue($conversation->isGuidedPointSettled());
        self::assertSame(2, $conversation->getGuidedState()['progress']['current'] ?? null);

        $this->get(GuidedStateLinker::class)->absorb($conversation, self::RUN, true, new OpenPointTarget('pages', 20, ['title']));
        self::assertSame(3, $conversation->getGuidedState()['progress']['current'] ?? null);
    }

    /**
     * Progress the model reports beside a question names the point itself:
     * the settled point is used up, and the proposal after the answer stays
     * on the point the model named.
     */
    #[Test]
    public function progressReportedBesideAQuestionUsesTheSettledPointUp(): void
    {
        $this->setUpBackendUser(self::EDITOR);
        $this->call(SetProgressTool::class, ['label' => self::LABEL, 'current' => 3, 'total' => 5]);
        $conversation = $this->atPointTwoAboutTheElement();
        $conversation->markGuidedPointSettled();

        $this->get(GuidedStateLinker::class)->absorb($conversation, self::RUN);
        self::assertFalse($conversation->isGuidedPointSettled());

        $this->get(GuidedStateLinker::class)->absorb($conversation, self::RUN, true, new OpenPointTarget('pages', 20, ['title']));
        self::assertSame(3, $conversation->getGuidedState()['progress']['current'] ?? null);
    }

    /** What the model reported wins over what the proposal would give. */
    #[Test]
    public function whatTheModelReportedWins(): void
    {
        $this->call(SetProgressTool::class, ['label' => self::LABEL, 'current' => 4, 'total' => 5]);
        $this->call(HighlightElementTool::class, ['contentUid' => 100]);
        $conversation = $this->atPointTwoAboutTheElement();
        $conversation->markGuidedPointSettled();

        $this->get(GuidedStateLinker::class)->absorb($conversation, self::RUN, true, new OpenPointTarget('pages', 20, ['description']));

        self::assertSame(4, $conversation->getGuidedState()['progress']['current'] ?? null);
        self::assertSame(['table' => 'tt_content', 'uid' => 100], $conversation->getGuidedState()['highlight']);
        self::assertFalse($conversation->isGuidedPointSettled());
    }

    /**
     * A proposal names nothing the highlight tool would refuse, and nothing
     * outside the conversation's page; the point still moves on.
     *
     * @return iterable<string, array{OpenPointTarget}>
     */
    public static function proposalsThatNameNothing(): iterable
    {
        yield 'an element on a page she may not show' => [new OpenPointTarget('tt_content', 101, ['bodytext'])];
        yield 'an element in a language she may not edit' => [new OpenPointTarget('tt_content', 102, ['bodytext'])];
        yield 'an element that does not exist' => [new OpenPointTarget('tt_content', 999, ['bodytext'])];
        yield 'an excluded page property her group does not allow' => [new OpenPointTarget('pages', 20, ['nav_title'])];
        yield 'two page properties at once' => [new OpenPointTarget('pages', 20, ['description', 'title'])];
        yield 'a property of another page' => [new OpenPointTarget('pages', 30, ['title'])];
        yield 'a record of another table' => [new OpenPointTarget('sys_file_reference', 7, ['alternative'])];
    }

    #[Test]
    #[DataProvider('proposalsThatNameNothing')]
    public function aProposalNamesNothingTheToolWouldRefuse(OpenPointTarget $proposal): void
    {
        $this->setUpBackendUser(self::EDITOR);
        $conversation = $this->atPointTwoAboutTheElement();
        $conversation->markGuidedPointSettled();

        $this->get(GuidedStateLinker::class)->absorb($conversation, self::RUN, true, $proposal);

        self::assertNull($conversation->getGuidedState()['highlight']);
        self::assertSame(3, $conversation->getGuidedState()['progress']['current'] ?? null);
    }

    /** Checked for the conversation's owner only: under another live user, nothing is named. */
    #[Test]
    public function aProposalIsCheckedForTheConversationsOwnerOnly(): void
    {
        $this->setUpBackendUser(1);
        $conversation = $this->atPointTwoAboutTheElement();

        $this->get(GuidedStateLinker::class)->absorb($conversation, self::RUN, true, new OpenPointTarget('pages', 20, ['title']));

        self::assertSame(['table' => 'tt_content', 'uid' => 100], $conversation->getGuidedState()['highlight'], 'the point stays, and so does its element');
    }

    /**
     * Without a running tour there is nothing to move: before the overview,
     * after the completion report, and at the last point.
     */
    #[Test]
    public function aProposalMovesNoPointOutsideARunningTour(): void
    {
        $this->setUpBackendUser(self::EDITOR);
        $linker = $this->get(GuidedStateLinker::class);

        $none = $this->conversation(self::EDITOR, 20);
        $linker->absorb($none, self::RUN, true, new OpenPointTarget('pages', 20, ['title']));
        self::assertSame(['progress' => null, 'highlight' => null], $none->getGuidedState());

        $done = $this->conversation(self::EDITOR, 20);
        $done->setGuidedState(['label' => self::LABEL, 'current' => 5, 'total' => 5, 'completed' => true], null);

        $linker->absorb($done, self::RUN, true, new OpenPointTarget('pages', 20, ['title']));
        self::assertSame(['label' => self::LABEL, 'current' => 5, 'total' => 5, 'completed' => true], $done->getGuidedState()['progress']);
        self::assertNull($done->getGuidedState()['highlight']);

        $last = $this->conversation(self::EDITOR, 20);
        $last->setGuidedState(['label' => self::LABEL, 'current' => 5, 'total' => 5, 'completed' => false], null);
        $last->markGuidedPointSettled();

        $linker->absorb($last, self::RUN, true, new OpenPointTarget('pages', 20, ['title']));
        self::assertSame(5, $last->getGuidedState()['progress']['current'] ?? null, 'never past the last point');
    }

    /**
     * Only through autowiring does the chat get the linker; if that failed,
     * every unit test would stay green and the header would never change.
     */
    #[Test]
    public function theContainerHandsTheChatTheLinker(): void
    {
        $linker = (new ReflectionProperty(ChatService::class, 'guidedState'))->getValue($this->get(ChatService::class));

        self::assertInstanceOf(GuidedStateLinker::class, $linker);
    }

    /**
     * The page module loads the highlight receiver: without the listener the
     * chat posts into a frame nobody listens in, and nothing else would fail.
     */
    #[Test]
    public function thePageModuleLoadsTheHighlightReceiver(): void
    {
        $definitions = $this->get(ListenerProvider::class)->getAllListenerDefinitions();
        self::assertSame(
            PageModuleHighlight::class,
            $definitions[ModifyPageLayoutContentEvent::class]['nr-mcp-agent/page-module-highlight']['service'] ?? null,
        );

        $this->get(PageModuleHighlight::class)();

        $instructions = json_encode($this->get(PageRenderer::class)->getJavaScriptRenderer()->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertStringContainsString('@netresearch/nr-mcp-agent/page-highlight.js', $instructions);
        self::assertFileExists(__DIR__ . '/../../../Resources/Public/JavaScript/page-highlight.js', 'the module mapping points into Resources/Public/JavaScript/');
    }

    #[Test]
    public function theEditorIsNoAdministrator(): void
    {
        // Guards the fixture: every refusal above would pass for the wrong
        // reason if the editor were an administrator.
        $user = $this->setUpBackendUser(self::EDITOR);
        self::assertInstanceOf(BackendUserAuthentication::class, $user);
        self::assertFalse($user->isAdmin());
    }
}
