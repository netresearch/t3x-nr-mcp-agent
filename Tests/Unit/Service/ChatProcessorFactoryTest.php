<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Service\ChatProcessorFactory;
use Netresearch\NrMcpAgent\Service\ExecChatProcessor;
use Netresearch\NrMcpAgent\Service\WorkerChatProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ChatProcessorFactoryTest extends TestCase
{
    #[Test]
    public function workerStrategySelectsTheWorkerProcessor(): void
    {
        [$factory, , $worker] = $this->factory('worker');

        self::assertSame($worker, $factory->create());
    }

    #[Test]
    public function execStrategySelectsTheExecProcessor(): void
    {
        [$factory, $exec] = $this->factory('exec');

        self::assertSame($exec, $factory->create());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherStrategies(): iterable
    {
        yield 'empty' => [''];
        yield 'unknown' => ['queue'];
        yield 'different case' => ['Worker'];
    }

    #[Test]
    #[DataProvider('otherStrategies')]
    public function anyOtherValueKeepsTheExecDefault(string $strategy): void
    {
        [$factory, $exec] = $this->factory($strategy);

        self::assertSame($exec, $factory->create());
    }

    /**
     * @return array{ChatProcessorFactory, ExecChatProcessor, WorkerChatProcessor}
     */
    private function factory(string $strategy): array
    {
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('getProcessingStrategy')->willReturn($strategy);

        // ExecChatProcessor is final; its constructor does not matter here.
        $exec = (new ReflectionClass(ExecChatProcessor::class))->newInstanceWithoutConstructor();
        $worker = new WorkerChatProcessor();

        return [new ChatProcessorFactory($configuration, $exec, $worker), $exec, $worker];
    }
}
