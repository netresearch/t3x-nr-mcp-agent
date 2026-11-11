<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Site\SiteLlmSettings;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ApprovalHandBackReason;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\InputHandBackReason;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Enum\SiteConfigurationRefusal;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\ChatTask\SiteConfigurationSelector;
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use Netresearch\NrMcpAgent\Service\RunActivityRecorder;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The AI configuration a site names for itself wins over the chat Task's
 * (ADR-033). What the chat cannot run on is refused before any model call,
 * never replaced by the Task's configuration.
 *
 * The site setting is read through nr-llm's own ConfigurationResolver and
 * SiteLlmSettings, built here around a SiteFinder double, so a test that
 * expects the site's configuration fails when the setting is not read.
 */
#[CoversClass(ChatService::class)]
#[CoversClass(SiteConfigurationSelector::class)]
final class ChatSiteConfigurationTest extends TestCase
{
    private const TASK_CONFIGURATION_UID = 1;

    private const SITE_CONFIGURATION_UID = 2;

    private const RUN = 'run-uuid-site';

    private const OTHER_TASK_CONFIGURATION_UID = 4;

    /** Whether an administrator gave the chat's Task another configuration. */
    private bool $taskConfigurationChanged = false;

    private ?AgentRunRequest $request = null;

    private int $runs = 0;

    private int $approvals = 0;

    private int $inputs = 0;

    /** What the site `shop` names in `nrllm.configuration`; '' names none. */
    private string $siteSetting = 'shop-ai';

    private bool $siteModelHasToolFlag = true;

    private bool $siteProviderCallsTools = true;

    private bool $siteConfigurationUsesCriteria = false;

    /** Whether the configuration the site names exists. */
    private bool $siteConfigurationExists = true;

    /** The configuration a waiting run was started on. */
    private int $runConfigurationUid = self::TASK_CONFIGURATION_UID;

    /** Whether nr-llm lets the chat read the waiting run. */
    private bool $runReadable = true;

    /** Whether the adapter of the site's model cannot be set up at all. */
    private bool $siteAdapterFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        $backendUser                = $this->createStub(BackendUserAuthentication::class);
        $backendUser->user          = ['uid' => 5];
        $backendUser->userGroupsUID = [];
        $backendUser->method('isAdmin')->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    // ---- a turn -------------------------------------------------------------

    #[Test]
    public function aSiteWithItsOwnConfigurationRunsTheChatOnIt(): void
    {
        $conversation = $this->turnAboutPage(10);

        self::assertSame(1, $this->runs);
        self::assertNotNull($this->request);
        self::assertSame(self::SITE_CONFIGURATION_UID, $this->request->configuration->getUid());
        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        // The site configuration's prompt replaces the Task configuration's;
        // the Task's template stays.
        $system = $this->systemPrompt();
        self::assertStringContainsString('SITE PERSONA', $system);
        self::assertStringNotContainsString('TASK PERSONA', $system);
        self::assertStringContainsString('CHAT TEMPLATE', $system);
        // The term lists of that site still apply: nr-llm reads the site from the run.
        self::assertSame('shop', $this->request->options?->getSite());
    }

    /**
     * The Task configuration's term lists keep applying on the site's
     * configuration (owner decision of 2026-11-11, nr-llm ADR-290): its bans
     * are added to the site's, never dropped. nr-llm reads both and keeps the
     * value for a resumed run.
     */
    #[Test]
    public function onASitesConfigurationTheTaskConfigurationsTermListsStillApply(): void
    {
        $this->turnAboutPage(10);

        self::assertNotNull($this->request);
        self::assertSame([self::TASK_CONFIGURATION_UID], $this->request->options?->getGuardrailConfigurations());
    }

    #[Test]
    public function onTheTasksConfigurationNoFurtherTermListsAreNamed(): void
    {
        $this->siteSetting = '';

        $this->turnAboutPage(10);

        self::assertNotNull($this->request);
        self::assertSame([], $this->request->options?->getGuardrailConfigurations());
    }

