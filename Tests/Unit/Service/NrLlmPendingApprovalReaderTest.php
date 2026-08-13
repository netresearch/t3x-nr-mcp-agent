<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunViewFactory;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrMcpAgent\Service\NrLlmPendingApprovalReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The adapter that talks to nr-llm.
 *
 * It had no test in the first version of this branch, and that is exactly where
 * the defect sat: it read the run through AgentRuntime::status(), which strips
 * the suspended state by design (ADR-101). The view factory then answered
 * `unreadable` for every run, so the card could never render — a feature that
 * was inert, behind a seam every other test stubbed.
 *
 * These assertions are therefore about the source of the run and the
 * authorisation that comes with it, not about the view: the factory is final,
 * so the reader is exercised up to the point where it hands the run over.
 */
#[CoversClass(NrLlmPendingApprovalReader::class)]
final class NrLlmPendingApprovalReaderTest extends TestCase
{
    /**
     * The regression guard: the run must come from the repository, which keeps
     * the suspended state, and not from a status projection that drops it.
     */
    #[Test]
    public function theRunIsReadFromTheRepository(): void
    {
        $repository = $this->createMock(AgentRunRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findByUuid')
            ->with('run-uuid-1234')
            ->willReturn(null);

        $reader = new NrLlmPendingApprovalReader($repository, $this->viewFactory());

        self::assertNull($reader->read(AiActorContext::backendUser(1), 'run-uuid-1234'));
    }

    /**
     * A run belonging to somebody else answers exactly like one that does not
     * exist. status() used to do this check; it is now the reader's.
     */
    #[Test]
    public function aRunOfAnotherUserIsIndistinguishableFromAMissingOne(): void
    {
        $repository = $this->createMock(AgentRunRepositoryInterface::class);
        $repository->method('findByUuid')->willReturn($this->runOwnedBy(99));

        $reader = new NrLlmPendingApprovalReader($repository, $this->viewFactory());

        self::assertNull($reader->read(AiActorContext::backendUser(1), 'run-uuid-1234'));
    }

    #[Test]
    public function anEmptyUuidNeverReachesTheRepository(): void
    {
        $repository = $this->createMock(AgentRunRepositoryInterface::class);
        $repository->expects(self::never())->method('findByUuid');

        $reader = new NrLlmPendingApprovalReader($repository, $this->viewFactory());

        self::assertNull($reader->read(AiActorContext::backendUser(1), ''));
    }

    /**
     * The factory is final and cannot be doubled; the tests above never reach
     * it, so an uninitialised instance is enough to construct the reader.
     */
    private function viewFactory(): WaitingRunViewFactory
    {
        /** @var WaitingRunViewFactory $factory */
        $factory = (new ReflectionClass(WaitingRunViewFactory::class))->newInstanceWithoutConstructor();

        return $factory;
    }

    private function runOwnedBy(int $beUser): AgentRun
    {
        $run = (new ReflectionClass(AgentRun::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionClass($run);
        foreach (['uuid' => 'run-uuid-1234', 'beUser' => $beUser, 'status' => 'waiting_for_approval'] as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setValue($run, $value);
        }

        return $run;
    }
}
