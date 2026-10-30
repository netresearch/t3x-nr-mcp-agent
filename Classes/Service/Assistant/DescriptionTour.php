<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

use Doctrine\DBAL\Exception as DatabaseException;
use Netresearch\NrMcpAgent\Domain\Repository\MissingDescriptionRepository;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * The page versions without a meta description, and where the SEO tour
 * starts on them: what "Recommended for your website" and "Check website"
 * show. Null where the user may not write a meta description
 * (MissingDescriptionRepository::summary()) or the SEO tour is not available
 * to them (it has no title, or the chat's catalogue does not resolve it for
 * them): the dashboard then has nothing to offer for them.
 *
 * The dashboard renders each widget in a request of its own, so the count is
 * kept in the cache CACHE, per user, workspace, groups and languages, for at
 * most five minutes (ext_localconf.php). DashboardCountInvalidation drops it
 * whenever DataHandler writes a page, so an editor's own change shows on the
 * next dashboard; a change of permissions shows within five minutes.
 *
 * Where the cache cannot be read or written (its tables are missing when a
 * deploy skipped `extension:setup`), the count is read directly and the
 * failure logged, once per widget request.
 */
final readonly class DescriptionTour
{
    /** The tour that writes meta descriptions. */
    public const SKILL = 'seo-optimieren';

    /** The cache the counts are kept in. */
    public const CACHE = 'nrmcpagent_dashboard';

    /** The tag every count carries: DataHandler's page cache clearing flushes it for live pages. */
    public const CACHE_TAG = 'pages';

    public function __construct(
        private MissingDescriptionRepository $descriptions,
        private SkillLabels $labels,
        private SkillResolverInterface $resolver,
        private FrontendInterface $cache,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * @return array{count: int, translations: int, start: array{skill: string, pageUid: int, languageUid: int, title: string}|null}|null
     */
    public function summary(): ?array
    {
        $skill = $this->labels->title(self::SKILL) === null ? null : $this->resolver->resolve(self::SKILL);
        if ($skill === null) {
            return null;
        }

        $summary = $this->counted();
        if ($summary === null) {
            return null;
        }

        $first = $summary['first'];

        return [
            'count'        => $summary['count'],
            'translations' => $summary['translations'],
            'start'        => $first === null ? null : ['skill' => $skill, 'pageUid' => $first['pageUid'], 'languageUid' => $first['languageUid'], 'title' => $first['title']],
        ];
    }

    /**
     * The repository's answer, from the cache where it holds one for this
     * user as they are now.
     *
     * @return array{count: int, translations: int, first: array{pageUid: int, languageUid: int, title: string}|null}|null
     */
    private function counted(): ?array
    {
        $key = $this->cacheKey();
        if ($key === null) {
            return $this->descriptions->summary();
        }

        try {
            $cached = $this->cache->get($key);
        } catch (DatabaseException $exception) {
            $this->cacheFailed($exception);

            return $this->descriptions->summary();
        }

        if (is_array($cached) && array_key_exists('summary', $cached)) {
            /** @var array{count: int, translations: int, first: array{pageUid: int, languageUid: int, title: string}|null}|null $summary */
            $summary = $cached['summary'];

            return $summary;
        }

        $summary = $this->descriptions->summary();
        try {
            $this->cache->set($key, ['summary' => $summary], [self::CACHE_TAG]);
        } catch (DatabaseException $exception) {
            $this->cacheFailed($exception);
        }

        return $summary;
    }

    private function cacheFailed(DatabaseException $exception): void
    {
        $this->logger?->warning('The dashboard cache "{cache}" is not usable, the page count is read directly. Run "typo3 extension:setup" to create its tables.', [
            'cache'     => self::CACHE,
            'exception' => $exception,
        ]);
    }

    /** What the count depends on besides the pages: who, where, and what they may do. Null without a user. */
    private function cacheKey(): ?string
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication || !is_array($user->user)) {
            return null;
        }

        return 'descriptions_' . sha1((string) json_encode([
            $user->user['uid'] ?? 0,
            $user->workspace,
            $user->isAdmin(),
            $user->userGroupsUID,
            $user->groupData['allowed_languages'] ?? '',
            $user->groupData['webmounts'] ?? '',
            $user->groupData['tables_modify'] ?? '',
            $user->groupData['non_exclude_fields'] ?? '',
        ]));
    }
}
