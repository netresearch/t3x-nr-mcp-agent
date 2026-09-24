<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Model\BackendUserGroup;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * The Task's Configuration must be one the user may run (NEXT-172, ADR-015):
 * active, and not restricted to backend groups the user is not in. The agent
 * runtime does not check either, so the chat does before it runs a turn.
 */
final class ChatServiceConfigurationAccessTest extends TestCase
{
    private bool $ran = false;

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    /**
     * @param list<int> $configurationGroups
     */
    private function turn(bool $active, array $configurationGroups, array $userGroups, bool $admin = false): Conversation
    {
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 5];
        $backendUser->userGroupsUID = $userGroups;
        $backendUser->method('isAdmin')->willReturn($admin);
        $GLOBALS['BE_USER'] = $backendUser;

        $groups = new ObjectStorage();
        foreach ($configurationGroups as $uid) {
            $group = $this->createStub(BackendUserGroup::class);
            $group->method('getUid')->willReturn($uid);
            $groups->attach($group);
        }

        $configuration = $this->createMock(LlmConfiguration::class);
        $configuration->method('isActive')->willReturn($active);
        $configuration->method('getName')->willReturn('Press');
        $configuration->method('getBeGroups')->willReturn($groups);
        $configuration->method('hasAccessRestrictions')->willReturn($configurationGroups !== []);
        $configuration->method('getLlmModel')->willReturn($this->createMock(LlmModel::class));
        $configuration->method('getSystemPrompt')->willReturn('');

        $task = $this->createMock(Task::class);
        $task->method('getConfiguration')->willReturn($configuration);
        $task->method('getPromptTemplate')->willReturn('');
        $taskRepository = $this->createMock(TaskRepository::class);
        $taskRepository->method('findByUid')->willReturn($task);

        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(9);

        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->method('run')->willReturnCallback(function (): AgentRunResult {
            $this->ran = true;

            return new AgentRunResult(AgentRunOutcome::COMPLETED, 'run', [], new ToolLoopResult('ok', [], 1, false, new UsageStatistics(1, 1, 2)));
        });

        $adapters = $this->createMock(ProviderAdapterRegistryInterface::class);
        $adapters->method('createAdapterFromModel')->willReturn($this->createMock(ProviderInterface::class));

        $service = new ChatService(
            $this->createMock(ConversationRepository::class),
            $config,
            $runtime,
            $this->createMock(PendingApprovalReaderInterface::class),
            $this->createMock(AgentRunRepositoryInterface::class),
            $taskRepository,
            $adapters,
            $this->createMock(ResourceFactory::class),
            $this->createMock(SiteFinder::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $this->createMock(UserContextPrompt::class),
        );

        $conversation = new Conversation();
        $conversation->setBeUser(5);
        $conversation->appendMessage(MessageRole::User, 'Hello');
        $service->processConversation($conversation);

        return $conversation;
    }

    #[Test]
    public function aDisabledConfigurationFailsTheTurnWithAReason(): void
    {
        $conversation = $this->turn(false, [], [1]);

        self::assertFalse($this->ran);
        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertStringContainsString('"Press" of Task 9 is disabled', $conversation->getErrorMessage());
    }

    #[Test]
    public function aConfigurationRestrictedToOtherGroupsFailsTheTurn(): void
    {
        $conversation = $this->turn(true, [7], [1, 2]);

        self::assertFalse($this->ran);
        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertStringContainsString('restricted to other backend groups', $conversation->getErrorMessage());
    }

    #[Test]
    public function aMemberOfAnAllowedGroupRunsTheTurn(): void
    {
        $conversation = $this->turn(true, [7], [2, 7]);

        self::assertTrue($this->ran);
        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
    }

    #[Test]
    public function anAdministratorMayRunARestrictedConfiguration(): void
    {
        $this->turn(true, [7], [], true);

        self::assertTrue($this->ran);
    }

    #[Test]
    public function anUnrestrictedActiveConfigurationRuns(): void
    {
        $this->turn(true, [], []);

        self::assertTrue($this->ran);
    }
}
