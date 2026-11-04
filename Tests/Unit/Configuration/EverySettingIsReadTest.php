<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Configuration;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;

/**
 * A setting the extension configuration offers is read, and its getter is
 * called.
 *
 * `maxConversationsPerUser` was offered for months with a getter nothing
 * called, and its documentation described an archival nobody had built. An
 * operator who set it changed nothing. Each getter must have a caller in
 * `Classes/` outside the configuration class itself.
 */
#[CoversNothing]
final class EverySettingIsReadTest extends TestCase
{
    #[Test]
    public function everyGetterHasACaller(): void
    {
        $code = $this->classesExceptTheConfiguration();

        $unread = [];
        foreach ((new ReflectionClass(ExtensionConfiguration::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->getDeclaringClass()->getName() !== ExtensionConfiguration::class) {
                continue;
            }

            if (!str_contains($code, '->' . $method->getName() . '(')) {
                $unread[] = $method->getName();
            }
        }

        self::assertSame([], $unread, 'a setting nothing reads');
    }

    #[Test]
    public function everyOfferedSettingIsReadByTheConfiguration(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 3) . '/ext_conf_template.txt');
        preg_match_all('/^([A-Za-z][\w.]*)\s*=/m', $template, $keys);
        $configuration = (string) file_get_contents(dirname(__DIR__, 3) . '/Classes/Configuration/ExtensionConfiguration.php');

        self::assertNotSame([], $keys[1], 'the template was read');
        self::assertSame(
            [],
            array_values(array_filter($keys[1], static fn (string $key): bool => !str_contains($configuration, "'" . $key . "'"))),
            'a setting the template offers and the configuration never reads',
        );
    }

    private function classesExceptTheConfiguration(): string
    {
        $root   = dirname(__DIR__, 3) . '/Classes';
        $self   = realpath($root . '/Configuration/ExtensionConfiguration.php');
        $code   = '';
        $finder = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($finder as $file) {
            if ($file->getExtension() !== 'php' || $file->getRealPath() === $self) {
                continue;
            }

            $code .= (string) file_get_contents($file->getPathname());
        }

        return $code;
    }
}
