<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\OpenPoint;

use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrMcpAgent\Domain\Repository\OpenPointRepository;
use Throwable;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The open points one backend user may see (ADR-022): the one place that
 * decides, for the list tool, the system prompt and the dashboard alike.
 *
 * - The subject page must pass `readPageAccess()` with PAGE_SHOW, the page
 *   module's own condition.
 * - A point is left out when the user may not read its table or, for an
 *   excluded field, the field; when its record is gone (deleted, or never
 *   there); or when the record is in a language the user may not access.
 */
readonly class OpenPointVisibility
{
    /** How many stored points are read per round while filling a site-wide list. */
    private const BATCH = 100;

    public function __construct(
        private OpenPointRepository $repository,
        private ?SkillRepository $skills = null,
        private ?LanguageServiceFactory $languageServiceFactory = null,
    ) {}

    /**
     * Whether the user may show the page.
     */
    public function mayShowPage(BackendUserAuthentication $user, int $pageUid): bool
    {
        return $this->readablePage($user, $pageUid) !== null;
    }

    /**
     * The page's open points the user may see, or null when the user may not
     * show the page.
     *
     * @return list<array{skillUid: int, targetTable: string, targetUid: int, field: string, crdate: int}>|null
     */
    public function forPage(BackendUserAuthentication $user, int $pageUid, int $skillUid = 0, int $limit = 50): ?array
    {
        if (!$this->mayShowPage($user, $pageUid)) {
            return null;
        }

        $visible = [];
        foreach ($this->repository->findBySubject(OpenPointTracker::SUBJECT_TABLE, $pageUid, $skillUid, $limit) as $point) {
            if ($this->readableTarget($user, $point['targetTable'], $point['targetUid'], $point['field']) !== null) {
                $visible[] = $point;
            }
        }

        return $visible;
    }

    /**
     * Every open point the current backend user may see, across the site,
     * newest first; at most `$limit`, counted after leaving out what the user
     * may not see. Empty without a backend user.
     *
     * @return list<VisibleOpenPoint>
     */
    public function visibleForCurrentUser(int $limit = 20): array
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? $this->visibleFor($user, $limit) : [];
    }

    /**
     * @return list<VisibleOpenPoint>
     */
    public function visibleFor(BackendUserAuthentication $user, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $pages = [];
        $visible = [];
        $offset = 0;
        do {
            $batch = $this->repository->findRecent($offset, self::BATCH);
            $offset += self::BATCH;
            foreach ($batch as $point) {
                if ($point['subjectTable'] !== OpenPointTracker::SUBJECT_TABLE) {
                    continue;
                }

                $pageUid = $point['subjectUid'];
                $page = array_key_exists($pageUid, $pages) ? $pages[$pageUid] : ($pages[$pageUid] = $this->readablePage($user, $pageUid));
                $target = $page !== null ? $this->readableTarget($user, $point['targetTable'], $point['targetUid'], $point['field']) : null;
                if ($page === null || $target === null) {
                    continue;
                }

                $visible[] = [$point, $page, $target];
                if (count($visible) >= $limit) {
                    break 2;
                }
            }
        } while (count($batch) === self::BATCH);

        $identifiers = $this->skillIdentifiers(array_values(array_unique(array_map(
            static fn(array $entry): int => $entry[0]['skillUid'],
            $visible,
        ))));
        $german = $this->germanLabels();

        return array_map(fn(array $entry): VisibleOpenPoint => new VisibleOpenPoint(
            $entry[0]['subjectUid'],
            $entry[2]['language'],
            $entry[0]['skillUid'],
            $identifiers[$entry[0]['skillUid']] ?? '',
            $entry[0]['targetTable'],
            $entry[0]['targetUid'],
            $entry[0]['field'],
            $entry[0]['crdate'],
            $this->summary($entry[0]['targetTable'], $entry[0]['field'], $entry[1], $entry[2]['empty'], $german),
        ), $visible);
    }

    /**
     * The page row when the user may show the page, else null.
     *
     * @return array<mixed>|null
     */
    private function readablePage(BackendUserAuthentication $user, int $pageUid): ?array
    {
        if ($pageUid <= 0) {
            return null;
        }

        try {
            $row = BackendUtility::readPageAccess($pageUid, $user->getPagePermsClause(Permission::PAGE_SHOW));
        } catch (Throwable) {
            return null;
        }

        $uid = is_array($row) ? ($row['uid'] ?? null) : null;

        return is_array($row) && is_numeric($uid) && (int) $uid === $pageUid ? $row : null;
    }

    /**
     * The target's language and whether the field is empty, when the user may
     * read the table, the field and the record in its language; else null.
     *
     * @return array{language: int, empty: bool}|null
     */
    private function readableTarget(BackendUserAuthentication $user, string $table, int $uid, string $field): ?array
    {
        if (!$user->check('tables_select', $table)) {
            return null;
        }

        $config = $this->tca($table);
        $ctrl = is_array($config['ctrl'] ?? null) ? $config['ctrl'] : [];
        $columns = is_array($config['columns'] ?? null) ? $config['columns'] : [];
        $column = $field !== '' && is_array($columns[$field] ?? null) ? $columns[$field] : null;
        if ($column !== null && ($column['exclude'] ?? false) && !$user->check('non_exclude_fields', $table . ':' . $field)) {
            return null;
        }

        $languageField = is_string($ctrl['languageField'] ?? null) ? $ctrl['languageField'] : '';
        $select = array_filter(['uid', $languageField, $column !== null ? $field : '']);

        try {
            $record = BackendUtility::getRecord($table, $uid, implode(',', array_unique($select)));
        } catch (Throwable) {
            return null;
        }

        if (!is_array($record)) {
            return null;
        }

        $language = $languageField !== '' ? ($record[$languageField] ?? 0) : 0;
        $language = is_numeric($language) ? (int) $language : 0;
        if ($languageField !== '' && !$user->checkLanguageAccess($language)) {
            return null;
        }

        $value = $column !== null ? ($record[$field] ?? null) : null;

        return ['language' => $language, 'empty' => $column !== null && ($value === null || $value === '')];
    }

    /**
     * One German sentence from the schema's labels and the page title:
     * "<Feld> fehlt auf „<Seite>“" for an empty field, else
     * "<Feld> auf „<Seite>“ offen"; for a whole record the table's label.
     *
     * @param array<mixed> $page
     */
    private function summary(string $table, string $field, array $page, bool $empty, ?LanguageService $german): string
    {
        $config = $this->tca($table);
        $columns = is_array($config['columns'] ?? null) ? $config['columns'] : [];
        $column = $field !== '' && is_array($columns[$field] ?? null) ? $columns[$field] : [];
        $ctrl = is_array($config['ctrl'] ?? null) ? $config['ctrl'] : [];
        $reference = $field !== ''
            ? (is_string($column['label'] ?? null) ? $column['label'] : '')
            : (is_string($ctrl['title'] ?? null) ? $ctrl['title'] : '');
        $label = $reference !== '' && $german instanceof LanguageService ? trim($german->sL($reference)) : '';
        if ($label === '') {
            $label = $field !== '' ? $field : $table;
        }

        $title = is_string($page['title'] ?? null) ? $page['title'] : '';

        return $empty
            ? sprintf('%s fehlt auf „%s“', $label, $title)
            : sprintf('%s auf „%s“ offen', $label, $title);
    }

    private function germanLabels(): ?LanguageService
    {
        try {
            return $this->languageServiceFactory?->create('de');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * nr-llm's stored identifiers, a disabled skill included.
     *
     * @param list<int> $uids
     *
     * @return array<int, string>
     */
    private function skillIdentifiers(array $uids): array
    {
        $uids = array_values(array_filter($uids, static fn(int $uid): bool => $uid > 0));
        if ($uids === [] || !$this->skills instanceof SkillRepository) {
            return [];
        }

        $identifiers = [];
        try {
            foreach ($this->skills->findExistingByUids($uids) as $skill) {
                if ($skill instanceof Skill && is_int($skill->getUid())) {
                    $identifiers[$skill->getUid()] = $skill->getIdentifier();
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $identifiers;
    }

    /**
     * @return array<mixed>
     */
    private function tca(string $table): array
    {
        $tca = $GLOBALS['TCA'] ?? null;
        $config = is_array($tca) ? ($tca[$table] ?? null) : null;

        return is_array($config) ? $config : [];
    }
}
