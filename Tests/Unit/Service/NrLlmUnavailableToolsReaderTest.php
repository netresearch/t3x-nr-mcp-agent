<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use Netresearch\NrMcpAgent\Service\NrLlmUnavailableToolsReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The reader has to work against an nr-llm that has the resolver (ADR-201 there)
 * and against one that does not — the extension supports both.
 */
#[CoversClass(NrLlmUnavailableToolsReader::class)]
final class NrLlmUnavailableToolsReaderTest extends TestCase
{
    private function container(?object $resolver): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with(NrLlmUnavailableToolsReader::RESOLVER_SERVICE)->willReturn($resolver !== null);
        $container->method('get')->willReturn($resolver);

        return $container;
    }

    private function decision(string $name, bool $allowed, ToolDenialReason $reason): ToolPolicyDecision
    {
        return new ToolPolicyDecision($name, $allowed, ToolDataClass::EDITOR_CONTENT, TrustZone::LOCAL, ToolDataClass::EDITOR_CONTENT, $reason);
    }

    #[Test]
    public function anOlderNrLlmWithoutTheResolverYieldsAnEmptyList(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        // Asked, not fetched: fetching a service that does not exist throws.
        $container->expects(self::never())->method('get');
        $reader = new NrLlmUnavailableToolsReader($container);

        self::assertSame([], $reader->read($this->createStub(LlmConfiguration::class), null));
    }

    #[Test]
    public function theResolversRefusalsBecomeNameAndReason(): void
    {
        $resolver = new class ([
            $this->decision('read_source', false, ToolDenialReason::CONFIGURATION_GROUP),
            $this->decision('get_env_raw', false, ToolDenialReason::REQUIRES_ADMIN),
            // An allowed decision is not an unavailable tool, whatever the resolver returns.
            $this->decision('get_page_content', true, ToolDenialReason::NONE),
            'not a decision',
        ]) {
            /** @param list<mixed> $decisions */
            public function __construct(private readonly array $decisions) {}

            /** @return list<mixed> */
            public function unavailable(mixed $configuration, mixed $user): array
            {
                return $this->decisions;
            }
        };

        $reader = new NrLlmUnavailableToolsReader($this->container($resolver));

        self::assertSame(
            [
                ['name' => 'read_source', 'reason' => 'configurationGroup'],
                ['name' => 'get_env_raw', 'reason' => 'requiresAdmin'],
            ],
            $reader->read($this->createStub(LlmConfiguration::class), null),
        );
    }

    #[Test]
    public function aFailingResolverYieldsAnEmptyListInsteadOfBreakingTheChat(): void
    {
        $resolver = new class {
            /** @return list<mixed> */
            public function unavailable(mixed $configuration, mixed $user): array
            {
                throw new RuntimeException('tool state table missing');
            }
        };

        $reader = new NrLlmUnavailableToolsReader($this->container($resolver));

        self::assertSame([], $reader->read($this->createStub(LlmConfiguration::class), null));
    }
}
