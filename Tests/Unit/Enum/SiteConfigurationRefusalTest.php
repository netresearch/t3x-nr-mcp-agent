<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Unit\Enum;

use Netresearch\NrMcpAgent\Enum\ApprovalHandBackReason;
use Netresearch\NrMcpAgent\Enum\ConversationErrorCode;
use Netresearch\NrMcpAgent\Enum\InputHandBackReason;
use Netresearch\NrMcpAgent\Enum\ProcessStopReason;
use Netresearch\NrMcpAgent\Enum\SiteConfigurationRefusal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SiteConfigurationRefusal::class)]
final class SiteConfigurationRefusalTest extends TestCase
{
    private const LANGUAGE_DIR = __DIR__ . '/../../../Resources/Private/Language/';

    /**
     * The key is built from the value, so no grep for translate('…') finds
     * it. Every sentence carries the placeholders the chat fills in: the site
     * always, the configuration where the reason knows it, the model where
     * the reason is about the model.
     */
    #[Test]
    public function everyReasonHasItsSentenceWithItsPlaceholdersInBothLanguageFiles(): void
    {
        $placeholders = [
            SiteConfigurationRefusal::Unusable->name      => ['%1$s'],
            SiteConfigurationRefusal::NoFixedModel->name  => ['%1$s', '%2$s'],
            SiteConfigurationRefusal::NoToolCalling->name => ['%1$s', '%2$s', '%3$s'],
        ];

        foreach (['locallang_chat.xlf' => 'source', 'de.locallang_chat.xlf' => 'target'] as $file => $element) {
            $xliff = (string) file_get_contents(self::LANGUAGE_DIR . $file);
            foreach (SiteConfigurationRefusal::cases() as $reason) {
                $pattern = '/id="' . preg_quote($reason->labelKey(), '/') . '"[^>]*>.*?<' . $element . '>([^<]+)<\/' . $element . '>/s';
                self::assertMatchesRegularExpression($pattern, $xliff, $file . ' ' . $reason->name);
                preg_match($pattern, $xliff, $match);
                foreach ($placeholders[$reason->name] as $placeholder) {
                    self::assertStringContainsString($placeholder, $match[1], $file . ' ' . $reason->name);
                }
            }
        }
    }

    /** The codes share the conversation's error-code column, a varchar(32). */
    #[Test]
    public function noReasonCollidesWithAnotherCodeAndEveryOneFitsTheColumn(): void
    {
        foreach (SiteConfigurationRefusal::cases() as $reason) {
            self::assertNull(ConversationErrorCode::tryFrom($reason->value), $reason->name);
            self::assertNull(ApprovalHandBackReason::tryFrom($reason->value), $reason->name);
            self::assertNull(InputHandBackReason::tryFrom($reason->value), $reason->name);
            self::assertNull(ProcessStopReason::tryFrom($reason->value), $reason->name);
            self::assertLessThanOrEqual(32, strlen($reason->value), $reason->name);
        }
    }

    #[Test]
    public function theNamesSurviveTheStoredMessage(): void
    {
        self::assertSame(
            ['site' => 'shop', 'configuration' => 'Shop "AI"', 'model' => 'Mödel'],
            SiteConfigurationRefusal::decode(SiteConfigurationRefusal::encode('shop', 'Shop "AI"', 'Mödel')),
        );
    }

    #[Test]
    public function aStoredMessageThatIsNoListOfNamesGivesEmptyNames(): void
    {
        self::assertSame(['site' => '', 'configuration' => '', 'model' => ''], SiteConfigurationRefusal::decode('LLM configuration "x" not found'));
        self::assertSame(['site' => '', 'configuration' => '', 'model' => ''], SiteConfigurationRefusal::decode('{"site": 5}'));
    }
}
