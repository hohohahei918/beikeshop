<?php

/**
 * AdminMcpController.php
 *
 * 后台 MCP 管理动作：重新生成访问令牌、连通性测试。
 * （配置项 token / baidu_appid / baidu_secret 通过官方插件保存路由 plugins.update 存储）
 *
 * @package Plugin\Mcp\Controllers
 */

namespace Plugin\Mcp\Controllers;

use Beike\Admin\Http\Controllers\Controller;
use Beike\Repositories\SettingRepo;
use Plugin\Mcp\Services\McpRegistry;
use Plugin\Mcp\Services\McpServer;

class AdminMcpController extends Controller
{
    /**
     * 重新生成访问令牌（立即生效，旧令牌失效）
     */
    public function regenerateToken()
    {
        $token = bin2hex(random_bytes(32));
        SettingRepo::update('plugin', 'mcp', ['token' => $token]);

        return redirect()->back()->with('success', __('Mcp::common.settings_saved'));
    }

    /**
     * 测试 MCP 服务连通性（程序内调用 initialize）
     */
    public function testConnection()
    {
        try {
            $request = json_encode([
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'initialize',
                'params'  => [
                    'protocolVersion' => McpServer::PROTOCOL_VERSION,
                    'capabilities'    => [],
                    'clientInfo'      => ['name' => 'admin-test', 'version' => '1.0'],
                ],
            ]);

            $result = (new McpServer(new McpRegistry))->handle((string) $request);
            $name   = $result['payload']['result']['serverInfo']['name'] ?? 'unknown';

            return json_success(sprintf(__('Mcp::common.test_connection_ok'), $name));
        } catch (\Throwable $e) {
            return json_fail(sprintf(__('Mcp::common.test_connection_bad'), $e->getMessage()), [], 500);
        }
    }
}
