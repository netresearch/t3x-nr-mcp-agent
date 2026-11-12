<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service;

use Netresearch\NrLlm\Service\Guardrail\GuardrailHintCollector;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function class_exists;
use function get_object_vars;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;

/**
 * The hints nr-llm's guardrails report while a run call runs (nr-llm ADR-288).
 *
 * A guardrail in report mode lets an output through and reports a hint: the
 * rule-set judge names a rule the text of a proposed write may break, or says
 * the text was not checked. The runtime's result carries no hints, so the call
 * runs inside nr-llm's GuardrailHintCollector, the shared service the
 * guardrail middleware records into.
 *
 * nr-llm releases before ADR-288 have no collector: the call then runs as it
 * is and reports no hints. The collector is therefore named as a string and
 * used by its shape, so this class needs nothing that release lacks.
 */
final class GuardrailHintCapture
{
    public const COLLECTOR_CLASS = GuardrailHintCollector::class;

    private ?object $collector = null;

    private bool $resolved = false;

    /**
     * @param string $collectorClass the collector's class, for a test that stands in for it
     */
    public function __construct(
        private readonly string $collectorClass = self::COLLECTOR_CLASS,
    ) {}

    /**
     * Run the call and return its result with the hints reported while it ran.
     * Only kind and message of a hint are kept: the message is the sentence
     * the editor reads.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return array{result: T, hints: list<array{kind: string, message: string}>}
     */
    public function capture(callable $call): array
    {
        $capture = [$this->collector(), 'capture'];
        if (!is_callable($capture)) {
            return ['result' => $call(), 'hints' => []];
        }

        $result   = null;
        $captured = $capture(static function () use ($call, &$result): void {
            $result = $call();
        });

        /** @var T $result */
        return [
            'result' => $result,
            'hints'  => $this->hintsOf(is_array($captured) ? ($captured['hints'] ?? null) : null),
        ];
    }

    /**
     * The shared collector, or null when the installed nr-llm has none.
     */
    private function collector(): ?object
    {
        if (!$this->resolved) {
            $this->resolved  = true;
            $this->collector = class_exists($this->collectorClass) ? GeneralUtility::makeInstance($this->collectorClass) : null;
        }

        return $this->collector;
    }

    /**
     * @return list<array{kind: string, message: string}>
     */
    private function hintsOf(mixed $hints): array
    {
        if (!is_array($hints)) {
            return [];
        }

        $read = [];
        foreach ($hints as $hint) {
            $fields = is_object($hint) ? get_object_vars($hint) : [];
            $kind   = $fields['kind'] ?? null;
            $text   = $fields['message'] ?? null;
            if (is_string($kind) && is_string($text) && $text !== '') {
                $read[] = ['kind' => $kind, 'message' => $text];
            }
        }

        return $read;
    }
}
