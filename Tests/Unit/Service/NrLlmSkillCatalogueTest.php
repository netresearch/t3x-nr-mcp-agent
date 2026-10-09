<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Service\NrLlmSkillCatalogue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Which skills a chat user can invoke, and how an invoked one reaches the run
 * (ADR-019).
 */
#[CoversClass(NrLlmSkillCatalogue::class)]
final class NrLlmSkillCatalogueTest extends TestCase
{
    private static function skill(string $identifier, string $name = '', bool $enabled = true, int $uid = 0): Skill
    {
        $skill = new Skill();
        if ($uid > 0) {
            $skill->_setProperty('uid', $uid);
        }
        $skill->setIdentifier($identifier);
        $skill->setName($name);
        $skill->setDescription('about ' . $identifier);
        $skill->setEnabled($enabled);

        return $skill;
    }

    private function catalogue(?Task $task): NrLlmSkillCatalogue
    {
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(3);
        $tasks = $this->createMock(TaskRepository::class);
        $tasks->method('findByUid')->with(3)->willReturn($task);

        return new NrLlmSkillCatalogue($config, $tasks);
    }

    private static function task(): Task
    {
        $configuration = new LlmConfiguration();
        $configuration->addSkill(self::skill('seo-page-tour', 'SEO einer Seite', uid: 5));
        $configuration->addSkill(self::skill('switched-off', 'Aus', false));
        $task = new Task();
        $task->setConfiguration($configuration);
        $task->addSkill(self::skill('content-tour'));
        // Attached to both: listed once.
        $task->addSkill(self::skill('seo-page-tour', 'SEO einer Seite'));

        return $task;
    }

    #[Test]
    public function theCatalogueListsTheEnabledSkillsOfConfigurationAndTask(): void
    {
        // nr-llm 0.41 marks process skills (nr-llm PR 1025) and these are
        // none; an earlier nr-llm has no marker.
        $process = method_exists(Skill::class, 'isProcess') ? false : null;

        self::assertSame(
            [
                ['identifier' => 'seo-page-tour', 'name' => 'SEO einer Seite', 'description' => 'about seo-page-tour', 'uid' => 5, 'process' => $process],
                ['identifier' => 'content-tour', 'name' => 'content-tour', 'description' => 'about content-tour', 'uid' => 0, 'process' => $process],
            ],
            $this->catalogue(self::task())->catalogue(),
        );
    }

    #[Test]
    public function aSkillNrLlmMarksAsAProcessIsListedAsOne(): void
    {
        if (!method_exists(Skill::class, 'setProcess')) {
            self::markTestSkipped('Needs nr-llm 0.41 (Skill::isProcess(), nr-llm PR 1025).');
        }

        $task = self::task();
        $tour = self::skill('guided-tour', 'Rundgang', uid: 9);
        $tour->setProcess(true);
        $task->addSkill($tour);

        self::assertTrue($this->catalogue($task)->find('guided-tour')['process'] ?? null);
        self::assertFalse($this->catalogue($task)->find('content-tour')['process'] ?? null);
    }

    #[Test]
    public function anInvokedSkillReachesTheRunAsAForcedSkill(): void
    {
        $augmentation = $this->catalogue(self::task())->augmentationFor('content-tour');

        self::assertNotNull($augmentation);
        self::assertCount(1, $augmentation->forcedSkills);
        self::assertSame('content-tour', $augmentation->forcedSkills[0]->getIdentifier());
    }

    #[Test]
    public function aSkillThatIsDisabledOrUnknownDegradesToNone(): void
    {
        $catalogue = $this->catalogue(self::task());

        self::assertNull($catalogue->augmentationFor('switched-off'));
        self::assertNull($catalogue->augmentationFor('gone'));
        self::assertNull($catalogue->augmentationFor(''));
        self::assertNull($catalogue->find('switched-off'));
        self::assertSame('SEO einer Seite', $catalogue->find('seo-page-tour')['name'] ?? null);
    }

    /** Four-eyes is read from the chat Task's own configuration. */
    #[Test]
    public function aConfigurationWithASecondApproverIsReported(): void
    {
        $task = self::task();
        self::assertFalse($this->catalogue($task)->requiresSecondApprover());

        $task->getConfiguration()?->setRequireSecondApprover(true);
        self::assertTrue($this->catalogue($task)->requiresSecondApprover());
        self::assertFalse($this->catalogue(null)->requiresSecondApprover());
    }

    #[Test]
    public function withoutATaskThereIsNoCatalogue(): void
    {
        self::assertSame([], $this->catalogue(null)->catalogue());
        self::assertNull($this->catalogue(null)->augmentationFor('seo-page-tour'));
    }

    #[Test]
    public function aFailingLookupIsNoCatalogueRatherThanAFailedTurn(): void
    {
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willThrowException(new RuntimeException('broken'));

        self::assertSame([], (new NrLlmSkillCatalogue($config, $this->createMock(TaskRepository::class)))->catalogue());
    }

    #[Test]
    public function theInstalledNrLlmTakesASkillPerRun(): void
    {
        self::assertTrue($this->catalogue(null)->isAvailable());
    }
}
