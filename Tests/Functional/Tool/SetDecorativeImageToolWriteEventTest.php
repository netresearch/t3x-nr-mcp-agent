<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\SkillToolAllowList;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use Netresearch\NrLlm\Event\AfterAiRecordWrittenEvent;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrMcpAgent\Tool\SetDecorativeImageTool;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * An approved decorative mark is announced as nr-llm announces its own
 * writers' writes: AfterAiRecordWrittenEvent with the reference and the run
 * (nr-llm ADR-187). The run is driven as production drives a write: it
 * suspends for the approval card, and the approved call is resumed.
 */
final class SetDecorativeImageToolWriteEventTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
        __DIR__ . '/../Fixtures/Extensions/alt_text_test',
    ];

    private const RUN_UUID = 'd1f0be47-0000-4000-8000-0000000000ab';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/missing_alt_texts.csv');
    }

    #[Test]
    public function anApprovedMarkIsAnnouncedWithItsReference(): void
    {
        $user            = $this->setUpBackendUser(2);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
        $tool            = $this->get(ToolRegistry::class)->get(SetDecorativeImageTool::NAME);
        self::assertInstanceOf(SetDecorativeImageTool::class, $tool);
        $dispatcher = new class implements EventDispatcherInterface {
            /** @var list<object> */
            public array $dispatched = [];

            public function dispatch(object $event): object
            {
                $this->dispatched[] = $event;

                return $event;
            }
        };
        $service = $this->loop($tool, $dispatcher);
        $context = new ToolExecutionContext(AiActorContext::backendUser(2), $user, new AgentRunReference(91, self::RUN_UUID));

        $service->resume($this->suspend($service, $context), true, $this->configuration(), $context);

        $events = array_values(array_filter($dispatcher->dispatched, static fn (object $event): bool => $event instanceof AfterAiRecordWrittenEvent));
        self::assertCount(1, $events);
        self::assertSame(self::RUN_UUID, $events[0]->correlationId);
        self::assertSame('sys_file_reference:1', (string) $events[0]->record);
        self::assertSame(WriteKind::UPDATED, $events[0]->kind);
        self::assertSame(1, $this->getConnectionPool()->getConnectionForTable('sys_file_reference')
            ->select(['tx_nrmcpagent_decorative'], 'sys_file_reference', ['uid' => 1])->fetchOne());
    }

    private function loop(SetDecorativeImageTool $tool, EventDispatcherInterface $dispatcher): ToolLoopService
    {
        $queue = [
            new CompletionResponse('', 'test-model', UsageStatistics::fromTokens(1, 1), toolCalls: [new ToolCall('call_1', SetDecorativeImageTool::NAME, ['reference' => 1, 'decorative' => true])]),
            new CompletionResponse('Als dekorativ markiert.', 'test-model', UsageStatistics::fromTokens(1, 1)),
        ];
        $models = $this->createStub(LlmServiceManagerInterface::class);
        $models->method('chatWithToolsForConfiguration')->willReturnCallback(static function () use (&$queue): CompletionResponse {
            $next = array_shift($queue);
            self::assertInstanceOf(CompletionResponse::class, $next);

            return $next;
        });

        return new ToolLoopService($models, new ToolRegistry([$tool]), $this->policy(), eventDispatcher: $dispatcher);
    }

    private function suspend(ToolLoopService $service, ToolExecutionContext $context): SuspendedRunState
    {
        try {
            $service->runLoop([['role' => 'user', 'content' => 'Mark the image decorative.']], $this->configuration(), $context, null);
        } catch (ToolApprovalRequiredException $e) {
            return $e->state;
        }

        self::fail('The run did not suspend for the approval card.');
    }

    private function policy(): ToolCallPolicyInterface
    {
        return new class implements ToolCallPolicyInterface {
            public function skillAllowListForRun(LlmConfiguration $configuration, array $forcedSkills = []): SkillToolAllowList
            {
                return new SkillToolAllowList(null);
            }

            public function decide(string $toolName, LlmConfiguration $configuration, ?BackendUserAuthentication $user, ?SkillToolAllowList $runAllowList = null): ToolPolicyDecision
            {
                return $this->decision();
            }

            public function filterOfferable(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user, ?SkillToolAllowList $runAllowList = null): array
            {
                return [SetDecorativeImageTool::NAME];
            }

            public function explain(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user, ?SkillToolAllowList $runAllowList = null): array
            {
                return [$this->decision()];
            }

            private function decision(): ToolPolicyDecision
            {
                return new ToolPolicyDecision(
                    toolName: SetDecorativeImageTool::NAME,
                    allowed: true,
                    dataClass: ToolDataClass::PUBLIC_CONTENT,
                    zone: TrustZone::LOCAL,
                    ceiling: ToolDataClass::SECRET_ADJACENT,
                    reason: ToolDenialReason::NONE,
                );
            }
        };
    }

    private function configuration(): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($model);

        return $configuration;
    }
}
