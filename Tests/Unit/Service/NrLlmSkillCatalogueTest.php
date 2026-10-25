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
use Netresearch\NrMcpAgent\Service\SkillPageNeed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
    private function skill(string $identifier, string $name = '', bool $enabled = true, int $uid = 0): Skill
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

    private function task(): Task
    {
        $configuration = new LlmConfiguration();
        $configuration->addSkill($this->skill('seo-page-tour', 'SEO einer Seite', uid: 5));
        $configuration->addSkill($this->skill('switched-off', 'Aus', false));

        $task = new Task();
        $task->setConfiguration($configuration);
        $task->addSkill($this->skill('content-tour'));
        // Attached to both: listed once.
        $task->addSkill($this->skill('seo-page-tour', 'SEO einer Seite'));

        return $task;
    }

    #[Test]
    public function theCatalogueListsTheEnabledSkillsOfConfigurationAndTask(): void
    {
        // nr-llm marks process skills (nr-llm PR 1025); these are none.
        $process = false;

        self::assertSame(
            [
                ['identifier' => 'seo-page-tour', 'name' => 'SEO einer Seite', 'description' => 'about seo-page-tour', 'uid' => 5, 'process' => $process, 'page' => null],
                ['identifier' => 'content-tour', 'name' => 'content-tour', 'description' => 'about content-tour', 'uid' => 0, 'process' => $process, 'page' => null],
            ],
            $this->catalogue($this->task())->catalogue(),
        );
    }

    #[Test]
    public function aSkillNrLlmMarksAsAProcessIsListedAsOne(): void
    {
        $task = $this->task();
        $tour = $this->skill('guided-tour', 'Rundgang', uid: 9);
        $tour->setProcess(true);

        $task->addSkill($tour);

        self::assertTrue($this->catalogue($task)->find('guided-tour')['process'] ?? null);
        self::assertFalse($this->catalogue($task)->find('content-tour')['process'] ?? null);
    }

    /**
     * A skill that declares nothing about a page has no `page`. That is also
     * what an nr-llm without the field yields (nr-llm ADR-229, ADR-025): the
     * chat then keeps the behaviour from before.
     */
    #[Test]
    public function aSkillThatDeclaresNoPageNeedHasNone(): void
    {
        $entry = $this->catalogue($this->task())->find('content-tour');

        self::assertIsArray($entry);
        self::assertArrayHasKey('page', $entry);
        self::assertNull($entry['page']);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function declaredPageNeeds(): iterable
    {
        yield 'required' => ['required', 'required'];
        yield 'optional' => ['optional', 'optional'];
        yield 'none' => ['none', 'none'];
        yield 'nothing declared' => ['', null];
    }

    /**
     * What the skill declares about a page reaches the entry as the value of
     * nr-llm's enum (nr-llm ADR-229).
     */
    #[Test]
    #[DataProvider('declaredPageNeeds')]
    public function theDeclaredPageNeedIsListed(string $declared, ?string $expected): void
    {
        $task = $this->task();
        $task->addSkill($this->skillDeclaring('page-skill', $declared));

        $entry = $this->catalogue($task)->find('page-skill');

        self::assertIsArray($entry);
        self::assertArrayHasKey('page', $entry);
        self::assertSame($expected, $entry['page']);
    }

    /**
     * A skill declaring a page need. nr-llm before ADR-229 has no
     * getPageNeed(); the double adds it there, returning a backed enum as
     * nr-llm does. Where nr-llm has it, the real setter is used.
     */
    private function skillDeclaring(string $identifier, string $page): Skill
    {
        if (method_exists(Skill::class, 'setPage')) {
            $skill = $this->skill($identifier);
            $skill->setPage($page);

            return $skill;
        }

        $skill = new class extends Skill {
            public string $declared = '';

            public function getPageNeed(): ?SkillPageNeed
            {
                return SkillPageNeed::tryFrom($this->declared);
            }
        };
        $skill->declared = $page;
        $skill->setIdentifier($identifier);
        $skill->setEnabled(true);

        return $skill;
    }

    /**
     * nr-llm's form attaches a process skill on demand only (nr-llm ADR-214
     * item 6), so such a process is listed; a skill the model loads on demand
     * is not the chat's to invoke.
     */
    #[Test]
    public function aProcessAttachedOnDemandIsListed(): void
    {
        $task = $this->task();
        $tour = $this->skill('guided-tour', 'Rundgang', uid: 9);
        $tour->setProcess(true);

        $task->getConfiguration()?->addSkillOnDemand($tour);
        $task->getConfiguration()?->addSkillOnDemand($this->skill('loaded-by-model', uid: 10));

        $catalogue = $this->catalogue($task);
        self::assertSame(9, $catalogue->find('guided-tour')['uid'] ?? null);
        self::assertTrue($catalogue->find('guided-tour')['process'] ?? null);
        self::assertNull($catalogue->find('loaded-by-model'));
    }

    #[Test]
    public function anInvokedSkillReachesTheRunAsAForcedSkill(): void
    {
        $augmentation = $this->catalogue($this->task())->augmentationFor('content-tour');

        self::assertNotNull($augmentation);
        self::assertCount(1, $augmentation->forcedSkills);
        self::assertSame('content-tour', $augmentation->forcedSkills[0]->getIdentifier());
    }

    #[Test]
    public function aSkillThatIsDisabledOrUnknownDegradesToNone(): void
    {
        $catalogue = $this->catalogue($this->task());

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
        $task = $this->task();
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
