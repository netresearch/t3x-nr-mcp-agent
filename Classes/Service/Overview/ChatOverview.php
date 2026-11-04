<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Service\Overview;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Budget\UserBudgetStatusService;
use Netresearch\NrLlm\Service\Formatting\LocalizedNumberFormatter;
use Netresearch\NrLlm\Service\Usage\ExtensionUsageReaderInterface;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Repository\ChatWorkerRepository;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationStatisticsRepository;
use Netresearch\NrMcpAgent\Enum\ChatAccessDecision;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Service\ChatAccess;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\ChatTask\ChatTaskProblem;
use Netresearch\NrMcpAgent\Service\ChatTask\ChatTaskResolver;
use Netresearch\NrMcpAgent\Service\ChatTask\ResolvedChatTask;
use Netresearch\NrMcpAgent\Service\UnavailableToolsReaderInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as CoreExtensionConfiguration;

/**
 * What the overview module shows, measured for the viewer (ADR-031).
 *
 * Readiness asks the rules the chat itself applies (ChatAccess,
 * ChatTaskResolver), so the page cannot say "ready" while a turn would be
 * refused. A state that was not measured is reported as `unchecked`, never as
 * `ok`. Administrators see the installation's figures and the configuration;
 * everyone else sees their own figures only.
 */
