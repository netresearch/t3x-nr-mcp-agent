<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityServiceInterface;
use Netresearch\NrMcpAgent\Service\NrLlmEditorActionLabels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(NrLlmEditorActionLabels::class)]
final class NrLlmEditorActionLabelsTest extends TestCase
{
    #[Test]
    public function eachDeclaredActionYieldsItsLabelReferenceUnderTheToolName(): void
    {
        $availability = $this->createMock(ToolAvailabilityServiceInterface::class);
        $availability->method('editorActions')->willReturn([
            'create_page_draft'    => new EditorAction('LLL:EXT:nr_llm/x.xlf:create.label', 'LLL:EXT:nr_llm/x.xlf:create.description', 'icon-create', ['pages']),
            'update_page_metadata' => new EditorAction('LLL:EXT:nr_llm/x.xlf:meta.label', 'LLL:EXT:nr_llm/x.xlf:meta.description', 'icon-meta', ['pages']),
        ]);

        self::assertSame(
            [
                'create_page_draft'    => 'LLL:EXT:nr_llm/x.xlf:create.label',
                'update_page_metadata' => 'LLL:EXT:nr_llm/x.xlf:meta.label',
            ],
            (new NrLlmEditorActionLabels($availability))->labelReferences(),
        );
    }

    /**
     * editorActions() runs every tool's own declaration code. A label is
     * presentation only: a failure there must cost the card its action names,
     * never the card.
     */
    #[Test]
    public function aFailingCatalogueYieldsNoLabelsInsteadOfAnError(): void
    {
        $availability = $this->createMock(ToolAvailabilityServiceInterface::class);
        $availability->method('editorActions')->willThrowException(new RuntimeException('broken declaration'));

        self::assertSame([], (new NrLlmEditorActionLabels($availability))->labelReferences());
    }
}
