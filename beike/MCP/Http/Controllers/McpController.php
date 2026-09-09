<?php

/**
 * McpController.php
 *
 * MCP Streamable HTTP 单端点: POST /mcp
 *
 * - 接收 JSON-RPC 2.0 请求体 (单条或批量)
 * - 委托给 McpServer 处理
 * - 始终以 application/json 返回 (本实现无流式需求, 不需要 SSE 升级)
 * - 405 / 400 给出标准 JSON-RPC error
 */

namespace Beike\MCP\Http\Controllers;

use Beike\MCP\Server\McpServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class McpController extends Controller
{
    public function __construct(private readonly McpServer $server)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (! $request->isMethod('post')) {
            return $this->jsonRpcError(null, -32600, 'Invalid Request: POST required', 405);
        }

        $payload = $request->json()->all();

        // 空 body 或非对象/非数组
        if (empty($payload)) {
            return $this->jsonRpcError(null, -32700, 'Parse error: empty or invalid JSON body');
        }

        // 必须是对象或数组 (JSON-RPC 顶层)
        if (! is_array($payload)) {
            return $this->jsonRpcError(null, -32700, 'Parse error: top-level JSON must be object or array');
        }

        $response = $this->server->handleRequest($payload);

        // 全部是 notification 时返回 202 (无 body 内容)
        if (empty($response)) {
            return response()->json(null, 202);
        }

        // 单条: 返回对象; 批量: 返回数组
        $isBatch = array_is_list($payload) && count($payload) > 1;

        return response()->json(
            $isBatch ? $response : $response[0] ?? $response,
            200,
            [
                'Content-Type'  => 'application/json',
                // Streamable HTTP spec: 回显协议版本, 方便客户端协商
                'MCP-Protocol-Version' => McpServer::PROTOCOL_VERSION,
            ]
        );
    }

    private function jsonRpcError(int|string|null $id, int $code, string $message, int $status = 400): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => ['code' => $code, 'message' => $message],
        ], $status, [
            'Content-Type'          => 'application/json',
            'MCP-Protocol-Version'  => McpServer::PROTOCOL_VERSION,
        ]);
    }
}