final readonly class ChatOverview
{
    /** Per-row states, rendered as text and icon. */
    public const OK = 'ok';

    public const PROBLEM = 'problem';

    public const WARNING = 'warning';

    public const INFO = 'info';

    public const UNCHECKED = 'unchecked';

    /** The extension's own settings template: a fixed path, never a value from outside. */
    private const EXT_CONF_TEMPLATE = __DIR__ . '/../../../ext_conf_template.txt';

    private const ACTIVE_STATUSES = [
        ConversationStatus::Processing,
        ConversationStatus::Locked,
        ConversationStatus::ToolLoop,
    ];

    public function __construct(
        private ExtensionConfiguration $config,
        private CoreExtensionConfiguration $coreConfiguration,
        private ChatTaskResolver $taskResolver,
        private ChatWorkerRepository $workers,
        private ConversationRepository $conversations,
        private ConversationStatisticsRepository $statistics,
        private ExtensionUsageReaderInterface $usage,
        private UserBudgetStatusService $budget,
        private UnavailableToolsReaderInterface $unavailableTools,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(BackendUserAuthentication $viewer, OverviewPeriod $period): array
    {
        $isAdmin   = $viewer->isAdmin();
        $viewerUid = $this->uid($viewer);
        $scope     = $isAdmin ? null : $viewerUid;

        return [
            'isAdmin'       => $isAdmin,
            'period'        => $period,
            'readiness'     => $this->readiness($viewer),
            'taskMapping'   => $isAdmin ? $this->taskMapping() : [],
            'configuration' => $isAdmin ? $this->settings() : [],
            'conversations' => $this->conversationCounts($scope, $period),
            'failures'      => $this->statistics->countFailedByErrorCode($scope),
            'openPoints'    => $this->statistics->countOpenPoints($scope),
            'waiting'       => $this->statistics->findWaitingForUser($viewerUid, 10),
            'usage'         => $this->usage($scope, $period),
            'budget'        => $this->budget->forUser($viewerUid, LocalizedNumberFormatter::forBackendUser())->toArray(),
        ];
    }

    /**
     * @return list<array{key: string, state: string, args: list<string|int>, fix: string}>
     */
    private function readiness(BackendUserAuthentication $viewer): array
    {
        $rows   = [];
        $access = (new ChatAccess($this->config))->decide($viewer);
        $rows[] = match ($access) {
            ChatAccessDecision::Allowed => $this->row('access.allowed', self::OK),
            ChatAccessDecision::NoTask  => $this->config->getGroupTaskMapping() === []
                ? $this->row('access.noTask', self::PROBLEM, [], 'settings')
                : $this->row('access.noTaskForYourGroups', self::PROBLEM, [], 'settings'),
            ChatAccessDecision::NotInAllowedGroup => $this->row('access.notInAllowedGroup', self::PROBLEM, [], 'settings'),
        };

        $taskUid = $this->config->getLlmTaskUid($this->groupIds($viewer));
        if ($taskUid === 0) {
            // No task for this viewer: the access row above already says so.
            $rows[] = $this->processingRow();

            return $rows;
        }

        $resolved = $this->taskResolver->check($taskUid, $this->actor($viewer));
        $rows[]   = $resolved instanceof ResolvedChatTask
            ? $this->row('task.ok', self::OK, [$resolved->task->getName(), $resolved->configuration->getName()])
            : $this->taskProblemRow($resolved, $taskUid);

        if ($resolved instanceof ResolvedChatTask) {
            $rows[] = $this->toolSupportRow($resolved->configuration);
            $rows[] = $this->unavailableToolsRow($resolved->configuration, $viewer);
        }

        $rows[] = $this->processingRow();

        return $rows;
    }

    /**
     * @return array{key: string, state: string, args: list<string|int>, fix: string}
     */
    private function taskProblemRow(ChatTaskProblem $problem, int $taskUid): array
    {
        return match ($problem) {
            ChatTaskProblem::TaskMissing             => $this->row('task.missing', self::PROBLEM, [$taskUid], 'nrllm_tasks'),
            ChatTaskProblem::ConfigurationMissing    => $this->row('task.configurationMissing', self::PROBLEM, [$taskUid], 'nrllm_tasks'),
            ChatTaskProblem::ConfigurationDisabled   => $this->row('task.configurationDisabled', self::PROBLEM, [$taskUid], 'nrllm_configurations'),
            ChatTaskProblem::ConfigurationRestricted => $this->row('task.configurationRestricted', self::PROBLEM, [$taskUid], 'nrllm_configurations'),
        };
    }

    /**
     * @return array{key: string, state: string, args: list<string|int>, fix: string}
     */
    private function toolSupportRow(LlmConfiguration $configuration): array
    {
        $model = $configuration->getLlmModel();
        if (!$model instanceof Model) {
            // A configuration that picks its model by criteria at run time has
            // no fixed model to ask.
            return $this->row('tools.unchecked', self::UNCHECKED, [$configuration->getName()]);
        }

        return $model->getCapabilitySet()->has(ModelCapability::TOOLS)
            ? $this->row('tools.ok', self::OK, [$model->getName()])
            : $this->row('tools.missing', self::PROBLEM, [$model->getName()], 'nrllm_models');
    }

    /**
     * @return array{key: string, state: string, args: list<string|int>, fix: string}
     */
    private function unavailableToolsRow(LlmConfiguration $configuration, BackendUserAuthentication $viewer): array
    {
        $count = count($this->unavailableTools->read($configuration, $viewer));

        return $count === 0
            ? $this->row('unavailableTools.none', self::INFO)
            : $this->row('unavailableTools.some', self::INFO, [$count], 'nrllm_tools');
    }

    /**
     * @return array{key: string, state: string, args: list<string|int>, fix: string}
     */
    private function processingRow(): array
    {
        if ($this->config->getProcessingStrategy() !== 'worker') {
            return $this->row('processing.exec', self::INFO);
        }

        $idle    = $this->workers->countIdle();
        $waiting = $this->conversations->countWaitingForWorker();
        if ($idle > 0) {
            return $this->row('processing.workerIdle', self::OK, [$idle, $waiting]);
        }

        // A worker busy with a turn leaves no row, so no idle worker is not
        // proof that none runs. Turns then start a process of their own.
        return $this->row('processing.noIdleWorker', self::WARNING, [$waiting]);
    }

    /**
     * Each groupTaskMapping row, checked as a member of the mapped group. The
     * row for everyone else is checked as an administrator: who falls under
     * it depends on the user, and the readiness rows above check the viewer.
     *
     * @return list<array{group: int, task: int, state: string, key: string, taskName: string, configurationName: string}>
     */
    private function taskMapping(): array
    {
        $rows    = [];
        $admin   = AiActorContext::backendUser(0, true, []);
        $pairs   = $this->config->getGroupTaskMapping();
        $pairs[] = [0, $this->config->getLlmTaskUid([])];
        foreach ($pairs as [$groupUid, $taskUid]) {
            if ($taskUid === 0) {
                // Left unset on purpose: these users do not get the chat.
                $rows[] = [
                    'group'             => $groupUid,
                    'task'              => $taskUid,
                    'state'             => self::INFO,
                    'key'               => 'task.mapping.none',
                    'taskName'          => '',
                    'configurationName' => '',
                ];

                continue;
            }

            // A mapped row is checked as a member of its group, so a
            // configuration withheld from that group shows here.
            $actor    = $groupUid > 0 ? AiActorContext::backendUser(0, false, [$groupUid]) : $admin;
            $resolved = $this->taskResolver->check($taskUid, $actor);
            $rows[]   = [
                'group'             => $groupUid,
                'task'              => $taskUid,
                'state'             => $resolved instanceof ResolvedChatTask ? self::OK : self::PROBLEM,
                'key'               => $resolved instanceof ResolvedChatTask ? 'task.mapping.ok' : 'task.mapping.' . $resolved->value,
                'taskName'          => $resolved instanceof ResolvedChatTask ? $resolved->task->getName() : '',
                'configurationName' => $resolved instanceof ResolvedChatTask ? $resolved->configuration->getName() : '',
            ];
        }

        return $rows;
    }

    /**
     * Every setting of ext_conf_template.txt with its value and whether that
     * is the default.
     *
     * @return list<array{key: string, value: string, default: string, isDefault: bool}>
     */
    private function settings(): array
    {
        $raw     = $this->coreConfiguration->get('nr_mcp_agent');
        $current = is_array($raw) ? $raw : [];
        $rows    = [];
        foreach ($this->templateDefaults() as $key => $default) {
            $value  = $current[$key] ?? $default;
            $value  = is_scalar($value) ? (string) $value : '';
            $rows[] = ['key' => $key, 'value' => $value, 'default' => $default, 'isDefault' => $value === $default];
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function templateDefaults(): array
    {
        $lines = is_readable(self::EXT_CONF_TEMPLATE) ? file(self::EXT_CONF_TEMPLATE, FILE_IGNORE_NEW_LINES) : false;
        if ($lines === false) {
            return [];
        }

        $defaults = [];
        foreach ($lines as $line) {
            if (preg_match('/^([A-Za-z][A-Za-z0-9_.]*)\s*=\s*(.*)$/', $line, $match) === 1) {
                $defaults[$match[1]] = trim($match[2]);
            }
        }

        return $defaults;
    }

    /**
     * @return array{active: int, awaitingApproval: int, awaitingInput: int, failed: int, idle: int, archived: int, started: int, startedWithSkill: int}
     */
    private function conversationCounts(?int $scope, OverviewPeriod $period): array
    {
        $byStatus = $this->statistics->countByStatus($scope);
        $active   = 0;
        foreach (self::ACTIVE_STATUSES as $status) {
            $active += $byStatus[$status->value] ?? 0;
        }

        $from = $period->from->getTimestamp();
        $to   = $period->to->getTimestamp();

        return [
            'active'           => $active,
            'awaitingApproval' => $byStatus[ConversationStatus::AwaitingApproval->value] ?? 0,
            'awaitingInput'    => $byStatus[ConversationStatus::AwaitingInput->value] ?? 0,
            'failed'           => $byStatus[ConversationStatus::Failed->value] ?? 0,
            'idle'             => $byStatus[ConversationStatus::Idle->value] ?? 0,
            'archived'         => $this->statistics->countArchived($scope),
            'started'          => $this->statistics->countStarted($from, $to, $scope),
            'startedWithSkill' => $this->statistics->countStartedWithSkill($from, $to, $scope),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function usage(?int $scope, OverviewPeriod $period): array
    {
        $extension  = ChatService::CALLER_SOURCE_EXTENSION;
        $totals     = $this->usage->totals($extension, $period->from, $period->to, $scope);
        $operations = $this->usage->operations($extension, $period->from, $period->to, $scope);
        $coverage   = $this->usage->coverage();
        $formatter  = LocalizedNumberFormatter::forBackendUser();
        $calls      = 0;
        $failed     = 0;
        foreach ($operations as $operation) {
            $calls += $operation->calls;
            $failed += $operation->failedCalls;
        }

        // Per-call telemetry is purged after its retention: a period that
        // starts before the oldest stored call is counted only from there.
        $countedFrom = $coverage->oldestCallAt !== null && $coverage->oldestCallAt > $period->from->getTimestamp()
            ? $coverage->oldestCallAt
            : null;

        return [
            'cost'             => $formatter->usd($totals->estimatedCost),
            'requests'         => $formatter->number($totals->requests),
            'tokens'           => $formatter->number($totals->tokens),
            'calls'            => $calls,
            'failedCalls'      => $failed,
            'telemetryEnabled' => $coverage->enabled,
            'retentionDays'    => $coverage->retentionDays,
            'callsCountedFrom' => $countedFrom,
        ];
    }

    /**
     * @param list<string|int> $args
     *
     * @return array{key: string, state: string, args: list<string|int>, fix: string}
     */
    private function row(string $key, string $state, array $args = [], string $fix = ''): array
    {
        return ['key' => $key, 'state' => $state, 'args' => $args, 'fix' => $fix];
    }

    private function uid(BackendUserAuthentication $user): int
    {
        $uid = $user->user['uid'] ?? 0;

        return is_numeric($uid) ? (int) $uid : 0;
    }

    /**
     * @return list<int>
     */
    private function groupIds(BackendUserAuthentication $user): array
    {
        return array_values(array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $user->userGroupsUID,
        ));
    }

    private function actor(BackendUserAuthentication $user): AiActorContext
    {
        return AiActorContext::backendUser($this->uid($user), $user->isAdmin(), $this->groupIds($user));
    }
}
