<?php

/**
 * Bootstrap.php
 *
 * @copyright  2026 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     hohohahei918
 * @created    2026-09-09
 */

namespace Plugin\Mcp;

use Plugin\Mcp\Services\McpRegistry;
use Plugin\Mcp\Services\Tools\SystemTools;

class Bootstrap
{
    public function boot()
    {
        $this->adminSettingPage();
    }

    /**
     * 将 MCP 服务管理页渲染进「插件 -> 编辑/设置」页面
     */
    private function adminSettingPage(): void
    {
        add_hook_blade('admin.plugin.form', function ($callback, $output, $data) {
            $code = $data['plugin']->code ?? '';
            if ($code != 'mcp') {
                return $output;
            }

            $data['endpoint']    = url('/mcp');
            $data['token']       = (string) plugin_setting('mcp.token', '');
            $data['baiduAppid']  = (string) plugin_setting('mcp.baidu_appid', '');
            $data['baiduSecret'] = (string) plugin_setting('mcp.baidu_secret', '');
            $data['health']      = SystemTools::health([]);
            $data['tools']       = (new McpRegistry)->definitions();

            return view('Mcp::admin.index', $data)->render();
        }, 1);
    }
}
