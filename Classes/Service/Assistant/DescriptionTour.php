<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Assistant;

use Netresearch\NrMcpAgent\Domain\Repository\MissingDescriptionRepository;

/**
 * The page versions without a meta description, and where the SEO tour
 * starts on them: what "Recommended for your website" and "Check website"
 * show. Null where the user may not write a meta description
 * (MissingDescriptionRepository::summary()) or the SEO tour is not available
 * to them (it has no title, or the chat's catalogue does not resolve it for
 * them): the dashboard then has nothing to offer for them.
 *
 * Both widgets sit on one dashboard; the count is read once per request.
 */
final class DescriptionTour
{
    /** The tour that writes meta descriptions. */
    public const SKILL = 'seo-optimieren';

    /** @var array{count: int, start: array{skill: string, pageUid: int, languageUid: int, title: string}|null}|false|null false: not read yet */
    private array|false|null $summary = false;

    public function __construct(
        private readonly MissingDescriptionRepository $descriptions,
        private readonly SkillLabels $labels,
        private readonly SkillResolverInterface $resolver,
    ) {}

    /**
     * @return array{count: int, start: array{skill: string, pageUid: int, languageUid: int, title: string}|null}|null
     */
    public function summary(): ?array
    {
        if ($this->summary === false) {
            $this->summary = $this->read();
        }

        return $this->summary;
    }

    /**
     * @return array{count: int, start: array{skill: string, pageUid: int, languageUid: int, title: string}|null}|null
     */
    private function read(): ?array
    {
        $skill = $this->labels->title(self::SKILL) === null ? null : $this->resolver->resolve(self::SKILL);
        if ($skill === null) {
            return null;
        }

        $summary = $this->descriptions->summary();
        if ($summary === null) {
            return null;
        }

        $first = $summary['first'];

        return [
            'count' => $summary['count'],
            'start' => $first === null ? null : ['skill' => $skill, 'pageUid' => $first['pageUid'], 'languageUid' => $first['languageUid'], 'title' => $first['title']],
        ];
    }
}
