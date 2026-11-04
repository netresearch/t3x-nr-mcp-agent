<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Language;

use DOMDocument;
use DOMElement;
use Netresearch\NrMcpAgent\Service\ChatTask\ChatTaskProblem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every label the overview module asks for exists in locallang_overview.xlf:
 * the literal keys of the template, the readiness rows ChatOverview builds,
 * the settings of ext_conf_template.txt, the fix and link targets and the
 * states. A missing key renders as an empty cell.
 */
final class LocallangOverviewKeysTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../';

    #[Test]
    public function everyKeyTheOverviewAsksForExists(): void
    {
        $template = (string) file_get_contents(self::ROOT . 'Resources/Private/Templates/Overview/Index.html');
        preg_match_all('/\{ll\}([A-Za-z.]+)\'/', $template, $literal);
        $builder = (string) file_get_contents(self::ROOT . 'Classes/Service/Overview/ChatOverview.php');
        preg_match_all('/\$this->row\(\'([A-Za-z.]+)\'/', $builder, $rows);
        preg_match_all('/, \'(nrllm_[a-z]+|settings)\'\)/', $builder, $fixes);
        $controller = (string) file_get_contents(self::ROOT . 'Classes/Controller/OverviewController.php');
        preg_match('/LINKED_MODULES = \[(.*?)\];/s', $controller, $linked);
        preg_match_all("/'(nrllm_[a-z]+)'/", $linked[1] ?? '', $links);
        preg_match_all('/^([A-Za-z][A-Za-z0-9_.]*)\s*=/m', (string) file_get_contents(self::ROOT . 'ext_conf_template.txt'), $settings);

        $used = $literal[1];
        foreach ($rows[1] as $key) {
            $used[] = 'readiness.' . $key;
        }

        foreach (ChatTaskProblem::cases() as $problem) {
            $used[] = 'readiness.task.mapping.' . $problem->value;
        }

        $used[] = 'readiness.task.mapping.ok';
        foreach ($fixes[1] as $route) {
            $used[] = 'fix.' . $route;
        }

        foreach ($links[1] as $module) {
            $used[] = 'link.' . $module;
        }

        foreach (array_diff($settings[1], ['maxConversationsPerUser']) as $setting) {
            $used[] = 'setting.' . $setting;
        }

        foreach (['ok', 'problem', 'warning', 'info', 'unchecked'] as $state) {
            $used[] = 'state.' . $state;
        }

        self::assertGreaterThan(80, count(array_unique($used)), 'precondition: the scan must find the keys');
        self::assertSame([], array_values(array_diff(array_unique($used), $this->units())));
    }

    /**
     * @return list<string>
     */
    private function units(): array
    {
        $document = new DOMDocument();
        $document->load(self::ROOT . 'Resources/Private/Language/locallang_overview.xlf');

        $ids = [];
        foreach ($document->getElementsByTagName('trans-unit') as $unit) {
            if ($unit instanceof DOMElement) {
                $ids[] = $unit->getAttribute('id');
            }
        }

        return $ids;
    }
}
