<?php

/**
 * McpServiceProvider.php
 *
 * 注册 BeikeShop 内置 MCP server 的路由与单例。
 *
 * @copyright  2026 beikeshop.com - All Rights Reserved
 */

namespace Beike\MCP\Providers;

use Beike\MCP\Server\McpServer;
use Illuminate\Support\ServiceProvider;

class McpServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 安装向导期间不挂载 MCP 路由, 避免在尚未迁移完成时被探测
        if (is_installer()) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../Routes/mcp.php');
    }

    public function register(): void
    {
        $this->app->singleton(McpServer::class, function ($app) {
            return new McpServer();
        });
    }
}
