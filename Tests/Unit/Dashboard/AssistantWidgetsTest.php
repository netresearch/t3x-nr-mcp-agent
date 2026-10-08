<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Dashboard;

use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Dashboard\ImprovePageWidget;
use Netresearch\NrMcpAgent\Dashboard\QuickTasksWidget;
use Netresearch\NrMcpAgent\Dashboard\RecommendationsWidget;
use Netresearch\NrMcpAgent\Domain\Repository\PageChoiceRepository;
use Netresearch\NrMcpAgent\Service\Assistant\ChatStartUriBuilder;
use Netresearch\NrMcpAgent\Service\Assistant\GuidedSkill;
use Netresearch\NrMcpAgent\Service\Assistant\GuidedSkillProviderInterface;
use Netresearch\NrMcpAgent\Service\Assistant\OpenPoint;
use Netresearch\NrMcpAgent\Service\Assistant\OpenPointReaderInterface;
use Netresearch\NrMcpAgent\Service\Assistant\PageSuggestion;
use Netresearch\NrMcpAgent\Service\Assistant\PageSuggestionProviderInterface;
use Netresearch\NrMcpAgent\Service\Assistant\QuickTask;
use Netresearch\NrMcpAgent\Service\Assistant\QuickTaskProviderInterface;
use Netresearch\NrMcpAgent\Service\Assistant\SkillResolverInterface;
use Netresearch\NrMcpAgent\Tests\Unit\Service\Assistant\SkillLabelsStub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;

/**
 * What the AI assistant widgets put in front of an editor. Rendering is in
 * the functional AssistantWidgetsRenderingTest.
 */
final class AssistantWidgetsTest extends TestCase
{
    use SkillLabelsStub;

    private function chatAccess(bool $available): ChatToolbarItem
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn($available ? 1 : 0);
        $config->method('getAllowedGroupIds')->willReturn([]);

