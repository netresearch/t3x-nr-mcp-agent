<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Tests\Functional\Service;

use Netresearch\NrMcpAgent\Domain\Model\Conversation;
use Netresearch\NrMcpAgent\Service\UserContextPrompt;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The page the user is on reaches the prompt only when they may see it
 * (NEXT-172). The browser sends a page id; the permission check runs here,
 * as the user the turn runs as.
 */
final class UserContextPromptTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-mcp-agent',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages_view_context.csv');
    }

    private function conversationOf(int $beUser, int $pageId): Conversation
    {
        $conversation = new Conversation();
        $conversation->setBeUser($beUser);
        $conversation->setViewContext($pageId, '');

        return $conversation;
    }

    #[Test]
    public function aPageTheUserMaySeeIsNamedWithItsUidAndTitle(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);

        $prompt = $this->get(UserContextPrompt::class)->build($this->conversationOf(1, 10));

        self::assertStringContainsString('- Selected page: uid 10, title "Startseite"', $prompt);
        self::assertStringContainsString('they mean page uid 10', $prompt);
    }

    #[Test]
    public function aPageTheUserMayNotSeeIsLeftOut(): void
    {
        // The editor has no web mount and no permission on page 10.
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(2);

        $prompt = $this->get(UserContextPrompt::class)->build($this->conversationOf(2, 10));

        self::assertStringNotContainsString('Startseite', $prompt);
        self::assertStringNotContainsString('uid 10', $prompt);
        // The answer language is still there: the block is not all-or-nothing.
        self::assertStringContainsString('Answer language', $prompt);
    }

    #[Test]
    public function aPageThatDoesNotExistIsLeftOut(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);

        $prompt = $this->get(UserContextPrompt::class)->build($this->conversationOf(1, 999));

        self::assertStringNotContainsString('uid 999', $prompt);
    }
}
