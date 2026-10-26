<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Provider\Contract\DocumentCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Configuration\ImageAnalysisTurnSettings;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Document\UploadMimeTypeMap;
use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Enum\ConversationStatus;
use Netresearch\NrMcpAgent\Enum\MessageRole;
use Netresearch\NrMcpAgent\Service\AltText\AltTextImagePolicyInterface;
use Netresearch\NrMcpAgent\Service\ChatService;
use Netresearch\NrMcpAgent\Service\PendingApprovalReaderInterface;
use Netresearch\NrMcpAgent\Service\RunActivityRecorder;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The privacy setting "Bildanalyse" (ADR-026, slice 3) for a document
 * attached in the chat, on real files, the extension's own extractors and
 * its policy: a provider that reads PDFs natively gets the whole file, its
 * photos included, so a PDF the setting keeps back goes as its extracted
 * text only. And a turn follows the setting it was dispatched with, read
 * anew for every turn, also in one long process.
 *
 * File 1 is fileadmin/team/flyer.pdf, file 2 fileadmin/news/flyer.pdf.
 */
final class ChatAttachmentImageAnalysisTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    private ?AgentRunRequest $captured = null;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['fileadmin/team/flyer.pdf', 'fileadmin/news/flyer.pdf'] as $path) {
            $target = $this->instancePath . '/' . $path;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0o775, true);
            }

            copy(__DIR__ . '/../../Fixtures/Documents/sample.pdf', $target);
        }

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/chat_attachment_image_analysis.csv');
        $this->setUpBackendUser(1);
        $this->configure('analyse', '');
    }

    private function configure(string $analysis, string $folders): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_mcp_agent']['imageAnalysis']                = $analysis;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_mcp_agent']['imageAnalysisExcludedFolders'] = $folders;
    }

    /**
     * The chat as a worker runs it: the policy and the turn settings from the
     * container, a provider that reads PDFs natively, the model mocked.
     */
    private function chat(): ChatService
    {
        $provider = $this->createMockForIntersectionOfInterfaces([ProviderInterface::class, DocumentCapableInterface::class]);
        $provider->method('supportsDocuments')->willReturn(true);
        $provider->method('getSupportedDocumentFormats')->willReturn(['pdf']);
        $adapters = $this->createMock(ProviderAdapterRegistryInterface::class);
        $adapters->method('createAdapterFromModel')->willReturn($provider);

        $configuration = $this->createMock(LlmConfiguration::class);
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('getSystemPrompt')->willReturn('');
        $configuration->method('getLlmModel')->willReturn($this->createMock(LlmModel::class));
        $task = $this->createMock(Task::class);
        $task->method('getConfiguration')->willReturn($configuration);
        $task->method('getPromptTemplate')->willReturn('');
        $tasks = $this->createMock(TaskRepository::class);
        $tasks->method('findByUid')->willReturn($task);

        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);

        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->method('run')->willReturnCallback(function (AgentRunRequest $request): AgentRunResult {
            $this->captured = $request;

            return new AgentRunResult(AgentRunOutcome::COMPLETED, 'run-uuid', [], new ToolLoopResult('Done.', [], 1, false, new UsageStatistics(1, 1, 2)));
        });

        return new ChatService(
            $this->createMock(ConversationRepository::class),
            $config,
            $runtime,
            $this->createMock(PendingApprovalReaderInterface::class),
            $this->createMock(AgentRunRepositoryInterface::class),
            $tasks,
            $adapters,
            $this->get(ResourceFactory::class),
            $this->createMock(SiteFinder::class),
            $this->get(DocumentExtractorRegistry::class),
            new UploadMimeTypeMap(),
            $this->createMock(UserContextPrompt::class),
            $this->createMock(RunActivityRecorder::class),
            imagePolicy: $this->get(AltTextImagePolicyInterface::class),
            turnSettings: $this->get(ImageAnalysisTurnSettings::class),
        );
    }

    private function conversation(int $fileUid, string $snapshot = ''): Conversation
    {
        return Conversation::fromRow([
            'uid'            => 1,
            'be_user'        => 1,
            'status'         => 'idle',
            'messages'       => json_encode([['role' => MessageRole::User->value, 'content' => 'Summarise this flyer.', 'fileUid' => $fileUid, 'fileName' => 'flyer.pdf', 'fileMimeType' => 'application/pdf']]),
            'message_count'  => 1,
            'image_analysis' => $snapshot,
        ]);
    }

    /** The type of the block the attachment became in the turn the model got. */
    private function attachmentBlock(ChatService $chat, Conversation $conversation): string
    {
        $this->captured = null;
        $chat->processConversation($conversation);
        self::assertNotNull($this->captured);
        $messages = $this->captured->messages;
        $last     = end($messages);
        self::assertIsArray($last);
        self::assertIsArray($last['content']);
        $block = $last['content'][1];
        self::assertIsArray($block);

        return match ($block['type']) {
            'document' => 'document',
            'text'     => str_starts_with((string) $block['text'], '[Extracted from') ? 'extracted' : 'withheld',
            default    => (string) $block['type'],
        };
    }

    /** The positive direction: analysed and not excluded, the PDF goes as the provider's native document. */
    #[Test]
    public function withImageAnalysisAPdfGoesAsANativeDocument(): void
    {
        self::assertSame('document', $this->attachmentBlock($this->chat(), $this->conversation(1)));
    }

    #[Test]
    public function withContextOnlyAPdfGoesAsItsExtractedTextOnly(): void
    {
        $this->configure('contextOnly', '');

        self::assertSame('extracted', $this->attachmentBlock($this->chat(), $this->conversation(1)));
    }

    #[Test]
    public function aPdfInAnExcludedFolderGoesAsItsExtractedTextOnlyAndOneBesideItNatively(): void
    {
        $this->configure('analyse', 'fileadmin/team/');
        $chat = $this->chat();

        self::assertSame('extracted', $this->attachmentBlock($chat, $this->conversation(1)));
        self::assertSame('document', $this->attachmentBlock($chat, $this->conversation(2)));
    }

    /** One process, two turns, the setting changed between them: the second turn follows it, both ways. */
    #[Test]
    public function aTurnFollowsTheSettingAsItIsWhenTheTurnRuns(): void
    {
        $chat = $this->chat();

        self::assertSame('document', $this->attachmentBlock($chat, $this->conversation(1)));
        $this->configure('contextOnly', '');
        self::assertSame('extracted', $this->attachmentBlock($chat, $this->conversation(1)));
        $this->configure('analyse', '');
        self::assertSame('document', $this->attachmentBlock($chat, $this->conversation(1)));
        $this->configure('analyse', 'fileadmin/team/');
        self::assertSame('extracted', $this->attachmentBlock($chat, $this->conversation(1)));
    }

    /**
     * A worker keeps the configuration it started with. The turn runs with
     * the setting it was dispatched with, both ways, and the worker's own is
     * back afterwards, so an unstamped turn after it follows that again.
     */
    #[Test]
    public function aTurnRunsWithTheSettingItWasDispatchedWithNotTheWorkersOwn(): void
    {
        $chat        = $this->chat();
        $contextOnly = json_encode(['imageAnalysis' => 'contextOnly', 'imageAnalysisExcludedFolders' => ''], JSON_THROW_ON_ERROR);
        $analyse     = json_encode(['imageAnalysis' => 'analyse', 'imageAnalysisExcludedFolders' => ''], JSON_THROW_ON_ERROR);

        self::assertSame('extracted', $this->attachmentBlock($chat, $this->conversation(1, $contextOnly)));
        self::assertSame('analyse', $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_mcp_agent']['imageAnalysis']);
        self::assertSame('document', $this->attachmentBlock($chat, $this->conversation(1)));

        $this->configure('contextOnly', '');
        self::assertSame('document', $this->attachmentBlock($chat, $this->conversation(1, $analyse)));
        self::assertSame('extracted', $this->attachmentBlock($chat, $this->conversation(1)));
    }

    /**
     * The backend request that hands a turn to the worker writes the setting
     * with it, read now; a write that hands nothing over keeps what was
     * stamped.
     */
    #[Test]
    public function handingATurnOverStampsTheSettingOfThatRequest(): void
    {
        $repository   = $this->get(ConversationRepository::class);
        $conversation = new Conversation();
        $conversation->setBeUser(1);
        $conversation->appendMessage(MessageRole::User, 'Hello');

        $uid          = $repository->add($conversation);
        $conversation = $repository->findByUid($uid);
        self::assertNotNull($conversation);
        self::assertSame('', $conversation->getImageAnalysisSnapshot());

        $this->configure('contextOnly', 'fileadmin/team/');
        $conversation->setStatus(ConversationStatus::Processing);
        $repository->update($conversation);
        $stamped = $repository->findByUid($uid)?->getImageAnalysisSnapshot();
        self::assertSame(['imageAnalysis' => 'contextOnly', 'imageAnalysisExcludedFolders' => 'fileadmin/team/'], json_decode((string) $stamped, true));

        $this->configure('analyse', '');
        $conversation->setStatus(ConversationStatus::Idle);
        $repository->update($conversation);
        self::assertSame($stamped, $repository->findByUid($uid)?->getImageAnalysisSnapshot());
    }
}
