<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service\ChatTask;

use Netresearch\NrLlm\Domain\Model\BackendUserGroup;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrMcpAgent\Exception\ChatNotConfiguredException;
use Netresearch\NrMcpAgent\Service\ChatTask\ChatTaskProblem;
use Netresearch\NrMcpAgent\Service\ChatTask\ChatTaskResolver;
use Netresearch\NrMcpAgent\Service\ChatTask\ResolvedChatTask;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * The chat's Task → Configuration rule, asked without running a turn: the
 * overview module shows the answer, the chat throws it.
 */
final class ChatTaskResolverTest extends TestCase
{
    #[Test]
    public function aMissingTaskIsReported(): void
    {
        self::assertSame(ChatTaskProblem::TaskMissing, $this->resolver(null)->check(4, AiActorContext::backendUser(2)));
    }

    #[Test]
    public function aTaskWithoutConfigurationIsReported(): void
    {
        $task = new Task();

        self::assertSame(ChatTaskProblem::ConfigurationMissing, $this->resolver($task)->check(4, AiActorContext::backendUser(2)));
    }

    #[Test]
    public function aDisabledConfigurationIsReported(): void
    {
        $configuration = new LlmConfiguration();
        $configuration->setIsActive(false);

        self::assertSame(ChatTaskProblem::ConfigurationDisabled, $this->resolver($this->task($configuration))->check(4, AiActorContext::backendUser(2)));
    }

    #[Test]
    public function aConfigurationRestrictedToOtherGroupsIsReportedForAnEditorOnly(): void
    {
        $configuration = $this->restricted(7);

        self::assertSame(ChatTaskProblem::ConfigurationRestricted, $this->resolver($this->task($configuration))->check(4, AiActorContext::backendUser(2, false, [3])));
        self::assertInstanceOf(ResolvedChatTask::class, $this->resolver($this->task($configuration))->check(4, AiActorContext::backendUser(2, false, [7])));
        self::assertInstanceOf(ResolvedChatTask::class, $this->resolver($this->task($configuration))->check(4, AiActorContext::backendUser(1, true, [])));
    }

    #[Test]
    public function aUsableConfigurationIsResolved(): void
    {
        $configuration = new LlmConfiguration();
        $configuration->setIsActive(true);

        $task = $this->task($configuration);

        $resolved = $this->resolver($task)->check(4, AiActorContext::backendUser(2));

        self::assertInstanceOf(ResolvedChatTask::class, $resolved);
        self::assertSame($task, $resolved->task);
        self::assertSame($configuration, $resolved->configuration);
    }

    #[Test]
    public function resolveThrowsTheMessageTheChatShows(): void
    {
        $this->expectException(ChatNotConfiguredException::class);
        $this->expectExceptionMessage('nr-llm Task with uid 4 not found');

        $this->resolver(null)->resolve(4, AiActorContext::backendUser(2));
    }

    private function resolver(?Task $task): ChatTaskResolver
    {
        $repository = $this->createStub(TaskRepository::class);
        $repository->method('findByUid')->willReturn($task);

        return new ChatTaskResolver($repository);
    }

    private function task(LlmConfiguration $configuration): Task
    {
        $task = new Task();
        $task->setConfiguration($configuration);

        return $task;
    }

    private function restricted(int $groupUid): LlmConfiguration
    {
        $group = $this->createStub(BackendUserGroup::class);
        $group->method('getUid')->willReturn($groupUid);
        /** @var ObjectStorage<BackendUserGroup> $groups */
        $groups = new ObjectStorage();
        $groups->attach($group);

        $configuration = new LlmConfiguration();
        $configuration->setIsActive(true);
        $configuration->setBeGroups($groups);

        return $configuration;
    }
}
