<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\ChatTask;

use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrMcpAgent\Exception\ChatNotConfiguredException;

/**
 * The chat's rule for which nr-llm Configuration a turn runs: the Task the
 * extension configuration names (llmTaskUid, or a groupTaskMapping row,
 * ADR-015), the Configuration that Task names, active, and usable by the actor
 * under its backend-group restriction.
 *
 * {@see check()} asks the rule without running anything, which is what the
 * overview module shows; {@see resolve()} is what a chat turn calls and throws
 * the message the chat displays.
 */
final readonly class ChatTaskResolver
{
    public function __construct(
        private TaskRepository $taskRepository,
        private ConfigurationResolver $configurationResolver = new ConfigurationResolver(),
    ) {}

    public function check(int $taskUid, AiActorContext $actor): ResolvedChatTask|ChatTaskProblem
    {
        $task = $taskUid > 0 ? $this->taskRepository->findByUid($taskUid) : null;
        if ($task === null) {
            return ChatTaskProblem::TaskMissing;
        }

        $configuration = $task->getConfiguration();
        if ($configuration === null) {
            return ChatTaskProblem::ConfigurationMissing;
        }

        // The Task names a Configuration; whether this user may run it is the
        // Configuration's to say — its active flag and its backend-group
        // restriction, as nr-llm's own modules check them. The runtime does
        // not, so without this a Task mapped to a group (ADR-015), or the
        // single llmTaskUid, would run a Configuration withheld from the user.
        if (!$configuration->isActive()) {
            return ChatTaskProblem::ConfigurationDisabled;
        }

        if (!$this->configurationResolver->actorMayUse($configuration, $actor)) {
            return ChatTaskProblem::ConfigurationRestricted;
        }

        return new ResolvedChatTask($task, $configuration);
    }

    /**
     * @throws ChatNotConfiguredException when the Task or its Configuration is missing or not usable
     */
    public function resolve(int $taskUid, AiActorContext $actor): ResolvedChatTask
    {
        $result = $this->check($taskUid, $actor);
        if ($result instanceof ResolvedChatTask) {
            return $result;
        }

        $name = $this->taskRepository->findByUid($taskUid)?->getConfiguration()?->getName() ?? '';

        throw new ChatNotConfiguredException(match ($result) {
            ChatTaskProblem::TaskMissing           => sprintf('nr-llm Task with uid %d not found', $taskUid),
            ChatTaskProblem::ConfigurationMissing  => sprintf('nr-llm Task with uid %d has no LLM configuration assigned', $taskUid),
            ChatTaskProblem::ConfigurationDisabled => sprintf(
                'The nr-llm configuration "%s" of Task %d is disabled. Ask an administrator to enable it or to assign another Task.',
                $name,
                $taskUid,
            ),
            ChatTaskProblem::ConfigurationRestricted => sprintf(
                'The nr-llm configuration "%s" of Task %d is restricted to other backend groups. Ask an administrator for access or for another Task.',
                $name,
                $taskUid,
            ),
        });
    }
}
