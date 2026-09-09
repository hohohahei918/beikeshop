<?php

/**
 * McpAuth.php
 *
 * MCP 端点鉴权中间件：校验 Authorization: Bearer <token>，
 * token 与后台生成的插件设置 plugin.mcp.token 一致。
 *
 * @package Plugin\Mcp\Middleware
 */

namespace Plugin\Mcp\Middleware;

use Closure;
use Illuminate\Http\Request;

class McpAuth
{
    public function handle(Request $request, Closure $next)
    {
        $expected = (string) plugin_setting('mcp.token', '');

        if ($expected === '') {
            return response()->json([
                'jsonrpc' => '2.0',
                'id'      => null,
                'error'   => ['code' => -32001, 'message' => 'MCP access token is not configured. Please generate one in the admin plugin page.'],
            ], 401, ['Content-Type' => 'application/json']);
        }

        $auth = (string) $request->header('Authorization', '');
        $token = '';
        if (preg_match('/Bearer\s+([^\s,]+)/i', $auth, $m)) {
            $token = $m[1];
        }

        if ($token === '' || ! hash_equals($expected, $token)) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id'      => null,
                'error'   => ['code' => -32001, 'message' => 'Unauthorized: invalid access token.'],
            ], 401, ['Content-Type' => 'application/json']);
        }

        return $next($request);
    }
}