    /**
     * A worker runs many turns with one service: a turn that follows one on
     * the site's configuration does not inherit its value.
     */
    #[Test]
    public function theNamedTermListsAreChosenAgainOnEveryTurn(): void
    {
        $service      = $this->service(10);
        $conversation = $this->conversation(10);
        $conversation->appendMessage(MessageRole::User, 'Ändere die Überschrift');

        $service->processConversation($conversation);
        self::assertSame([self::TASK_CONFIGURATION_UID], $this->request?->options?->getGuardrailConfigurations());

        // The site drops its setting and the Task gets another configuration:
        // the turn runs on that one, and the earlier Task configuration is no
        // longer named.
        $this->siteSetting              = '';
        $this->taskConfigurationChanged = true;
        $conversation->appendMessage(MessageRole::User, 'Und noch etwas');
        $service->processConversation($conversation);
        self::assertSame(self::OTHER_TASK_CONFIGURATION_UID, $this->request?->configuration->getUid());

        self::assertSame([], $this->request?->options?->getGuardrailConfigurations());
    }

    #[Test]
    public function aSiteWithoutItsOwnConfigurationKeepsTheTasksConfiguration(): void
    {
        $this->siteSetting = '';

        $this->turnAboutPage(10);

        self::assertNotNull($this->request);
        self::assertSame(self::TASK_CONFIGURATION_UID, $this->request->configuration->getUid());
        self::assertStringContainsString('TASK PERSONA', $this->systemPrompt());
    }

    #[Test]
    public function aConversationAboutNoPageKeepsTheTasksConfiguration(): void
    {
        $this->turnAboutPage(0);

        self::assertNotNull($this->request);
        self::assertSame(self::TASK_CONFIGURATION_UID, $this->request->configuration->getUid());
    }

    #[Test]
    public function aModelWithoutToolCallingIsRefusedBeforeAnyModelCall(): void
    {
        $this->siteModelHasToolFlag = false;

        $conversation = $this->turnAboutPage(10);

        self::assertSame(0, $this->runs, 'neither the site model nor the Task configuration may be called');
        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertSame(SiteConfigurationRefusal::NoToolCalling->value, $conversation->getErrorCode());
        self::assertSame(
            ['site' => 'shop', 'configuration' => 'Shop AI', 'model' => 'Small Model'],
            SiteConfigurationRefusal::decode($conversation->getErrorMessage()),
        );
    }

    #[Test]
    public function aProviderWithoutToolCallingIsRefusedBeforeAnyModelCall(): void
    {
        $this->siteProviderCallsTools = false;

        $conversation = $this->turnAboutPage(10);

        self::assertSame(0, $this->runs);
        self::assertSame(SiteConfigurationRefusal::NoToolCalling->value, $conversation->getErrorCode());
    }

    #[Test]
    public function aConfigurationThatPicksItsModelByCriteriaIsRefused(): void
    {
        $this->siteConfigurationUsesCriteria = true;

        $conversation = $this->turnAboutPage(10);

        self::assertSame(0, $this->runs);
        self::assertSame(SiteConfigurationRefusal::NoFixedModel->value, $conversation->getErrorCode());
    }

    #[Test]
    public function aConfigurationTheSiteNamesButThatDoesNotExistIsRefused(): void
    {
        $this->siteConfigurationExists = false;

        $conversation = $this->turnAboutPage(10);

        self::assertSame(0, $this->runs, 'no fall back to the Task configuration');
        self::assertSame(SiteConfigurationRefusal::Unusable->value, $conversation->getErrorCode());
        self::assertSame('shop', SiteConfigurationRefusal::decode($conversation->getErrorMessage())['site']);
    }

    /**
     * A retry of a turn chooses again: the site set its own configuration
     * after the first attempt.
     */
    #[Test]
    public function aRetriedTurnUsesTheConfigurationTheSiteNamesNow(): void
    {
        $this->siteSetting = '';
        $conversation      = $this->turnAboutPage(10);
        self::assertNotNull($this->request);
        self::assertSame(self::TASK_CONFIGURATION_UID, $this->request->configuration->getUid());

        $this->siteSetting = 'shop-ai';
        $conversation->setStatus(ConversationStatus::Failed);
        $this->service(10)->resumeConversation($conversation);

        self::assertSame(2, $this->runs);
        self::assertSame(self::SITE_CONFIGURATION_UID, $this->request->configuration->getUid());
    }

