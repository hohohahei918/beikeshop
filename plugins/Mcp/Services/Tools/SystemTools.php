<?php

/**
 * SystemTools.php
 *
 * 系统状态类 MCP 工具实现。
 *
 * @package Plugin\Mcp\Services\Tools
 */

namespace Plugin\Mcp\Services\Tools;

use Beike\Models\Product;
use Plugin\Mcp\Services\McpRegistry;
use Plugin\Mcp\Services\McpServer;
use Illuminate\Support\Facades\DB;

class SystemTools
{
    /**
     * 商城与 MCP 服务健康检查
     */
    public static function health(array $args): array
    {
        try {
            DB::connection()->getPdo();
            $dbOk = true;
        } catch (\Throwable $e) {
            $dbOk = false;
        }

        $productCount = 0;
        try {
            $productCount = (int) Product::query()->count();
        } catch (\Throwable $e) {
            // ignore
        }

        return [
            'status'            => 'ok',
            'server_name'       => McpServer::SERVER_NAME,
            'server_version'    => McpServer::SERVER_VERSION,
            'protocol_version'  => McpServer::PROTOCOL_VERSION,
            'mcp_endpoint'      => url('/mcp'),
            'beikeshop_version' => config('beikeshop.version') ?: 'unknown',
            'database'          => $dbOk ? 'ok' : 'error',
            'product_count'     => $productCount,
            'tools_available'   => count((new McpRegistry)->definitions()),
            'php_version'       => PHP_VERSION,
            'time'              => now()->toIso8601String(),
        ];
    }
}
