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
use Netresearch\NrMcpAgent\Domain\Repository\OpenPointRepository;
use Netresearch\NrMcpAgent\Domain\Repository\RunStateRepository;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\GuidedStateLinker;
use Netresearch\NrMcpAgent\Tool\HighlightElementTool;
use Netresearch\NrMcpAgent\Tool\ListOpenPointsTool;
use Netresearch\NrMcpAgent\Tool\RecordOpenPointTool;
use Netresearch\NrMcpAgent\Tool\ResolveOpenPointTool;
use Netresearch\NrMcpAgent\Tool\SetProgressTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
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
 * her group does not allow.
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/guided_tools.csv');
    }

    private function editorContext(?AgentRunReference $run = new AgentRunReference(1, self::RUN)): ToolExecutionContext
    {
        $user = $this->setUpBackendUser(self::EDITOR);
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
        yield 'record open point' => [RecordOpenPointTool::class];
        yield 'list open points' => [ListOpenPointsTool::class];
        yield 'resolve open point' => [ResolveOpenPointTool::class];
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

        $user = $this->setUpBackendUser(self::EDITOR);
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
        $result = $this->call($class, ['label' => 'x', 'current' => 1, 'total' => 2, 'contentUid' => 100, 'pageUid' => 20, 'languageUid' => 0, 'skill' => 's', 'key' => 'k', 'title' => 't'], ToolExecutionContext::none());

        self::assertTrue($result->isError);
        self::assertSame(0, $this->rows('tx_nrmcpagent_run_state'));
        self::assertSame(0, $this->rows('tx_nrmcpagent_open_point'));
    }

    #[Test]
    public function progressOutsideARunIsRefused(): void
    {
        $result = $this->call(SetProgressTool::class, ['label' => 'Über uns · Deutsch', 'current' => 1, 'total' => 5], $this->editorContext(null));

        self::assertTrue($result->isError);
        self::assertSame(0, $this->rows('tx_nrmcpagent_run_state'));
    }

    #[Test]
    public function theSameProgressTwiceIsOneRow(): void
    {
        $arguments = ['label' => 'Über uns · Deutsch', 'current' => 2, 'total' => 5];
        self::assertFalse($this->call(SetProgressTool::class, $arguments)->isError);
        self::assertFalse($this->call(SetProgressTool::class, $arguments)->isError);

        self::assertSame(1, $this->rows('tx_nrmcpagent_run_state'));
        self::assertSame(
            ['label' => 'Über uns · Deutsch', 'current' => 2, 'total' => 5],
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
     * The open point survives the conversation: recorded twice it is one row
     * with the later wording, listed in its scope only, gone from the list
     * once resolved.
     */
    #[Test]
    public function anOpenPointIsKeptOncePerScopeAndKey(): void
    {
        $scope = ['pageUid' => 20, 'languageUid' => 0, 'skill' => 'seo-check'];
        self::assertFalse($this->call(RecordOpenPointTool::class, [...$scope, 'key' => 'meta-description', 'title' => 'Meta Description fehlt'])->isError);
        self::assertFalse($this->call(RecordOpenPointTool::class, [...$scope, 'key' => 'meta-description', 'title' => 'Meta Description zu kurz', 'details' => 'Mindestens 120 Zeichen'])->isError);

        self::assertSame(1, $this->rows('tx_nrmcpagent_open_point'));
        $listed = json_decode($this->call(ListOpenPointsTool::class, $scope)->content, true);
        self::assertSame(
            ['points' => [['key' => 'meta-description', 'title' => 'Meta Description zu kurz', 'details' => 'Mindestens 120 Zeichen', 'status' => 'open']]],
            $listed,
        );

        $otherSkill = json_decode($this->call(ListOpenPointsTool::class, [...$scope, 'skill' => 'accessibility'])->content, true);
        self::assertSame(['points' => []], $otherSkill);

        self::assertFalse($this->call(ResolveOpenPointTool::class, [...$scope, 'key' => 'meta-description'])->isError);
        self::assertFalse($this->call(ResolveOpenPointTool::class, [...$scope, 'key' => 'meta-description'])->isError, 'resolving twice changes nothing');
        self::assertSame(['points' => []], json_decode($this->call(ListOpenPointsTool::class, $scope)->content, true));
        $all = json_decode($this->call(ListOpenPointsTool::class, [...$scope, 'includeResolved' => true])->content, true);
        self::assertSame('resolved', $all['points'][0]['status'] ?? null);
    }

    #[Test]
    public function resolvingAPointThatWasNeverRecordedIsAnError(): void
    {
        self::assertTrue($this->call(ResolveOpenPointTool::class, ['pageUid' => 20, 'languageUid' => 0, 'skill' => 'seo-check', 'key' => 'unknown'])->isError);
    }

    /**
     * @return iterable<string, array{class-string<ToolInterface>, array<string, mixed>}>
     */
    public static function scopesTheEditorMayNotUse(): iterable
    {
        $point = ['key' => 'k', 'title' => 't'];
        yield 'record on a page she may not show' => [RecordOpenPointTool::class, ['pageUid' => 30, 'languageUid' => 0, 'skill' => 's', ...$point]];
        yield 'record in a language she may not edit' => [RecordOpenPointTool::class, ['pageUid' => 20, 'languageUid' => 1, 'skill' => 's', ...$point]];
        yield 'record without a skill' => [RecordOpenPointTool::class, ['pageUid' => 20, 'languageUid' => 0, 'skill' => '', ...$point]];
        yield 'list on a page she may not show' => [ListOpenPointsTool::class, ['pageUid' => 30, 'languageUid' => 0, 'skill' => 's']];
        yield 'resolve on a page she may not show' => [ResolveOpenPointTool::class, ['pageUid' => 30, 'languageUid' => 0, 'skill' => 's', 'key' => 'k']];
    }

    /**
     * Reading is checked as well as writing: a point names a page, and the
     * list must not tell an editor about pages she may not show.
     *
     * @param class-string<ToolInterface> $class
     * @param array<string, mixed>        $arguments
     */
    #[Test]
    #[DataProvider('scopesTheEditorMayNotUse')]
    public function aScopeTheEditorMayNotUseIsRefused(string $class, array $arguments): void
    {
        $this->get(OpenPointRepository::class)->record(['pageUid' => 30, 'languageUid' => 0, 'skill' => 's'], 'k', 'Interner Punkt', '', 'run', 1);

        $result = $this->call($class, $arguments);

        self::assertTrue($result->isError);
        self::assertStringNotContainsString('Interner Punkt', $result->content);
        self::assertSame(1, $this->rows('tx_nrmcpagent_open_point'));
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
        $this->call(SetProgressTool::class, ['label' => 'Über uns · Deutsch', 'current' => 2, 'total' => 5]);
        $this->call(HighlightElementTool::class, ['contentUid' => 100]);

        $conversation = $this->conversation(self::EDITOR, 20);
        $this->get(GuidedStateLinker::class)->absorb($conversation, self::RUN);

        self::assertSame(
            ['progress' => ['label' => 'Über uns · Deutsch', 'current' => 2, 'total' => 5], 'highlight' => ['table' => 'tt_content', 'uid' => 100]],
            $conversation->getGuidedState(),
        );
        self::assertSame(0, $this->rows('tx_nrmcpagent_run_state'));
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
        $this->call(SetProgressTool::class, ['label' => 'Über uns · Deutsch', 'current' => 2, 'total' => 5]);

        $conversation = $this->conversation(1, 20);
        $this->get(GuidedStateLinker::class)->absorb($conversation, self::RUN);

        self::assertSame(['progress' => null, 'highlight' => null], $conversation->getGuidedState());
        self::assertSame(1, $this->rows('tx_nrmcpagent_run_state'));
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
