# BeikeShop MCP 插件（Plugin\Mcp）

为 BeikeShop 商城（Laravel 12, PHP 8.2）提供 **MCP（Model Context Protocol, streamable HTTP）服务**，
使 AI 智能体（如本机 Marvis / Claude Desktop / 其它 MCP 客户端）能够直接管理商城：

- 商品查询 / 详情
- 资料搬运（URL 抓取、CSV 批量导入）
- 翻译与多语言描述生成（百度翻译）
- 定价与汇率（实时汇率、按利润率改价、SKU 改价）
- 商品发布（创建 / 更新 / 上下架 / 删除）

## 特性

- **集成在主项目**：`plugins/Mcp/` 目录即随仓库分发，通过贝生成绍官方插件机制（`beike/Plugin`）自动发现，无需独立进程。
- **官方安装方式**：
  1. 将 `plugins/Mcp/` 放入主项目 `plugins/`（本分支已内置）；
  2. 后台 → 插件 → 本地插件 → 找到「MCP 服务 / AI 商城管理」→ **安装并启用**（`code: mcp` 已加入 `config/app.php` 的 `free_plugin_codes`，无需授权）。
- **后台查阅与管理**：后台 → 插件 → 「MCP 服务」编辑/设置页，提供：
  - 运行状态概览（版本、数据库、商品数、工具数、端点）；
  - 访问令牌查看 / 复制 / 重新生成；
  - 百度翻译 APP ID / 密钥配置保存；
  - 可用工具清单；
  - 连通性测试、curl 快速验证示例、客户端接入指引。

## 端点与鉴权

- 端点：`POST /mcp`（启用插件后自动注册，`name: mcp.endpoint`）
- 鉴权请求头：`Authorization: Bearer <token>`
- 令牌后台生成，存于 `plugin.mcp.token`（settings 表），可随时重新生成（旧令牌立即失效）。

## 可用工具（18 个）

| 工具名 | 说明 |
| --- | --- |
| `beikeshop_health` | 服务与商城健康检查 |
| `search_products` | 商品搜索（关键词/分类/品牌/状态/价格） |
| `get_product_detail` | 商品详情（多语言描述/SKU 含成本价/分类/品牌/图片） |
| `list_categories` | 分类列表（层级路径） |
| `list_brands` | 品牌列表 |
| `get_exchange_rate` | 实时汇率换算（open.er-api.com） |
| `suggest_price` | 按成本+利润率建议售价 |
| `set_product_price` | 修改指定 SKU 售价/原价 |
| `update_price_by_margin` | 按利润率批量改价 |
| `translate_text` | 百度翻译 |
| `build_product_descriptions` | 多语言商品描述包生成 |
| `create_product` | 创建商品 |
| `update_product` | 全量更新商品（未传字段保留原值） |
| `set_product_active` | 批量上下架 |
| `delete_product` | 删除商品（软删除） |
| `fetch_product_from_url` | URL 抓取商品草稿 |
| `import_product_from_url` | URL 抓取并发布商品 |
| `import_products_from_csv` | CSV 批量导入 |

> 说明：URL 语料为通用基础实现（og meta / title / 图片 / 描述）。1688 / AliExpress 等平台的规则化字段识别（尺码表、SKU 组合、规格图片等）建议在调用侧按平台二次确认或扩展规则。

## 客户端接入

### 示例（MCP HTTP 客户端配置）

```
类型: streamable HTTP
URL : https://<你的商城域名>/mcp
Headers: Authorization: Bearer <token>
```

### curl 快速验证

```bash
curl -X POST https://<域名>/mcp \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"curl","version":"1.0"}}}'
```

### Python 客户端（原独立 stdio mcp-server 兼容说明）

仓库 `mcp-server/` 曾作为独立 Python stdio 服务提供同名工具。本插件以 HTTP 形式内置同能力，
仅供 `stdio` 模式的客户端使用时可自行实现 HTTP↔stdio 桥接，或继续使用原 `mcp-server/`（需自行管理 PYTHONPATH 与密钥）。

## 部署注意

- 启用插件后请确认路由可达：`php artisan route:list | grep mcp`（或后台设置页“测试连接”）。
- 若启用 `php artisan route:cache`，插件路由会一并缓存；改完插件路由后需重新 `route:clear`。
- 生产环境请使用 HTTPS，并妥善保管访问令牌。
```
