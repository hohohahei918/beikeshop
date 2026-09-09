<?php

/**
 * admin.php
 *
 * 后台路由（自动加 admin 前缀与 admin_auth 中间件）：
 * - POST admin/mcp/regenerate-token  重新生成访问令牌
 * - GET  admin/mcp/test              测试连接
 */

use Illuminate\Support\Facades\Route;
use Plugin\Mcp\Controllers\AdminMcpController;

Route::post('mcp/regenerate-token', [AdminMcpController::class, 'regenerateToken'])->name('plugin.mcp.regenerate_token');
Route::get('mcp/test', [AdminMcpController::class, 'testConnection'])->name('plugin.mcp.test');
