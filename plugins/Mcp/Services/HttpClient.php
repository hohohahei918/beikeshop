<?php

/**
 * HttpClient.php
 *
 * 轻量 HTTP 客户端封装（基于 cURL），用于汇率、翻译、URL 搬运等外部请求。
 *
 * @package Plugin\Mcp\Services
 */

namespace Plugin\Mcp\Services;

class HttpClient
{
    /**
     * GET 请求返回字符串
     */
    public static function get(string $url, int $timeout = 15): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'BeikeShop-MCP/1.0',
        ]);
        $body    = curl_exec($ch);
        $error   = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('HTTP request failed: ' . ($error ?: 'unknown error'));
        }

        return (string) $body;
    }

    /**
     * GET 请求并解析 JSON
     */
    public static function getJson(string $url, int $timeout = 15): array
    {
        $raw = self::get($url, $timeout);
        $json = json_decode($raw, true);

        if (! is_array($json)) {
            throw new \RuntimeException('Invalid JSON response from: ' . $url);
        }

        return $json;
    }
}
