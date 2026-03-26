<?php

declare(strict_types=1);

defined('TYPO3') or die();

// MCP tool list cache — stores tool definitions per server to avoid reconnecting on every request
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nr_mcp_agent_tools'] ??= [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
    'options' => ['defaultLifetime' => 3600],
    'groups' => ['system'],
];

// Flush MCP tool cache when server records are saved via DataHandler
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
    = \Netresearch\NrMcpAgent\Hook\McpServerCacheFlushHook::class;