    // ---- a waiting run --------------------------------------------------------

    /**
     * The run was proposed on the Task's configuration; the site has named
     * its own since. Approving or denying would continue the run with a model
     * call on the old configuration.
     */
    #[Test]
    public function aDecisionIsHeldBackWhenTheSiteNowNamesAnotherConfiguration(): void
    {
        $service      = $this->service(10);
        $conversation = $this->parked(10);
        $service->recordDecision($conversation, true, 'digest-abc');

        $service->processConversation($conversation);

        self::assertSame(0, $this->approvals, 'approve() must not be reached');
        self::assertSame(ConversationStatus::AwaitingApproval, $conversation->getStatus());
        self::assertSame(self::RUN, $conversation->getApprovalRunUuid());
        self::assertSame(ApprovalHandBackReason::SiteConfiguration->value, $conversation->getErrorCode());
        self::assertFalse($conversation->hasPendingApprovalDecision(), 'the card is decidable again');
    }

    #[Test]
    public function aDenialIsHeldBackToo(): void
    {
        $service      = $this->service(10);
        $conversation = $this->parked(10);
        $service->recordDecision($conversation, false, 'digest-abc');

        $service->processConversation($conversation);

        self::assertSame(0, $this->approvals);
        self::assertSame(ApprovalHandBackReason::SiteConfiguration->value, $conversation->getErrorCode());
    }

    #[Test]
    public function aDecisionIsHeldBackWhenTheSiteNowNamesAConfigurationWithoutToolCalling(): void
    {
        $this->runConfigurationUid  = self::SITE_CONFIGURATION_UID;
        $this->siteModelHasToolFlag = false;
        $service                    = $this->service(10);
        $conversation               = $this->parked(10);
        $service->recordDecision($conversation, true, 'digest-abc');

        $service->processConversation($conversation);

        self::assertSame(0, $this->approvals);
        self::assertSame(ApprovalHandBackReason::SiteConfiguration->value, $conversation->getErrorCode());
    }

    #[Test]
    public function aDecisionOnARunOfTheSitesConfigurationIsCarriedOut(): void
    {
        $this->runConfigurationUid = self::SITE_CONFIGURATION_UID;
        $service                   = $this->service(10);
        $conversation              = $this->parked(10);
        $service->recordDecision($conversation, true, 'digest-abc');

        $service->processConversation($conversation);

        self::assertSame(1, $this->approvals);
    }

    #[Test]
    public function aDecisionWhereTheSiteNamesNoConfigurationIsCarriedOutAsBefore(): void
    {
        $this->siteSetting = '';
        $service           = $this->service(10);
        $conversation      = $this->parked(10);
        $service->recordDecision($conversation, true, 'digest-abc');

        $service->processConversation($conversation);

        self::assertSame(1, $this->approvals);
    }

    /**
     * Whether the run may go on there is unknown when the site's provider
     * cannot even be set up, so it does not.
     */
    #[Test]
    public function aDecisionIsHeldBackWhenTheSitesProviderCannotBeSetUp(): void
    {
        $this->runConfigurationUid = self::SITE_CONFIGURATION_UID;
        $this->siteAdapterFails    = true;
        $service                   = $this->service(10);
        $conversation              = $this->parked(10);
        $service->recordDecision($conversation, true, 'digest-abc');

        $service->processConversation($conversation);

        self::assertSame(0, $this->approvals);
        self::assertSame(ApprovalHandBackReason::SiteConfiguration->value, $conversation->getErrorCode());
    }

    /** A run the chat may not read is left to nr-llm, which refuses it itself. */
    #[Test]
    public function aDecisionOnARunTheChatCannotReadIsLeftToNrLlm(): void
    {
        $this->runReadable = false;
        $service           = $this->service(10);
        $conversation      = $this->parked(10);
        $service->recordDecision($conversation, true, 'digest-abc');

        $service->processConversation($conversation);

        self::assertSame(1, $this->approvals);
    }

