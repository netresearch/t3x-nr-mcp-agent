<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Service\ChangeList\ConversationChangeReader;
use Netresearch\NrMcpAgent\Service\ChangeList\RecordChange;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use Netresearch\NrMcpAgent\Service\RunActivityRecorder;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * What the chat service hands the change view (ADR-034): the runs of the
 * conversation, read for its owner, with the writes of records from before
 * the run's uuid was kept; and the configuration's draft-workspace setting,
 * read from the run's own configuration.
 */
#[CoversClass(ChatService::class)]
final class ChatServiceChangesTest extends TestCase
{
    private const OWNER = 7;

    /** @var list<string> */
    private array $eventsAskedFor = [];

    private function agentRun(int $owner = self::OWNER, int $configuration = 0): AgentRun
    {
        $run        = (new ReflectionClass(AgentRun::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionClass($run);
        $reflection->getProperty('beUser')->setValue($run, $owner);
        $reflection->getProperty('configurationUid')->setValue($run, $configuration);

        return $run;
    }

    private function service(?LlmConfigurationRepository $configurations = null): ChatService
    {
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->method('events')->willReturnCallback(function (AiActorContext $actor, string $uuid): array {
            $this->eventsAskedFor[] = $uuid . '@' . $actor->backendUserUid;

            return [new AgentRunEvent(0, 1, 0, 'tool_changes', 1, 0.0, [
                'toolName'       => 'update_record',
                'changes'        => [['table' => 'pages', 'uid' => $uuid === 'run-a' ? 1 : 2, 'action' => 'updated', 'workspace' => 0, 'writeTarget' => true, 'restrictions' => []]],
                'changeCoverage' => 'datahandler',
                'changesOmitted' => 0,
            ], 0)];
        });
        $runs = $this->createMock(AgentRunRepositoryInterface::class);
        $runs->method('findByUuid')->willReturnCallback(fn (string $uuid): ?AgentRun => in_array($uuid, ['run-a', 'run-b'], true) ? $this->agentRun(configuration: 5) : null);

        return new ChatService(
            $this->createMock(ConversationRepository::class),
            $this->createStub(ExtensionConfiguration::class),
            $runtime,
            $this->createMock(PendingApprovalReaderInterface::class),
            $runs,
            $this->createMock(TaskRepository::class),
            $this->createMock(ProviderAdapterRegistryInterface::class),
            $this->createMock(ResourceFactory::class),
            $this->createMock(SiteFinder::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $this->createMock(UserContextPrompt::class),
            $this->createMock(RunActivityRecorder::class),
            configurations: $configurations,
            changeReader: new ConversationChangeReader($runtime, $runs),
        );
    }

    private function conversation(): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser(self::OWNER);
        // Two runs, the first resumed once, and a record from before the uuid was kept.
        $conversation->appendRunRecord(['outcome' => 'completed', 'tools' => [], 'writes' => [['table' => 'tt_content', 'uid' => 9, 'complete' => true]], 'waiting' => []]);
        $conversation->appendRunRecord(['outcome' => 'awaiting_approval', 'run' => 'run-a', 'tools' => [], 'writes' => [], 'waiting' => []]);
        $conversation->appendRunRecord(['outcome' => 'completed', 'run' => 'run-a', 'tools' => [], 'writes' => [], 'waiting' => []]);
        $conversation->appendRunRecord(['outcome' => 'completed', 'run' => 'run-b', 'tools' => [], 'writes' => [], 'waiting' => []]);

        return $conversation;
    }

    #[Test]
    public function theRunsOfTheConversationAreReadForItsOwnerAndOldRecordsContributeTheirWrites(): void
    {
        $result = $this->service()->changesOf($this->conversation());

        self::assertSame(['run-a@' . self::OWNER, 'run-b@' . self::OWNER], $this->eventsAskedFor);
        self::assertSame(['tt_content:9', 'pages:1', 'pages:2'], array_map(static fn (RecordChange $c): string => $c->key(), $result->changes));
        self::assertTrue($result->legacy);
        self::assertSame(2, $result->runs);
    }

    #[Test]
    public function withoutAReaderTheListIsEmpty(): void
    {
        $service = new ChatService(
            $this->createMock(ConversationRepository::class),
            $this->createStub(ExtensionConfiguration::class),
            $this->createMock(AgentRuntimeInterface::class),
            $this->createMock(PendingApprovalReaderInterface::class),
            $this->createMock(AgentRunRepositoryInterface::class),
            $this->createMock(TaskRepository::class),
            $this->createMock(ProviderAdapterRegistryInterface::class),
            $this->createMock(ResourceFactory::class),
            $this->createMock(SiteFinder::class),
            new DocumentExtractorRegistry([]),
            new UploadMimeTypeMap(),
            $this->createMock(UserContextPrompt::class),
            $this->createMock(RunActivityRecorder::class),
        );

        self::assertSame([], $service->changesOf($this->conversation())->changes);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function settingProvider(): array
    {
        return ['required' => [true], 'not required' => [false]];
    }

    #[Test]
    #[DataProvider('settingProvider')]
    public function theDraftWorkspaceSettingIsReadFromTheRunsOwnConfiguration(bool $required): void
    {
        $configuration = $this->createMock(LlmConfiguration::class);
        $configuration->method('getRequireDraftWorkspace')->willReturn($required);
        $configurations = $this->createMock(LlmConfigurationRepository::class);
        $configurations->expects(self::once())->method('findByUid')->with(5)->willReturn($configuration);
        $conversation = new Conversation();
        $conversation->setApprovalRunUuid('run-a');

        self::assertSame($required, $this->service($configurations)->requiresDraftWorkspace($conversation));
    }

    /** A run or configuration that cannot be read is not "required": nr-llm still refuses the write itself. */
    #[Test]
    public function aRunThatCannotBeReadRequiresNothing(): void
    {
        $conversation = new Conversation();
        $conversation->setApprovalRunUuid('gone');

        self::assertFalse($this->service($this->createMock(LlmConfigurationRepository::class))->requiresDraftWorkspace($conversation));
        self::assertFalse($this->service()->requiresDraftWorkspace(new Conversation()));
    }
}
