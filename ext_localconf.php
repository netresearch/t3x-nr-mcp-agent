<?php

declare(strict_types=1);

use Netresearch\NrMcpAgent\Hook\McpServerCacheFlushHook;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

defined('TYPO3') || die();

// MCP tool list cache — stores tool definitions per server to avoid reconnecting on every request
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nr_mcp_agent_tools'] ??= [
    'frontend' => VariableFrontend::class,
    'backend' => Typo3DatabaseBackend::class,
    'options' => ['defaultLifetime' => 3600],
    'groups' => ['system'],
];

// Flush MCP tool cache when server records are saved via DataHandler
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
    = McpServerCacheFlushHook::class;
