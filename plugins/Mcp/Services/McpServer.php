<?php

/**
 * McpServer.php
 *
 * 最小可靠的 MCP（Model Context Protocol, streamable HTTP transport）服务端实现。
 * 规范参考：https://modelcontextprotocol.io/specification/2025-03-26
 *
 * 支持：initialize / ping / tools/list / tools/call / resources/list / prompts/list，
 * 以及 JSON-RPC 2.0 通知（返回空 202）。
 * 鉴权由 Middleware\McpAuth 完成。
 *
 * @package Plugin\Mcp\Services
 */

namespace Plugin\Mcp\Services;

use Throwable;
use InvalidArgumentException;

class McpServer
{
    public const PROTOCOL_VERSION = '2025-03-26';

    public const SERVER_NAME    = 'beikeshop-mcp';
    public const SERVER_VERSION = '1.0.0';

    protected McpRegistry $registry;

    public function __construct(McpRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * 处理一段 JSON-RPC 请求体
     *
     * @param string $raw 请求体原文
     * @return array{empty: bool, payload: ?array} empty=true 表示应返回 202 空响应（通知）
     */
    public function handle(string $raw): array
    {
        if (trim($raw) === '') {
            return ['empty' => false, 'payload' => $this->error(null, -32700, 'Parse error: empty request')];
        }

        $request = json_decode($raw, true);
        if (! is_array($request) || ($request['jsonrpc'] ?? '') !== '2.0') {
            return ['empty' => false, 'payload' => $this->error(null, -32600, 'Invalid Request')];
        }

        $method   = (string) ($request['method'] ?? '');
        $id       = $request['id'] ?? null;
        $hasId    = array_key_exists('id', $request);
        $params   = $request['params'] ?? [];

        // JSON-RPC 通知（无 id）：不做处理，返回空响应
        if (! $hasId) {
            return ['empty' => true, 'payload' => null];
        }

        if ($method === '') {
            return ['empty' => false, 'payload' => $this->error($id, -32600, 'Invalid Request: missing method')];
        }

        try {
            return ['empty' => false, 'payload' => $this->route($id, $method, $params)];
        } catch (Throwable $e) {
            return ['empty' => false, 'payload' => $this->error($id, -32603, 'Internal error: ' . $e->getMessage())];
        }
    }

    protected function route(mixed $id, string $method, mixed $params): array
    {
        switch ($method) {
            case 'initialize':
                return $this->result($id, [
                    'protocolVersion' => self::PROTOCOL_VERSION,
                    'capabilities'    => [
                        'tools' => ['listChanged' => false],
                    ],
                    'serverInfo'      => [
                        'name'    => self::SERVER_NAME,
                        'version' => self::SERVER_VERSION,
                    ],
                    'instructions'    => 'BeikeShop MCP Server. Use tools/list and tools/call to manage the store.',
                ]);

            case 'ping':
                return $this->result($id, []);

            case 'tools/list':
                return $this->result($id, ['tools' => $this->registry->definitions()]);

            case 'tools/call':
                return $this->callTool($id, $params);

            case 'resources/list':
                return $this->result($id, ['resources' => []]);

            case 'prompts/list':
                return $this->result($id, ['prompts' => []]);

            case 'logging/setLevel':
                return $this->result($id, []);

            default:
                return $this->error($id, -32601, sprintf('Method not found: %s', $method));
        }
    }

    protected function callTool(mixed $id, mixed $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $args = (is_array($params) && isset($params['arguments']) && is_array($params['arguments']))
            ? $params['arguments']
            : [];

        if ($name === '') {
            return $this->error($id, -32602, 'Invalid params: tool name is required');
        }

        try {
            $result = $this->registry->call($name, $args);

            return $this->result($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => static::encode($result),
                    ],
                ],
                'isError' => false,
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->error($id, -32602, $e->getMessage());
        } catch (Throwable $e) {
            // 工具执行失败：返回 isError 结果而非协议级错误，便于客户端拿到可读信息
            return $this->result($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'ERROR: ' . $e->getMessage(),
                    ],
                ],
                'isError' => true,
            ]);
        }
    }

    public static function encode(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: (string) $value;
    }

    protected function result(mixed $id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    protected function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
