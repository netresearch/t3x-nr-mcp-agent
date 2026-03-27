<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrLlm\Provider\Contract\DocumentCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\VisionCapableInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Domain\Repository\LlmTaskRepository;
use Netresearch\NrMcpAgent\Mcp\McpToolProviderInterface;
use Netresearch\NrMcpAgent\Service\ChatService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Targets mutation survivors in ChatService::getProviderCapabilities() (lines 47-71):
 * - InstanceOf_ + LogicalAnd: VisionCapable check + supportsVision() call
 * - UnwrapArrayValues: array_values() on merged formats must produce a list
 */
class ChatServiceCapabilitiesTest extends TestCase
{
    private function createChatService(
        ProviderInterface $provider,
        ?\Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry $registry = null,
    ): ChatService {
        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $config->method('isMcpEnabled')->willReturn(false);
        $mcpProvider = $this->createMock(McpToolProviderInterface::class);

        $llmTaskRepository = $this->createMock(LlmTaskRepository::class);
        $llmTaskRepository->method('resolveModelByTaskUid')->willReturn([
            'model' => $this->createMock(LlmModel::class),
            'systemPrompt' => '',
            'promptTemplate' => '',
        ]);

        $adapterRegistry = $this->createMock(ProviderAdapterRegistry::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn($provider);

        $registry ??= new \Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry([]);

        return new ChatService(
            $repository,
            $config,
            $mcpProvider,
            $llmTaskRepository,
            $adapterRegistry,
            $this->createMock(ResourceFactory::class),
            $this->createMock(SiteFinder::class),
            $registry,
        );
    }

    // -------------------------------------------------------------------------
    // InstanceOf_ mutation: remove `instanceof VisionCapableInterface` check
    // -------------------------------------------------------------------------

    #[Test]
    public function returnsNoVisionWhenProviderIsNotVisionCapable(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $service = $this->createChatService($provider);

        $caps = $service->getProviderCapabilities();

        self::assertFalse($caps['visionSupported']);
        self::assertSame(0, $caps['maxFileSize']);
        self::assertSame([], $caps['supportedFormats']);
    }

    // -------------------------------------------------------------------------
    // LogicalAnd mutation: remove `&& $provider->supportsVision()` part
    // -------------------------------------------------------------------------

    #[Test]
    public function returnsNoVisionWhenProviderIsVisionCapableButDoesNotSupportVision(): void
    {
        $provider = $this->createMockForIntersectionOfInterfaces([ProviderInterface::class, VisionCapableInterface::class]);
        $provider->method('supportsVision')->willReturn(false);

        $service = $this->createChatService($provider);
        $caps = $service->getProviderCapabilities();

        self::assertFalse($caps['visionSupported']);
    }

    #[Test]
    public function returnsVisionSupportedWhenProviderSupportsVision(): void
    {
        $provider = $this->createMockForIntersectionOfInterfaces([ProviderInterface::class, VisionCapableInterface::class]);
        $provider->method('supportsVision')->willReturn(true);
        $provider->method('getMaxImageSize')->willReturn(5242880);
        $provider->method('getSupportedImageFormats')->willReturn(['image/jpeg', 'image/png']);

        $service = $this->createChatService($provider);
        $caps = $service->getProviderCapabilities();

        self::assertTrue($caps['visionSupported']);
        self::assertSame(5242880, $caps['maxFileSize']);
    }

    // -------------------------------------------------------------------------
    // UnwrapArrayValues: array_values() must produce a sequential-keyed list
    // -------------------------------------------------------------------------

    #[Test]
    public function supportedFormatsIsSequentiallyIndexedList(): void
    {
        $provider = $this->createMockForIntersectionOfInterfaces([ProviderInterface::class, VisionCapableInterface::class]);
        $provider->method('supportsVision')->willReturn(true);
        $provider->method('getMaxImageSize')->willReturn(1024);
        $provider->method('getSupportedImageFormats')->willReturn(['image/jpeg', 'image/png']);

        $service = $this->createChatService($provider);
        $caps = $service->getProviderCapabilities();

        // array_values() ensures the result is a list (0-indexed), not associative
        $keys = array_keys($caps['supportedFormats']);
        self::assertSame(range(0, count($caps['supportedFormats']) - 1), $keys);
    }

    #[Test]
    public function documentFormatsAreMergedIntoSupportedFormats(): void
    {
        $provider = $this->createMockForIntersectionOfInterfaces([ProviderInterface::class, VisionCapableInterface::class, DocumentCapableInterface::class]);
        $provider->method('supportsVision')->willReturn(true);
        $provider->method('getMaxImageSize')->willReturn(1024);
        $provider->method('getSupportedImageFormats')->willReturn(['image/jpeg']);
        $provider->method('supportsDocuments')->willReturn(true);
        $provider->method('getSupportedDocumentFormats')->willReturn(['application/pdf']);

        $service = $this->createChatService($provider);
        $caps = $service->getProviderCapabilities();

        self::assertContains('image/jpeg', $caps['supportedFormats']);
        self::assertContains('application/pdf', $caps['supportedFormats']);
        // Must remain a list even after merge
        self::assertSame(range(0, 1), array_keys($caps['supportedFormats']));
    }

    #[Test]
    public function documentFormatsAreExcludedWhenDocumentSupportDisabled(): void
    {
        $provider = $this->createMockForIntersectionOfInterfaces([ProviderInterface::class, VisionCapableInterface::class, DocumentCapableInterface::class]);
        $provider->method('supportsVision')->willReturn(true);
        $provider->method('getMaxImageSize')->willReturn(1024);
        $provider->method('getSupportedImageFormats')->willReturn(['image/jpeg']);
        $provider->method('supportsDocuments')->willReturn(false);

        $service = $this->createChatService($provider);
        $caps = $service->getProviderCapabilities();

        self::assertNotContains('application/pdf', $caps['supportedFormats']);
        self::assertContains('image/jpeg', $caps['supportedFormats']);
    }

    #[Test]
    public function returnsNoVisionWhenProviderResolutionFails(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $repository = $this->createMock(ConversationRepository::class);
        $config = $this->createStub(ExtensionConfiguration::class);
        $config->method('getLlmTaskUid')->willReturn(1);
        $config->method('isMcpEnabled')->willReturn(false);

        $llmTaskRepository = $this->createMock(LlmTaskRepository::class);
        $llmTaskRepository->method('resolveModelByTaskUid')->willThrowException(new RuntimeException('Model not found'));

        $adapterRegistry = $this->createMock(ProviderAdapterRegistry::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn($provider);

        $service = new ChatService(
            $repository,
            $config,
            $this->createMock(McpToolProviderInterface::class),
            $llmTaskRepository,
            $adapterRegistry,
            $this->createMock(ResourceFactory::class),
            $this->createMock(SiteFinder::class),
            new \Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry([]),
        );

        $caps = $service->getProviderCapabilities();

        self::assertFalse($caps['visionSupported']);
        self::assertSame(0, $caps['maxFileSize']);
        self::assertSame([], $caps['supportedFormats']);
    }

    #[Test]
    public function extractionFormatsAppearsEvenWithoutVisionSupport(): void
    {
        $extractor = $this->createMock(\Netresearch\NrMcpAgent\Document\DocumentExtractorInterface::class);
        $extractor->method('isAvailable')->willReturn(true);
        $extractor->method('getSupportedMimeTypes')->willReturn(['application/pdf']);
        $extractor->method('getSupportedFileExtensions')->willReturn(['pdf']);

        $registry = new \Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry([$extractor]);
        $provider = $this->createMock(ProviderInterface::class); // not VisionCapable

        $service = $this->createChatService($provider, $registry);
        $caps = $service->getProviderCapabilities();

        // Registry contributes file extensions (not MIME types) so the UI accept attribute works
        self::assertContains('pdf', $caps['supportedFormats']);
        self::assertNotContains('application/pdf', $caps['supportedFormats']);
    }

    #[Test]
    public function extractionFormatsMergeWithProviderFormats(): void
    {
        $extractor = $this->createMock(\Netresearch\NrMcpAgent\Document\DocumentExtractorInterface::class);
        $extractor->method('isAvailable')->willReturn(true);
        $extractor->method('getSupportedMimeTypes')->willReturn(['application/pdf']);
        $extractor->method('getSupportedFileExtensions')->willReturn(['pdf']);

        $registry = new \Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry([$extractor]);

        $provider = $this->createMockForIntersectionOfInterfaces([ProviderInterface::class, VisionCapableInterface::class]);
        $provider->method('supportsVision')->willReturn(true);
        $provider->method('getMaxImageSize')->willReturn(1024);
        $provider->method('getSupportedImageFormats')->willReturn(['jpeg']);

        $service = $this->createChatService($provider, $registry);
        $caps = $service->getProviderCapabilities();

        // Provider formats (extensions) and registry extensions are both present
        self::assertContains('jpeg', $caps['supportedFormats']);
        self::assertContains('pdf', $caps['supportedFormats']);
    }

    #[Test]
    public function formatInBothProviderAndRegistryAppearsOnce(): void
    {
        // Both provider and registry claim 'pdf' — dedup must keep only one
        $extractor = $this->createMock(\Netresearch\NrMcpAgent\Document\DocumentExtractorInterface::class);
        $extractor->method('isAvailable')->willReturn(true);
        $extractor->method('getSupportedMimeTypes')->willReturn(['application/pdf']);
        $extractor->method('getSupportedFileExtensions')->willReturn(['pdf']);

        $registry = new \Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry([$extractor]);

        $provider = $this->createMockForIntersectionOfInterfaces([ProviderInterface::class, VisionCapableInterface::class, DocumentCapableInterface::class]);
        $provider->method('supportsVision')->willReturn(true);
        $provider->method('getMaxImageSize')->willReturn(1024);
        $provider->method('getSupportedImageFormats')->willReturn(['jpeg']);
        $provider->method('supportsDocuments')->willReturn(true);
        $provider->method('getSupportedDocumentFormats')->willReturn(['pdf']); // same as registry

        $service = $this->createChatService($provider, $registry);
        $caps = $service->getProviderCapabilities();

        self::assertSame(1, count(array_filter($caps['supportedFormats'], fn($f) => $f === 'pdf')));
        // array_values() must re-index after deduplication so keys are 0..N-1
        self::assertSame(range(0, count($caps['supportedFormats']) - 1), array_keys($caps['supportedFormats']));
    }
}
