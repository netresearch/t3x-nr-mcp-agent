<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use DomainException;
use Netresearch\NrMcpAgent\Service\NrLlmPreviewHeadingLabels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * nr-llm's heading list is not released yet, so the reader is guarded: an
 * nr-llm without it, or one whose list misbehaves, yields no headings, and the
 * card falls back to the editor action label or its generic wording.
 */
#[CoversClass(NrLlmPreviewHeadingLabels::class)]
final class NrLlmPreviewHeadingLabelsTest extends TestCase
{
    /** The shape nr-llm's list is expected to have: a static method. */
    private function staticProvider(): string
    {
        return (new class {
            /** @return list<mixed> */
            public static function labelReferences(): array
            {
                return ['LLL:EXT:nr_llm/x.xlf:approvalPreview.deleteRecord.headingPage', 'not a reference', 42, 'LLL:EXT:nr_llm/x.xlf:approvalPreview.movePage.heading'];
            }
        })::class;
    }

    private function failingProvider(): string
    {
        return (new class {
            /** @return list<string> */
            public static function labelReferences(): array
            {
                throw new DomainException('broken');
            }
        })::class;
    }

    /** The same list on a service. */
    private function serviceProvider(): object
    {
        return new class {
            /** @return list<string> */
            public function labelReferences(): array
            {
                return ['LLL:EXT:nr_llm/x.xlf:approvalPreview.updatePage.heading'];
            }
        };
    }
    #[Test]
    public function anNrLlmWithoutTheListYieldsNoHeadings(): void
    {
        $reader = new NrLlmPreviewHeadingLabels($this->createMock(ContainerInterface::class), 'Netresearch\\NrLlm\\DoesNotExist');

        self::assertSame([], $reader->labelReferences());
    }

    #[Test]
    public function theExpectedClassIsTheDefault(): void
    {
        // Pinned so a rename on either side is a conscious change. Until nr-llm
        // ships the class the default yields nothing.
        self::assertSame('Netresearch\\NrLlm\\Service\\Tool\\ApprovalPreviewHeadings', NrLlmPreviewHeadingLabels::PROVIDER);
        self::assertSame('labelReferences', NrLlmPreviewHeadingLabels::METHOD);
    }

    #[Test]
    public function aStaticListYieldsItsReferencesAndNothingElse(): void
    {
        $reader = new NrLlmPreviewHeadingLabels($this->createMock(ContainerInterface::class), $this->staticProvider());

        self::assertSame(['LLL:EXT:nr_llm/x.xlf:approvalPreview.deleteRecord.headingPage', 'LLL:EXT:nr_llm/x.xlf:approvalPreview.movePage.heading'], $reader->labelReferences());
    }

    #[Test]
    public function aListOnAServiceIsReadFromTheContainer(): void
    {
        $service   = $this->serviceProvider();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with($service::class)->willReturn($service);

        $reader = new NrLlmPreviewHeadingLabels($container, $service::class);

        self::assertSame(['LLL:EXT:nr_llm/x.xlf:approvalPreview.updatePage.heading'], $reader->labelReferences());
    }

    #[Test]
    public function aServiceTheContainerDoesNotHaveYieldsNoHeadings(): void
    {
        $service   = $this->serviceProvider();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new RuntimeException('no such service'));

        self::assertSame([], (new NrLlmPreviewHeadingLabels($container, $service::class))->labelReferences());
    }

    #[Test]
    public function aFailingListYieldsNoHeadingsInsteadOfAnError(): void
    {
        $reader = new NrLlmPreviewHeadingLabels($this->createMock(ContainerInterface::class), $this->failingProvider());

        self::assertSame([], $reader->labelReferences());
    }
}
