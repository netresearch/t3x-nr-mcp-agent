<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Configuration;

use JsonException;

/**
 * The privacy setting "Bildanalyse" (ADR-026, slice 3) as a turn was
 * dispatched with it.
 *
 * TYPO3 loads its configuration into $GLOBALS['TYPO3_CONF_VARS'] once per
 * process (Bootstrap::populateLocalConfiguration()), and the core's
 * ExtensionConfiguration::get() reads it from there. A worker
 * (`processingStrategy = worker`) is one long process, so it would go on
 * with the setting it started with. Every turn, however, starts in a backend
 * request that reads the configuration anew: the request stamps the two
 * values on the conversation in the same write that hands it to the worker
 * (ConversationRepository), and the turn runs with them. Values, not a
 * decision, are stamped: the folders are resolved where the image is.
 */
final readonly class ImageAnalysisTurnSettings
{
    private const KEYS = ['imageAnalysis', 'imageAnalysisExcludedFolders'];

    public function __construct(
        private ExtensionConfiguration $configuration,
    ) {}

    /** The two values as configured now, as the conversation keeps them. */
    public function snapshot(): string
    {
        return json_encode($this->configuration->getImageAnalysisValues(), JSON_THROW_ON_ERROR);
    }

    /**
     * Runs a turn with the values it was dispatched with, in place of the
     * ones this process loaded, and puts the process's own back afterwards,
     * so the next turn starts from them again. An empty or unreadable
     * snapshot (a turn queued before the stamp existed) leaves the process's
     * values as they are.
     *
     * @template T
     *
     * @param callable(): T $turn
     *
     * @return T
     */
    public function during(string $snapshot, callable $turn): mixed
    {
        $values = $this->decode($snapshot);
        if ($values === null) {
            return $turn();
        }

        $process = $this->extension();
        $this->setExtension([...$process, ...$values]);

        try {
            return $turn();
        } finally {
            // The process's own values back, and only those: whatever else the
            // turn changed in the extension's configuration stays.
            $extension = $this->extension();
            foreach (self::KEYS as $key) {
                if (array_key_exists($key, $process)) {
                    $extension[$key] = $process[$key];
                } else {
                    unset($extension[$key]);
                }
            }

            $this->setExtension($extension);
        }
    }

    /**
     * The extension's configuration as this process holds it, where the
     * core's ExtensionConfiguration::get() reads it.
     *
     * @return array<array-key, mixed>
     */
    private function extension(): array
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $extensions    = is_array($configuration) ? ($configuration['EXTENSIONS'] ?? null) : null;
        $extension     = is_array($extensions) ? ($extensions['nr_mcp_agent'] ?? null) : null;

        return is_array($extension) ? $extension : [];
    }

    /**
     * @param array<array-key, mixed> $extension
     */
    private function setExtension(array $extension): void
    {
        $configuration               = is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
        $extensions                  = is_array($configuration['EXTENSIONS'] ?? null) ? $configuration['EXTENSIONS'] : [];
        $extensions['nr_mcp_agent']  = $extension;
        $configuration['EXTENSIONS'] = $extensions;
        $GLOBALS['TYPO3_CONF_VARS']  = $configuration;
    }

    /**
     * @return array<string, string>|null
     */
    private function decode(string $snapshot): ?array
    {
        if ($snapshot === '') {
            return null;
        }

        try {
            $decoded = json_decode($snapshot, true, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $values = [];
        foreach (self::KEYS as $key) {
            if (!is_string($decoded[$key] ?? null)) {
                return null;
            }

            $values[$key] = $decoded[$key];
        }

        return $values;
    }
}
