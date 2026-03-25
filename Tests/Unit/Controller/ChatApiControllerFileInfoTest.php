<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Controller;

use InvalidArgumentException;
use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Controller\ChatApiController;
use Netresearch\NrMcpAgent\Document\DocumentExtractorInterface;
use Netresearch\NrMcpAgent\Document\DocumentExtractorRegistry;
use Netresearch\NrMcpAgent\Domain\Repository\ConversationRepository;
use Netresearch\NrMcpAgent\Service\ChatCapabilitiesInterface;
use Netresearch\NrMcpAgent\Service\ChatProcessorInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;

class ChatApiControllerFileInfoTest extends TestCase
{
    private ChatApiController $subject;
    private ResourceFactory $resourceFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('getAllowedGroupIds')->willReturn([]);

        $chatService = $this->createMock(ChatCapabilitiesInterface::class);
        $chatService->method('getProviderCapabilities')->willReturn([
            'visionSupported' => false,
            'maxFileSize' => 0,
            'supportedFormats' => [],
        ]);

        $this->resourceFactory = $this->createMock(ResourceFactory::class);

        $extractor = $this->createMock(DocumentExtractorInterface::class);
        $extractor->method('isAvailable')->willReturn(true);
        $extractor->method('getSupportedMimeTypes')->willReturn(['application/pdf']);
        $extractor->method('getSupportedFileExtensions')->willReturn(['pdf']);

        $this->subject = new ChatApiController(
            $this->createMock(ConversationRepository::class),
            $this->createMock(ChatProcessorInterface::class),
            $config,
            $chatService,
            $this->resourceFactory,
            $this->createMock(StorageRepository::class),
            new DocumentExtractorRegistry([$extractor]),
        );

        $GLOBALS['BE_USER'] = new stdClass();
        $GLOBALS['BE_USER']->user = ['uid' => 1, 'usergroup' => ''];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    private function makeRequest(array $params = []): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn($params);
        return $request;
    }

    #[Test]
    public function fileInfoReturnsMissingFileUidAs400(): void
    {
        $response = $this->subject->fileInfo($this->makeRequest([]));
        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function fileInfoReturnsInvalidFileUidAs400(): void
    {
        $response = $this->subject->fileInfo($this->makeRequest(['fileUid' => 'abc']));
        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function fileInfoReturnsNotFoundWhenResourceFactoryThrows(): void
    {
        $this->resourceFactory
            ->method('getFileObject')
            ->willThrowException(new InvalidArgumentException());

        $response = $this->subject->fileInfo($this->makeRequest(['fileUid' => '99']));
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function fileInfoReturnsForbiddenWhenNoReadPermission(): void
    {
        $file = $this->createMock(File::class);
        $file->method('checkActionPermission')->with('read')->willReturn(false);

        $this->resourceFactory->method('getFileObject')->willReturn($file);

        $response = $this->subject->fileInfo($this->makeRequest(['fileUid' => '42']));
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function fileInfoReturnsUnsupportedTypeAs422(): void
    {
        $file = $this->createMock(File::class);
        $file->method('checkActionPermission')->with('read')->willReturn(true);
        $file->method('getExtension')->willReturn('exe');

        $this->resourceFactory->method('getFileObject')->willReturn($file);

        $response = $this->subject->fileInfo($this->makeRequest(['fileUid' => '42']));
        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function fileInfoReturnsFileMetadataOnSuccess(): void
    {
        $file = $this->createMock(File::class);
        $file->method('checkActionPermission')->with('read')->willReturn(true);
        $file->method('getExtension')->willReturn('pdf');
        $file->method('getUid')->willReturn(42);
        $file->method('getName')->willReturn('report.pdf');
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getSize')->willReturn(102400);

        $this->resourceFactory->method('getFileObject')->willReturn($file);

        $response = $this->subject->fileInfo($this->makeRequest(['fileUid' => '42']));
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(42, $body['fileUid']);
        self::assertSame('report.pdf', $body['name']);
        self::assertSame('application/pdf', $body['mimeType']);
        self::assertSame(102400, $body['size']);
    }
}
