<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Hook;

use Throwable;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Flushes the MCP tool cache when an MCP server record is saved via the TYPO3 backend.
 *
 * Registered via $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']
 */
final class McpServerCacheFlushHook
{
    private const TABLE = 'tx_nrmcpagent_mcp_server';

    public function __construct(
        private readonly CacheManager $cacheManager,
    ) {}

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
            $cache = $this->cacheManager->getCache('nr_mcp_agent_tools');
            if ($cache instanceof FrontendInterface) {
                $cache->flush();
            }
        } catch (Throwable) {
            // Cache not available — nothing to flush
        }
    }
}
