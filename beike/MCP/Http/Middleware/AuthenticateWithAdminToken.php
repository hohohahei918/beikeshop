<?php

/**
 * AuthenticateWithAdminToken.php
 *
 * MCP 端点鉴权中间件：复用 admin_user_tokens 表签发的 admin token。
 * 客户端可通过以下任一方式携带 token：
 *   1. 请求头 `token: <token>`            （与 admin_api 中间件一致）
 *   2. 请求头 `Authorization: Bearer <token>` （HTTP 标准，TraeWork 等客户端默认用法）
 *   3. 查询参数 `?token=<token>`            （兜底，不推荐生产使用）
 */

namespace Beike\MCP\Http\Middleware;

use Beike\Models\AdminUser;
use Beike\Repositories\AdminUserTokenRepo;
use Illuminate\Http\Request;

class AuthenticateWithAdminToken
{
    public function handle(Request $request, \Closure $next)
    {
        $token = $this->extractToken($request);

        if ($token === '') {
            return $this->unauthorized('missing token');
        }

        $adminUserToken = AdminUserTokenRepo::getAdminUserTokenByToken($token);
        if (! $adminUserToken) {
            return $this->unauthorized('invalid token');
        }

        $adminUser = $adminUserToken->adminUser;
        if (! $adminUser instanceof AdminUser || ! $adminUser->active) {
            return $this->unauthorized('inactive admin user');
        }

        // 与 admin_api 保持一致：把管理员注入 registry，工具内部可用 registry('admin_user') 取到
        register('admin_user', $adminUser, true);

        return $next($request);
    }

    private function extractToken(Request $request): string
    {
        $token = (string) $request->header('token', '');
        if ($token !== '') {
            return $token;
        }

        $authorization = (string) $request->header('Authorization', '');
        if (stripos($authorization, 'Bearer ') === 0) {
            return trim(substr($authorization, 7));
        }

        $token = (string) $request->query('token', '');
        if ($token !== '') {
            return $token;
        }

        return '';
    }

    private function unauthorized(string $message)
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id'      => null,
            'error'   => [
                'code'    => -32001,
                'message' => 'Unauthorized: ' . $message,
            ],
        ], 401);
    }
}
