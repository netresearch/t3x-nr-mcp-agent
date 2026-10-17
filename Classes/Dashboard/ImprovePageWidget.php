<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Dashboard;

use Netresearch\NrMcpAgent\Backend\ToolbarItems\ChatToolbarItem;
use Netresearch\NrMcpAgent\Domain\Repository\PageChoiceRepository;
use Netresearch\NrMcpAgent\Service\Assistant\ChatStartUriBuilder;
use Netresearch\NrMcpAgent\Service\Assistant\GuidedSkill;
use Netresearch\NrMcpAgent\Service\Assistant\GuidedSkillProviderInterface;
use Netresearch\NrMcpAgent\Service\Assistant\PageSuggestionProviderInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;

/**
 * "Improve a page": the editor picks a page and presses the button of a
 * guided tour, and the chat starts that tour on that page.
 *
 * The tours come from GuidedSkillProviderInterface, one submit button each
 * (`name="skill"`, the tour as value). The shared page choice lists first the
 * suggested pages ("Empfohlen", PageSuggestionProviderInterface: the union
 * over the tours offered, one entry per page, at most MAX_SUGGESTIONS), then
 * the most recently changed pages the editor may improve
 * (PageChoiceRepository) that are not suggested already. The form submits to
 * the URL ChatStartUriBuilder describes, in the default language: it is a
 * plain GET form and works by keyboard and without JavaScript.
 *
 * Who may use the chat is decided as for the toolbar button and the "AI Chat"
 * widget; for anyone else the widget says the chat is not available.
 */
final class ImprovePageWidget extends AbstractAssistantWidget
{
    /** How many pages the page choice offers. */
    public const PAGE_LIMIT = 15;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        WidgetConfigurationInterface $configuration,
        BackendViewFactory $backendViewFactory,
        private readonly ChatToolbarItem $chatAccess,
        private readonly GuidedSkillProviderInterface $skills,
        private readonly PageChoiceRepository $pages,
        private readonly PageSuggestionProviderInterface $suggestions,
        private readonly ChatStartUriBuilder $chatStart,
        array $options = [],
    ) {
        parent::__construct($configuration, $backendViewFactory, $options);
    }

    protected function templateName(): string
    {
        return 'Widget/ImprovePageWidget';
    }

    /**
     * @return array{available: bool, skills: list<array{identifier: string, title: string, description: string}>, suggestions: list<array{uid: int, title: string, reason: string}>, pages: list<array{uid: int, title: string}>, form: array{action: string, hidden: array<string, string>, skillField: string, pageField: string, languageField: string}|null, idPrefix: string}
     */
    public function templateVariables(): array
    {
        if (!$this->chatAccess->checkAccess()) {
            return ['available' => false, 'skills' => [], 'suggestions' => [], 'pages' => [], 'form' => null, 'idPrefix' => ''];
        }

        $skills = array_map(
            static fn(GuidedSkill $skill): array => [
                'identifier' => $skill->identifier,
                'title' => $skill->title,
                'description' => $skill->description,
            ],
            $this->skills->getGuidedSkills(),
        );

        $suggestions = $skills === [] ? [] : $this->suggestions(array_column($skills, 'identifier'));
        $suggested = array_column($suggestions, 'uid');
        $pages = $skills === [] ? [] : array_values(array_filter(
            $this->pages->findRecentlyChanged(self::PAGE_LIMIT),
            static fn(array $page): bool => !in_array($page['uid'], $suggested, true),
        ));

        return [
            'available' => true,
            'skills' => $skills,
            'suggestions' => $suggestions,
            'pages' => $pages,
            'form' => $this->chatStart->formTarget(),
            // The same widget may sit on a dashboard twice; its labels must
            // still point at its own fields.
            'idPrefix' => 'nr-mcp-agent-improve-' . bin2hex(random_bytes(4)),
        ];
    }

    /**
     * The suggestions of every tour, in tour order, one entry per page with
     * the reasons of all tours that suggest it, at most MAX_SUGGESTIONS. The
     * form starts the chat in the default language, so only default-language
     * suggestions qualify.
     *
     * @param list<string> $skills
     * @return list<array{uid: int, title: string, reason: string}>
     */
    private function suggestions(array $skills): array
    {
        $byPage = [];
        foreach ($skills as $skill) {
            foreach ($this->suggestions->suggest($skill) as $suggestion) {
                if ($suggestion->languageUid !== 0) {
                    continue;
                }

                if (isset($byPage[$suggestion->pageUid])) {
                    $byPage[$suggestion->pageUid]['reasons'][$suggestion->reason] = true;
                    continue;
                }

                if (count($byPage) < PageSuggestionProviderInterface::MAX_SUGGESTIONS) {
                    $byPage[$suggestion->pageUid] = ['title' => $suggestion->title, 'reasons' => [$suggestion->reason => true]];
                }
            }
        }

        $suggestions = [];
        foreach ($byPage as $uid => $entry) {
            $suggestions[] = ['uid' => $uid, 'title' => $entry['title'], 'reason' => implode(', ', array_keys($entry['reasons']))];
        }

        return $suggestions;
    }
}