    #[Test]
    public function anAnswerIsHeldBackWhenTheSiteNowNamesAnotherConfiguration(): void
    {
        $service      = $this->service(10);
        $conversation = $this->asking(10);
        $service->recordInput($conversation, ['start' => 'meta'], 'digest-abc', 'Meta Description');

        $service->processConversation($conversation);

        self::assertSame(0, $this->inputs, 'submitInput() must not be reached');
        self::assertSame(ConversationStatus::AwaitingInput, $conversation->getStatus());
        self::assertSame(InputHandBackReason::SiteConfiguration->value, $conversation->getErrorCode());
        self::assertFalse($conversation->hasPendingInputSubmission());
    }

    #[Test]
    public function anAnswerOnARunOfTheSitesConfigurationIsTaken(): void
    {
        $this->runConfigurationUid = self::SITE_CONFIGURATION_UID;
        $service                   = $this->service(10);
        $conversation              = $this->asking(10);
        $service->recordInput($conversation, ['start' => 'meta'], 'digest-abc', 'Meta Description');

        $service->processConversation($conversation);

        self::assertSame(1, $this->inputs);
    }

    // ---- set-up ---------------------------------------------------------------

    private function turnAboutPage(int $pageId): Conversation
    {
        $conversation = $this->conversation($pageId);
        $conversation->appendMessage(MessageRole::User, 'Ändere die Überschrift');

        $this->service($pageId)->processConversation($conversation);

        return $conversation;
    }

    private function conversation(int $pageId): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(5);
        $conversation->setViewContext($pageId, 'web_layout', 0);

