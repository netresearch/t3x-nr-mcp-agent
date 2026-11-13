<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\ChangeList;

use Netresearch\NrLlm\Domain\Enum\ChangeAction;
use Throwable;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * The change list as one viewer may see it (ADR-034).
 *
 * nr-llm's list names table and uid of records the viewer may not be allowed
 * to read (nr-llm ADR-292), and the run's events are readable by whoever may
 * read the run. So the decision is made here, per record: the viewer sees a
 * record when they may select its table, the record's page (or the page
 * itself) is shown to them, the record's language and workspace are open to
 * them, and the row still exists. Anything else is withheld whole - no table,
 * no uid, no title - and only counted, so the list does not turn into an
 * oracle for records the viewer has no access to.
 *
 * @phpstan-type Item array{
 *     table: string, tableLabel: string, uid: int, title: string,
 *     action: string, writeTarget: bool, failedAfterWrite: bool,
 *     draft: bool, workspace: array{id: int, title: string},
 *     language: array{id: int, label: string}|null,
 *     hidden: bool|null, restrictions: list<string>,
 *     legacy: bool, complete: bool|null,
 *     editUrl: string, historyUrl: string, workspaceUrl: string
 * }
 */
final readonly class ChangePresenter
{
    /** How many records one answer lists; the rest is counted in `omitted`. */
    public const LIMIT = 100;

    private const WORKSPACE_ROUTES = ['workspaces_publish', 'workspaces_admin', 'web_WorkspacesWorkspaces'];

    public function __construct(
        private UriBuilder $uriBuilder,
        private LanguageLabel $languageLabel,
    ) {}

    /**
     * @return array{
     *     runs: int, changes: list<Item>, withheld: int, omitted: int,
     *     notCaptured: bool, legacy: bool, unreadableRuns: int
     * }
     */
    public function present(ChangeCollection $collection, BackendUserAuthentication $viewer, ?LanguageService $language): array
    {
        $items    = [];
        $withheld = 0;
        $omitted  = $collection->omitted;
        foreach ($collection->changes as $change) {
            if (count($items) >= self::LIMIT) {
                ++$omitted;

                continue;
            }

            $item = $this->item($change, $viewer, $language);
            if ($item === null) {
                ++$withheld;

                continue;
            }

            $items[] = $item;
        }

        return [
            'runs'           => $collection->runs,
            'changes'        => $items,
            'withheld'       => $withheld,
            'omitted'        => $omitted,
            'notCaptured'    => $collection->notCaptured,
            'legacy'         => $collection->legacy,
            'unreadableRuns' => $collection->unreadable,
        ];
    }

    /**
     * @return Item|null null when the viewer may not see the record
     */
    private function item(RecordChange $change, BackendUserAuthentication $viewer, ?LanguageService $labels): ?array
    {
        $row = $this->row($change->table, $change->uid);
        if ($row === null || !$this->mayRead($viewer, $change->table, $row)) {
            return null;
        }

        $ctrl    = $this->ctrl($change->table);
        $deleted = is_string($ctrl['delete'] ?? null) && $this->intOf($row[$ctrl['delete']] ?? 0) === 1;
        // The workspace the stored row is in, not the one the writer worked in.
        $workspace = $change->workspace;
        $wsRecord  = $workspace > 0 ? $viewer->checkWorkspace($workspace) : false;

        $mayModify = !$deleted && $viewer->check('tables_modify', $change->table);

        return [
            'table'            => $change->table,
            'tableLabel'       => $this->tableLabel($change->table, $labels),
            'uid'              => $change->uid,
            'title'            => $this->title($change->table, $row),
            'action'           => $change->action instanceof ChangeAction ? $change->action->value : '',
            'writeTarget'      => $change->isWriteTarget,
            'failedAfterWrite' => $change->failedAfterWrite,
            'draft'            => $workspace > 0,
            // The title of a workspace the viewer may not use is withheld; its id is not.
            'workspace' => [
                'id'    => $workspace,
                'title' => is_array($wsRecord) && is_string($wsRecord['title'] ?? null) ? $wsRecord['title'] : '',
            ],
            'language' => $change->language !== null
                ? ['id' => $change->language, 'label' => $this->languageLabel->of($change->language, $this->sitePageOf($change->table, $row), $labels)]
                : null,
            'hidden'       => $change->hidden,
            'restrictions' => $change->restrictions,
            'legacy'       => $change->legacy,
            'complete'     => $change->complete,
            'editUrl'      => $mayModify ? $this->editUrl($change->table, $change->uid) : '',
            'historyUrl'   => $deleted ? '' : $this->historyUrl($change->table, $change->uid),
            // The workspace module shows the workspace the viewer works in now.
            'workspaceUrl' => $workspace > 0 && $viewer->workspace === $workspace ? $this->workspaceUrl() : '',
        ];
    }

    /**
     * @param array<mixed> $row
     */
    private function mayRead(BackendUserAuthentication $viewer, string $table, array $row): bool
    {
        if ($this->ctrl($table) === [] || !$viewer->check('tables_select', $table)) {
            return false;
        }

        $workspaceOfRow = $this->intOf($row['t3ver_wsid'] ?? 0);
        if ($workspaceOfRow > 0 && $viewer->checkWorkspace($workspaceOfRow) === false) {
            return false;
        }

        $ctrl          = $this->ctrl($table);
        $languageField = is_string($ctrl['languageField'] ?? null) ? $ctrl['languageField'] : '';
        $language      = $languageField !== '' ? $this->intOf($row[$languageField] ?? 0) : 0;
        if ($languageField !== '' && !$viewer->checkLanguageAccess($language)) {
            return false;
        }

        $pageId = $this->pageOf($table, $row);
        if ($pageId === 0) {
            // A record on the root level, or the root itself.
            return $viewer->isAdmin() || ($table !== 'pages' && $this->intOf($ctrl['rootLevel'] ?? 0) !== 0);
        }

        try {
            return BackendUtility::readPageAccess($pageId, $viewer->getPagePermsClause(Permission::PAGE_SHOW)) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<mixed>|null
     */
    private function row(string $table, int $uid): ?array
    {
        if ($this->ctrl($table) === []) {
            return null;
        }

        try {
            // Deleted rows included: a delete is something the run did.
            $row = BackendUtility::getRecord($table, $uid, '*', '', false);
        } catch (Throwable) {
            return null;
        }

        return is_array($row) ? $row : null;
    }

    /**
     * The page whose permissions govern the record: the page itself, else the
     * record's page. A translation of a page lies beside its original.
     *
     * @param array<mixed> $row
     */
    private function pageOf(string $table, array $row): int
    {
        if ($table === 'pages') {
            $parent = $this->intOf($row['l10n_parent'] ?? 0);
            $uid    = $this->intOf($row['uid'] ?? 0);

            return $parent > 0 ? $parent : $uid;
        }

        return $this->intOf($row['pid'] ?? 0);
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * A page that finds the record's site. A page new in a workspace is not in
     * the live rootline yet, so a page is looked up by its parent.
     *
     * @param array<mixed> $row
     */
    private function sitePageOf(string $table, array $row): int
    {
        $pid = $this->intOf($row['pid'] ?? 0);

        return $table === 'pages' && $pid === 0 ? $this->intOf($row['uid'] ?? 0) : $pid;
    }

    /**
     * @param array<mixed> $row
     */
    private function title(string $table, array $row): string
    {
        try {
            return trim(strip_tags(BackendUtility::getRecordTitle($table, $row)));
        } catch (Throwable) {
            return '';
        }
    }

    private function tableLabel(string $table, ?LanguageService $labels): string
    {
        $title = $this->ctrl($table)['title'] ?? null;
        $label = is_string($title) && $labels instanceof LanguageService ? trim($labels->sL($title)) : '';

        return $label !== '' ? $label : $table;
    }

    private function editUrl(string $table, int $uid): string
    {
        try {
            return (string) $this->uriBuilder->buildUriFromRoute('record_edit', ['edit' => [$table => [$uid => 'edit']]]);
        } catch (Throwable) {
            return '';
        }
    }

    private function historyUrl(string $table, int $uid): string
    {
        try {
            return (string) $this->uriBuilder->buildUriFromRoute('record_history', ['element' => $table . ':' . $uid]);
        } catch (Throwable) {
            return '';
        }
    }

    private function workspaceUrl(): string
    {
        if (!ExtensionManagementUtility::isLoaded('workspaces')) {
            return '';
        }

        foreach (self::WORKSPACE_ROUTES as $route) {
            try {
                return (string) $this->uriBuilder->buildUriFromRoute($route);
            } catch (Throwable) {
                continue;
            }
        }

        return '';
    }

    /**
     * @return array<mixed>
     */
    private function ctrl(string $table): array
    {
        $tca    = $GLOBALS['TCA'] ?? null;
        $config = is_array($tca) ? ($tca[$table] ?? null) : null;
        $ctrl   = is_array($config) ? ($config['ctrl'] ?? null) : null;

        return is_array($ctrl) ? $ctrl : [];
    }
}
