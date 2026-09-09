<?php

/**
 * mcp.php
 *
 * MCP Streamable HTTP 单端点路由。
 * 经 mcp 中间件组做时区设置 + admin token 鉴权 + 限流。
 *
 * @copyright  2026 beikeshop.com - All Rights Reserved
 */

use Beike\MCP\Http\Controllers\McpController;
use Illuminate\Support\Facades\Route;

Route::prefix('mcp')
    ->middleware('mcp')
    ->group(function () {
        // 单端点: 同时承接 POST (JSON-RPC 请求) 与 GET (SSE/探测, 当前实现仅做协议探测返回 405)
        Route::post('/', McpController::class)->name('mcp.endpoint');
        Route::get('/', fn () => response()->json([
            'jsonrpc' => '2.0',
            'id'      => null,
            'error'   => [
                'code'    => -32600,
                'message' => 'Invalid Request: POST required. This is a Streamable HTTP MCP endpoint.',
            ],
        ], 405));
    });
