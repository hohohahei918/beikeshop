<?php

/**
 * McpException.php
 *
 * MCP server 内部异常: 携带 JSON-RPC error code 与可选 data。
 */

namespace Beike\MCP\Server;

class McpException extends \Exception
{
    private mixed $data;

    public function __construct(int $code, string $message, mixed $data = null)
    {
        parent::__construct($message, $code);
        $this->data = $data;
    }

    public function getData(): mixed
    {
        return $this->data;
    }
}
