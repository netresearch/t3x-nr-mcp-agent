<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Hook;

use Netresearch\NrMcpAgent\Checker\McpConnectionChecker;
use Throwable;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Flushes the MCP tool cache and verifies the connection when an MCP server record is saved.
 *
 * Registered via $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']
 *
 * Note: SC_OPTIONS hooks are instantiated via GeneralUtility::makeInstance() outside the DI
 * container context, so constructor injection is not available here.
 */
final class McpServerCacheFlushHook
{
    private const TABLE = 'tx_nrmcpagent_mcp_server';

    /**
     * @param string $status 'new' or 'update'
     * @param string $table
     * @param string|int $id
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        string|int $id,
        array $fieldArray,
        DataHandler $dataHandler,
    ): void {
        if ($table !== self::TABLE) {
            return;
        }

        // Flush the entire MCP tools cache — it only contains tool lists, so this is safe
        // and avoids complexity of resolving the exact cache key (which requires the full row).
        try {
            $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
            $cache = $cacheManager->getCache('nr_mcp_agent_tools');
            if ($cache instanceof FrontendInterface) {
                $cache->flush();
            }
        } catch (Throwable) {
            // Cache not available — nothing to flush
        }

        // Resolve real UID — new records use a temporary string ID until after the save
        $resolvedId = is_int($id) ? $id : ($dataHandler->substNEWwithIDs[$id] ?? 0);
        $uid = is_int($resolvedId) ? $resolvedId : (is_string($resolvedId) || is_float($resolvedId) ? (int) $resolvedId : 0);
        if ($uid === 0) {
            return;
        }

        // Load the full saved record (fieldArray only contains changed fields)
        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE)
            ->select(['*'], self::TABLE, ['uid' => $uid])
            ->fetchAssociative();

        if (!is_array($row)) {
            return;
        }

        // SSE transport cannot be verified automatically yet
        $transport = is_string($row['transport'] ?? null) ? $row['transport'] : 'stdio';
        if ($transport !== 'stdio') {
            return;
        }

        /** @var McpConnectionChecker $checker */
        $checker = GeneralUtility::makeInstance(McpConnectionChecker::class);
        $error = $checker->check($row);

        /** @var FlashMessageService $flashMessageService */
        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        $queue = $flashMessageService->getMessageQueueByIdentifier();

        if ($error === null) {
            $queue->addMessage(GeneralUtility::makeInstance(
                FlashMessage::class,
                'The MCP server connected successfully and returned its tool list.',
                'MCP connection OK',
                ContextualFeedbackSeverity::OK,
                true,
            ));
        } else {
            $queue->addMessage(GeneralUtility::makeInstance(
                FlashMessage::class,
                'Could not connect to the MCP server: ' . $error,
                'MCP connection failed',
                ContextualFeedbackSeverity::ERROR,
                true,
            ));
        }
    }
}
