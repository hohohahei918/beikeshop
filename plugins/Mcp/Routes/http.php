<?php

/**
 * http.php
 *
 * 无前缀路由（自动加载）：公开 MCP 端点 POST /mcp。
 * 该文件由 PluginServiceProvider 以无前缀方式加载，鉴权由 McpAuth 中间件完成。
 */

use Illuminate\Support\Facades\Route;
use Plugin\Mcp\Controllers\McpController;
use Plugin\Mcp\Middleware\McpAuth;

Route::match(['GET', 'POST'], 'mcp', McpController::class)
    ->middleware(McpAuth::class)
    ->name('mcp.endpoint');