        return new ChatToolbarItem($config, $this->createMock(PageRenderer::class));
    }

    private function chatStart(): ChatStartUriBuilder
    {
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturnCallback(
            static fn(string $route, array $parameters = []): Uri => new Uri('/chat?' . http_build_query(['token' => 't'] + $parameters)),
        );

        return new ChatStartUriBuilder($uriBuilder);
    }

    private function viewFactory(): BackendViewFactory
    {
        // Final, and only needed for rendering.
        return (new ReflectionClass(BackendViewFactory::class))->newInstanceWithoutConstructor();
    }

    private function improve(bool $available, GuidedSkillProviderInterface $skills, PageChoiceRepository $pages, ?PageSuggestionProviderInterface $suggestions = null): ImprovePageWidget
    {
        return new ImprovePageWidget($this->createMock(WidgetConfigurationInterface::class), $this->viewFactory(), $this->chatAccess($available), $skills, $pages, $suggestions ?? $this->createMock(PageSuggestionProviderInterface::class), $this->chatStart());
    }

    private function tasks(bool $available, QuickTaskProviderInterface $tasks, PageChoiceRepository $pages): QuickTasksWidget
    {
        return new QuickTasksWidget($this->createMock(WidgetConfigurationInterface::class), $this->viewFactory(), $this->chatAccess($available), $tasks, $pages, $this->chatStart());
    }

    /** Every skill resolves to `3:<skill>`, except those listed. */
    private function resolverWithout(string ...$missing): SkillResolverInterface
    {
        $resolver = $this->createMock(SkillResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static fn(string $skill): ?string => in_array($skill, $missing, true) ? null : '3:' . $skill);

        return $resolver;
    }

    private function recommendations(bool $available, OpenPointReaderInterface $reader, PageChoiceRepository $pages): RecommendationsWidget
    {
        return new RecommendationsWidget(
            $this->createMock(WidgetConfigurationInterface::class),
            $this->viewFactory(),
            $this->chatAccess($available),
            $reader,
            $pages,
            $this->skillLabels(['skill.seo-optimieren.title' => 'SEO optimieren', 'skill.inhalt-verbessern.title' => 'Inhalt verbessern']),
            $this->resolverWithout('inhalt-verbessern'),
            $this->chatStart(),
        );
    }

    #[Test]
    public function aUserTheChatIsNotAvailableForIsOfferedNothing(): void
    {
        $skills = $this->createMock(GuidedSkillProviderInterface::class);
        $skills->expects(self::never())->method('getGuidedSkills');
        $tasks = $this->createMock(QuickTaskProviderInterface::class);
        $tasks->expects(self::never())->method('getQuickTasks');
        $reader = $this->createMock(OpenPointReaderInterface::class);
        $reader->expects(self::never())->method('findOpen');
        $pages = $this->createMock(PageChoiceRepository::class);
        $pages->expects(self::never())->method(self::anything());

        self::assertSame(
            ['available' => false, 'skills' => [], 'suggestions' => [], 'pages' => [], 'form' => null, 'idPrefix' => ''],
            $this->improve(false, $skills, $pages)->templateVariables(),
        );
        self::assertSame(
            ['available' => false, 'tasks' => [], 'pages' => [], 'form' => null, 'idPrefix' => ''],
            $this->tasks(false, $tasks, $pages)->templateVariables(),
        );
        self::assertSame(['available' => false, 'points' => []], $this->recommendations(false, $reader, $pages)->templateVariables());
    }

    #[Test]
    public function improveAPageOffersTheToursAndTheUsersPages(): void
    {
        $skills = $this->createMock(GuidedSkillProviderInterface::class);
        $skills->method('getGuidedSkills')->willReturn([new GuidedSkill('seo-optimieren', 'SEO optimieren', 'Prüfen')]);
        $pages = $this->createMock(PageChoiceRepository::class);
        $pages->expects(self::once())->method('findRecentlyChanged')->with(ImprovePageWidget::PAGE_LIMIT)->willReturn([['uid' => 21, 'title' => 'Inhalt A']]);

        $variables = $this->improve(true, $skills, $pages)->templateVariables();

        self::assertTrue($variables['available']);
        self::assertSame([['identifier' => 'seo-optimieren', 'title' => 'SEO optimieren', 'description' => 'Prüfen']], $variables['skills']);
        self::assertSame([['uid' => 21, 'title' => 'Inhalt A']], $variables['pages']);
        self::assertSame(['action' => '/chat', 'hidden' => ['token' => 't'], 'skillField' => 'skill', 'pageField' => 'pageUid', 'languageField' => 'languageUid'], $variables['form']);
        self::assertMatchesRegularExpression('/^nr-mcp-agent-improve-[0-9a-f]{8}$/', $variables['idPrefix']);
    }

    #[Test]
    public function withoutToursNoPagesAreLookedUp(): void
    {
        $skills = $this->createMock(GuidedSkillProviderInterface::class);
        $skills->method('getGuidedSkills')->willReturn([]);
        $pages = $this->createMock(PageChoiceRepository::class);
        $pages->expects(self::never())->method('findRecentlyChanged');

        self::assertSame([], $this->improve(true, $skills, $pages)->templateVariables()['pages']);
    }

    #[Test]
    public function aTaskWithoutAPageIsALinkAndATaskOnAPageAsksForOne(): void
    {
        $tasks = $this->createMock(QuickTaskProviderInterface::class);
        $tasks->method('getQuickTasks')->willReturn([new QuickTask('Neue Seite anlegen', 'neue-seite', false), new QuickTask('SEO optimieren', 'seo-optimieren', true)]);
        $pages = $this->createMock(PageChoiceRepository::class);
        $pages->expects(self::once())->method('findRecentlyChanged')->willReturn([['uid' => 21, 'title' => 'Inhalt A']]);

        $variables = $this->tasks(true, $tasks, $pages)->templateVariables();

        self::assertTrue($variables['available']);
        self::assertSame(['action' => '/chat', 'hidden' => ['token' => 't'], 'skillField' => 'skill', 'pageField' => 'pageUid', 'languageField' => 'languageUid'], $variables['form']);
        self::assertMatchesRegularExpression('/^nr-mcp-agent-tasks-[0-9a-f]{8}$/', $variables['idPrefix']);
        self::assertSame(
            [
                ['label' => 'Neue Seite anlegen', 'skill' => 'neue-seite', 'needsPage' => false, 'uri' => '/chat?token=t&skill=neue-seite'],
                ['label' => 'SEO optimieren', 'skill' => 'seo-optimieren', 'needsPage' => true, 'uri' => ''],
            ],
            $variables['tasks'],
        );
        self::assertSame([['uid' => 21, 'title' => 'Inhalt A']], $variables['pages']);
    }

    #[Test]
    public function whenNoTaskNeedsAPageNoPagesAreLookedUp(): void
    {
        $tasks = $this->createMock(QuickTaskProviderInterface::class);
        $tasks->method('getQuickTasks')->willReturn([new QuickTask('Neue Seite anlegen', 'neue-seite', false)]);
        $pages = $this->createMock(PageChoiceRepository::class);
        $pages->expects(self::never())->method('findRecentlyChanged');

        self::assertSame([], $this->tasks(true, $tasks, $pages)->templateVariables()['pages']);
    }

    /**
     * The store is not trusted to filter: a point on a page the user may not
     * access, for a skill without a name or that does not resolve, or in a
     * language the chat would not start in, is not shown. The link carries
     * the resolved skill and the point's language (decision 4).
     */
    #[Test]
    public function recommendationsShowOnlyPointsOnAccessiblePagesWithANamedSkill(): void
    {
        $reader = $this->createMock(OpenPointReaderInterface::class);
        $reader->expects(self::once())->method('findOpen')->with(RecommendationsWidget::LIMIT)->willReturn([
            new OpenPoint(30, 0, 'seo-optimieren', 'Fremde Seite.'),
            new OpenPoint(21, 0, 'unlabelled', 'Ohne Namen.'),
            new OpenPoint(21, 0, 'inhalt-verbessern', 'Skill nicht startbar.'),
            new OpenPoint(21, 2, 'seo-optimieren', 'Sprache nicht startbar.'),
            new OpenPoint(21, 1, 'seo-optimieren', 'Die Meta Description fehlt.'),
        ]);
        $pages = $this->createMock(PageChoiceRepository::class);
        $pages->method('findAccessible')->willReturnCallback(
            static fn(int $uid): ?array => $uid === 21 ? ['uid' => 21, 'title' => 'Inhalt A'] : null,
        );
        $pages->method('mayStartIn')->willReturnCallback(static fn(int $uid, int $language): bool => $language !== 2);

        $variables = $this->recommendations(true, $reader, $pages)->templateVariables();

        self::assertTrue($variables['available']);
        self::assertSame(
            [['summary' => 'Die Meta Description fehlt.', 'pageTitle' => 'Inhalt A', 'skillTitle' => 'SEO optimieren', 'uri' => '/chat?token=t&skill=3%3Aseo-optimieren&pageUid=21&languageUid=1']],
            $variables['points'],
        );
    }

    /**
     * The suggestions of all tours, in tour order: one entry per page with
     * every reason, default-language versions only (the form starts in the
     * default language), five at most; the rest of the list leaves the
     * suggested pages out.
     */
    #[Test]
    public function improveAPageUnitesTheToursSuggestions(): void
    {
        $skills = $this->createMock(GuidedSkillProviderInterface::class);
        $skills->method('getGuidedSkills')->willReturn([new GuidedSkill('3:seo-optimieren', 'SEO optimieren', ''), new GuidedSkill('3:inhalt-verbessern', 'Inhalt verbessern', '')]);
        $provider = $this->createMock(PageSuggestionProviderInterface::class);
        $provider->method('suggest')->willReturnMap([
            ['3:seo-optimieren', PageSuggestionProviderInterface::MAX_SUGGESTIONS, [
                new PageSuggestion(1, 0, 'Eins', 'Meta Description fehlt'),
                new PageSuggestion(2, 0, 'Zwei', 'Seitentitel zu kurz'),
                new PageSuggestion(3, 1, 'Drei (EN)', 'Offener Punkt'),
                new PageSuggestion(4, 0, 'Vier', 'Meta Description fehlt'),
            ]],
            ['3:inhalt-verbessern', PageSuggestionProviderInterface::MAX_SUGGESTIONS, [
                new PageSuggestion(2, 0, 'Zwei', 'Offener Punkt'),
                new PageSuggestion(5, 0, 'Fünf', 'Offener Punkt'),
                new PageSuggestion(6, 0, 'Sechs', 'Offener Punkt'),
                new PageSuggestion(7, 0, 'Sieben', 'Offener Punkt'),
            ]],
        ]);
        $pages = $this->createMock(PageChoiceRepository::class);
        $pages->method('findRecentlyChanged')->willReturn([['uid' => 2, 'title' => 'Zwei'], ['uid' => 8, 'title' => 'Acht'], ['uid' => 1, 'title' => 'Eins']]);

        $variables = $this->improve(true, $skills, $pages, $provider)->templateVariables();

        self::assertSame(
            [
                ['uid' => 1, 'title' => 'Eins', 'reason' => 'Meta Description fehlt'],
                ['uid' => 2, 'title' => 'Zwei', 'reason' => 'Seitentitel zu kurz, Offener Punkt'],
                ['uid' => 4, 'title' => 'Vier', 'reason' => 'Meta Description fehlt'],
                ['uid' => 5, 'title' => 'Fünf', 'reason' => 'Offener Punkt'],
                ['uid' => 6, 'title' => 'Sechs', 'reason' => 'Offener Punkt'],
            ],
            $variables['suggestions'],
        );
        self::assertSame([['uid' => 8, 'title' => 'Acht']], $variables['pages']);
    }
}
