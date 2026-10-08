<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Psr\Container\ContainerInterface;
use ReflectionMethod;
use Throwable;

/**
 * The nr-llm side of {@see PreviewHeadingLabelsInterface}.
 *
 * nr-llm is adding a public list of its preview heading labels with the
 * change that makes every preview open with one (netresearch/t3x-nr-llm#1016).
 * At the time of writing that API is not released, so the provider is
 * looked up by name and guarded: an nr-llm without it yields no labels, and
 * the card then names the change by the editor action label or its generic
 * wording. The expected shape is {@see self::PROVIDER}::{@see self::METHOD}()
 * returning a list of `LLL:` references, static or on a container service.
 *
 * Any failure yields no labels as well: recognising a heading is
 * presentation, and the decision the card carries does not depend on it.
 */
final readonly class NrLlmPreviewHeadingLabels implements PreviewHeadingLabelsInterface
{
    /** The expected nr-llm class; to be confirmed against the release. */
    public const PROVIDER = 'Netresearch\\NrLlm\\Service\\Tool\\ApprovalPreviewHeadings';

    public const METHOD = 'labelReferences';

    public function __construct(
        private ContainerInterface $container,
        private string $provider = self::PROVIDER,
    ) {}

    public function labelReferences(): array
    {
        try {
            if (!class_exists($this->provider) || !method_exists($this->provider, self::METHOD)) {
                return [];
            }

            $method = new ReflectionMethod($this->provider, self::METHOD);
            // A missing service or a non-static method without an instance
            // throws, and lands in the catch below like any other failure.
            $instance   = $method->isStatic() ? null : $this->container->get($this->provider);
            $references = $method->invoke(is_object($instance) ? $instance : null);
        } catch (Throwable) {
            return [];
        }

        if (!is_iterable($references)) {
            return [];
        }

        $labels = [];
        foreach ($references as $reference) {
            if (is_string($reference) && str_starts_with($reference, 'LLL:')) {
                $labels[] = $reference;
            }
        }

        return $labels;
    }
}
