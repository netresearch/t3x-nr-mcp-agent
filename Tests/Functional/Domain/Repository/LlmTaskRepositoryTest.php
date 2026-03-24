<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Domain\Repository;

use Netresearch\NrLlm\Domain\Model\Model as LlmModel;
use Netresearch\NrMcpAgent\Domain\Repository\LlmTaskRepository;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class LlmTaskRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    private LlmTaskRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tx_nrllm_model.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tx_nrllm_configuration.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tx_nrllm_task.csv');
        $this->subject = $this->get(LlmTaskRepository::class);
    }

    #[Test]
    public function resolveModelByTaskUidReturnsModelAndPrompts(): void
    {
        $result = $this->subject->resolveModelByTaskUid(1);

        self::assertArrayHasKey('model', $result);
        self::assertArrayHasKey('systemPrompt', $result);
        self::assertArrayHasKey('promptTemplate', $result);
        self::assertInstanceOf(LlmModel::class, $result['model']);
        self::assertSame('You are a helpful assistant.', $result['systemPrompt']);
        self::assertSame('Answer: {{input}}', $result['promptTemplate']);
    }

    #[Test]
    public function resolveModelByTaskUidReturnsMappedModel(): void
    {
        $result = $this->subject->resolveModelByTaskUid(1);

        $model = $result['model'];
        self::assertSame('claude-sonnet', $model->getIdentifier());
        self::assertSame('claude-sonnet-4', $model->getModelId());
    }

    #[Test]
    public function resolveModelByTaskUidThrowsForNonExistentTask(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/task uid 999/');

        $this->subject->resolveModelByTaskUid(999);
    }
}
