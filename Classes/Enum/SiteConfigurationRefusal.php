<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Enum;

/**
 * Why the chat refused the AI configuration a site names for itself
 * (ADR-033).
 *
 * The value is the code a refused turn stores; the stored message holds the
 * names the label quotes ({@see self::encode()}). Every reader gets the label
 * in their language, an administrator also a link to the configurations.
 */
enum SiteConfigurationRefusal: string
{
    /** The configuration does not exist, is switched off, or the user's groups may not use it. */
    case Unusable = 'siteConfig.unusable';

    /** The configuration picks its model by criteria; the chat needs a fixed one to check. */
    case NoFixedModel = 'siteConfig.noFixedModel';

    /** The configuration's model, or its provider, cannot call tools. */
    case NoToolCalling = 'siteConfig.noTools';

    public function labelKey(): string
    {
        return 'error.' . $this->value;
    }

    /**
     * The names the label quotes, as the stored message carries them.
     */
    public static function encode(string $site, string $configuration, string $model): string
    {
        return (string) json_encode(['site' => $site, 'configuration' => $configuration, 'model' => $model], JSON_UNESCAPED_UNICODE);
    }

    /**
     * The names back from a stored message, '' for each one it does not carry.
     *
     * @return array{site: string, configuration: string, model: string}
     */
    public static function decode(string $stored): array
    {
        $data  = json_decode($stored, true);
        $names = ['site' => '', 'configuration' => '', 'model' => ''];
        if (!is_array($data)) {
            return $names;
        }

        foreach (array_keys($names) as $key) {
            $names[$key] = is_string($data[$key] ?? null) ? $data[$key] : '';
        }

        return $names;
    }
}
