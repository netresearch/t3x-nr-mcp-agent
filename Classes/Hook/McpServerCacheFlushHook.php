<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Hook;

use Throwable;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Flushes the MCP tool cache when an MCP server record is saved via the TYPO3 backend.
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
    }
}
