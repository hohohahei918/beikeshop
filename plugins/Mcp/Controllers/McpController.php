<?php

/**
 * McpController.php
 *
 * MCP 端点 HTTP 控制器（streamable HTTP transport）。
 * 支持 JSON 响应与 SSE 响应两种客户端连接方式。
 *
 * 路由：POST /mcp （GET 用于连通性测试），经 Middleware\McpAuth 鉴权。
 *
 * @package Plugin\Mcp\Controllers
 */

namespace Plugin\Mcp\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Plugin\Mcp\Services\McpRegistry;
use Plugin\Mcp\Services\McpServer;

class McpController extends Controller
{
    public function __invoke(Request $request)
    {
        // GET /mcp 仅用于连通性检查（浏览器/监控）
        if ($request->isMethod('GET')) {
            return response()->json([
                'status' => 'ok',
                'mcp'    => true,
                'server' => McpServer::SERVER_NAME,
                'version'=> McpServer::SERVER_VERSION,
            ], 200, ['MCP-Protocol-Version' => McpServer::PROTOCOL_VERSION]);
        }

        $server = new McpServer(new McpRegistry);
        $result = $server->handle((string) $request->getContent());

        $headers = [
            'Content-Type'        => 'application/json',
            'MCP-Protocol-Version'=> McpServer::PROTOCOL_VERSION,
            'Cache-Control'       => 'no-store',
        ];

        // JSON-RPC 通知：返回 202 空响应
        if ($result['empty']) {
            return response('', 202, $headers);
        }

        $payload = $result['payload'];

        // 客户端声明 SSE 时按 SSE 协议包装
        $accept = strtolower((string) $request->header('Accept', ''));
        if (str_contains($accept, 'text/event-stream')) {
            $data = 'event: message' . "\n" . 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

            return response($data, 200, array_merge($headers, [
                'Content-Type' => 'text/event-stream',
                'X-Accel-Buffering' => 'no',
            ]));
        }

        return response()->json($payload, 200, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
