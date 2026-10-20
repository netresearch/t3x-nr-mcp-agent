<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\SkillInvocation as NrLlmInvocation;
use Netresearch\NrLlm\Domain\ValueObject\SubjectRecord;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrMcpAgent\Service\NrLlmSkillInvocation;
use Netresearch\NrMcpAgent\Service\SkillInvocation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The chat's invocation as nr-llm's (ADR-214 item 10, nr-llm ADR-228).
 */
#[CoversClass(NrLlmSkillInvocation::class)]
final class NrLlmSkillInvocationTest extends TestCase
{
    private function request(): AgentRunRequest
    {
        return new AgentRunRequest(
            $this->createStub(LlmConfiguration::class),
            [['role' => 'user', 'content' => 'Los']],
            AiActorContext::backendUser(1),
            options: new ToolOptions(),
            maxIterations: 7,
        );
    }

    #[Test]
    public function thePageBecomesTheSubjectInItsLanguage(): void
    {
        $request = $this->request();

        $invoked = (new NrLlmSkillInvocation())->withInvocation($request, new SkillInvocation('seo-page-tour', 42, 'pages', 10, 1));

        self::assertNotNull($invoked);
        self::assertEquals(new NrLlmInvocation(42, new SubjectRecord(new RecordReference('pages', 10), 1)), $invoked->invocation);
        self::assertSame($request->messages, $invoked->messages);
        self::assertSame($request->options, $invoked->options);
        self::assertSame(7, $invoked->maxIterations);
    }

    #[Test]
    public function withoutAPageThereIsNoSubject(): void
    {
        $invoked = (new NrLlmSkillInvocation())->withInvocation($this->request(), new SkillInvocation('seo-page-tour', 42));

        self::assertEquals(new NrLlmInvocation(42), $invoked?->invocation);
    }

    /** nr-llm names the skill by its record uid; without one there is nothing to invoke. */
    #[Test]
    public function aSkillWithoutAUidCannotBeInvoked(): void
    {
        self::assertNull((new NrLlmSkillInvocation())->withInvocation($this->request(), new SkillInvocation('seo-page-tour', 0, 'pages', 10)));
    }
}