        return $conversation;
    }

    private function parked(int $pageId): Conversation
    {
        $conversation = $this->conversation($pageId);
        $conversation->appendMessage(MessageRole::User, 'Ändere die Überschrift');
        $conversation->setStatus(ConversationStatus::AwaitingApproval);
        $conversation->setApprovalRunUuid(self::RUN);

        return $conversation;
    }

    private function asking(int $pageId): Conversation
    {
        $conversation = $this->conversation($pageId);
        $conversation->appendMessage(MessageRole::User, 'Prüfe die Seite');
        $conversation->appendMessage(MessageRole::Assistant, 'Mit welchem Punkt soll ich beginnen?');
        $conversation->setStatus(ConversationStatus::AwaitingInput);
        $conversation->setApprovalRunUuid(self::RUN);

        return $conversation;
    }

    private function systemPrompt(): string
    {
        self::assertNotNull($this->request);
        $system = $this->request->messages[0] ?? null;
        self::assertInstanceOf(ChatMessage::class, $system);

        return $system->content;
    }

    private function service(int $pageId): ChatService
    {
        // Read when asked, so a test can change the setting between two turns
        // of the same service, as a long-running worker sees it.
        $site       = fn (): Site => new Site('shop', 1, ['settings' => $this->siteSetting === '' ? [] : ['nrllm' => ['configuration' => $this->siteSetting]]]);
        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willReturnCallback($site);
        $siteFinder->method('getSiteByIdentifier')->willReturnCallback($site);
        $siteFinder->method('getAllSites')->willReturn([]);

        $userContext = $this->createStub(UserContextPrompt::class);
        $userContext->method('readablePageId')->willReturn($pageId);

        $taskModel              = $this->createStub(LlmModel::class);
        $taskConfiguration      = $this->configuration(self::TASK_CONFIGURATION_UID, 'Backend AI Chat', 'TASK PERSONA', $taskModel, false);
        $otherTaskConfiguration = $this->configuration(self::OTHER_TASK_CONFIGURATION_UID, 'Other Chat', 'TASK PERSONA', $taskModel, false);

        $siteModel = $this->createStub(LlmModel::class);
        $siteModel->method('supportsTools')->willReturn($this->siteModelHasToolFlag);
        $siteModel->method('getName')->willReturn('Small Model');
        $siteConfiguration = $this->configuration(self::SITE_CONFIGURATION_UID, 'Shop AI', 'SITE PERSONA', $siteModel, $this->siteConfigurationUsesCriteria);

        $configurations = $this->createStub(LlmConfigurationRepository::class);
        $configurations->method('findOneByIdentifier')->willReturnCallback(
            fn (string $identifier): ?LlmConfiguration => $identifier === 'shop-ai' && $this->siteConfigurationExists ? $siteConfiguration : null,
        );

        $task = $this->createStub(Task::class);
        $task->method('getConfiguration')->willReturnCallback(
            fn (): LlmConfiguration => $this->taskConfigurationChanged ? $otherTaskConfiguration : $taskConfiguration,
        );
        $task->method('getPromptTemplate')->willReturn('CHAT TEMPLATE');
        $taskRepository = $this->createStub(TaskRepository::class);
        $taskRepository->method('findByUid')->willReturn($task);

        $toolProvider = $this->createStubForIntersectionOfInterfaces([ProviderInterface::class, ToolCapableInterface::class]);
        $toolProvider->method('supportsTools')->willReturn(true);
        $plainProvider = $this->createStub(ProviderInterface::class);
        $adapters      = $this->createStub(ProviderAdapterRegistryInterface::class);
        $adapters->method('createAdapterFromModel')->willReturnCallback(
            function (LlmModel $model) use ($siteModel, $plainProvider, $toolProvider): ProviderInterface {
                if ($model === $siteModel && $this->siteAdapterFails) {
                    throw new ProviderConfigurationException('The provider of the model has no API key', 1);
                }

                return $model === $siteModel && !$this->siteProviderCallsTools ? $plainProvider : $toolProvider;
            },
        );

        $runtime = $this->createStub(AgentRuntimeInterface::class);
        $runtime->method('run')->willReturnCallback(function (AgentRunRequest $request): AgentRunResult {
            $this->request = $request;
            ++$this->runs;

            return $this->completed();
        });
        $runtime->method('approve')->willReturnCallback(function (): AgentRunResult {
            ++$this->approvals;

            return $this->completed();
        });
        $runtime->method('submitInput')->willReturnCallback(function (): AgentRunResult {
            ++$this->inputs;

            return $this->completed();
        });

        $runs = $this->createStub(AgentRunRepositoryInterface::class);
        $runs->method('findByUuid')->willReturn($this->runReadable ? $this->waitingRun() : null);

        $repository = $this->createStub(ConversationRepository::class);
        $repository->method('updateIf')->willReturn(true);

        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(9);

        $resolver = new ConfigurationResolver($configurations, new SiteLlmSettings($siteFinder));

        return new ChatService(
            $repository,
            $config,
            $runtime,
            $this->createStub(PendingApprovalReaderInterface::class),
            $runs,
            $taskRepository,
            $adapters,
            $this->createStub(ResourceFactory::class),
            $siteFinder,
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $userContext,
            $this->createStub(RunActivityRecorder::class),
            configurationResolver: $resolver,
            siteConfigurations: new SiteConfigurationSelector($resolver, $adapters),
        );
    }

    private function configuration(int $uid, string $name, string $prompt, LlmModel $model, bool $criteria): LlmConfiguration
    {
        $configuration = $this->createStub(LlmConfiguration::class);
        $configuration->method('getUid')->willReturn($uid);
        $configuration->method('getName')->willReturn($name);
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('hasAccessRestrictions')->willReturn(false);
        $configuration->method('getSystemPrompt')->willReturn($prompt);
        $configuration->method('usesCriteriaSelection')->willReturn($criteria);
        // Mode and model are separate fields: a configuration in criteria
        // mode can still hold a model record. The mode decides.
        $configuration->method('getLlmModel')->willReturn($model);

        return $configuration;
    }

    private function waitingRun(): AgentRun
    {
        $run        = (new ReflectionClass(AgentRun::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionClass($run);
        foreach (['uuid' => self::RUN, 'beUser' => 5, 'status' => 'waiting_for_approval', 'configurationUid' => $this->runConfigurationUid] as $name => $value) {
            $reflection->getProperty($name)->setValue($run, $value);
        }

        return $run;
    }

    private function completed(): AgentRunResult
    {
        return new AgentRunResult(AgentRunOutcome::COMPLETED, self::RUN, [], new ToolLoopResult('Erledigt.', [], 1, false, new UsageStatistics(1, 1, 2)));
    }
}
