# BeikeShop MCP Server

为 BeikeShop（Laravel 开源跨境电商系统）提供 MCP（Model Context Protocol）服务，
让智能体客户端（如本机 Marvis）能直接管理商品找寻、资料搬运、翻译、定价、发布等日常工作。

## 环境要求

- Python 3.10+（本机已用 3.12 创建独立 venv）
- 一个可访问的 BeikeShop 实例（含 `/admin_api` 路由与管理员 Token）
- 百度翻译开放平台账号（用于翻译能力，`BAIDU_TRANSLATE_APPID` / `BAIDU_TRANSLATE_SECRET_KEY`）

## 目录结构

```
mcp-server/
├── run.py                    # 启动入口
├── .env.example              # 环境配置模板（复制为 .env 填写）
├── beikeshop_mcp/
│   ├── __init__.py           # 版本与能力域声明
│   ├── config.py             # 配置加载（环境变量 / .env）
│   ├── beike_client.py       # BeikeShop Admin REST API 客户端
│   ├── scraper.py            # 商品 URL 资料抓取（1688 / AliExpress / 通用）
│   ├── baidu_translate.py    # 百度翻译封装（自动切段）
│   ├── pricing.py            # 汇率获取 + 建议售价计算
│   └── server.py             # FastMCP 服务与全部工具
```

## 安装与运行

```bash
cd mcp-server
# 已有 .venv（Python 3.12）；如重建：
# uv venv --python 3.12 .venv && .venv/bin/pip install "mcp<2" requests beautifulsoup4 python-dotenv

cp .env.example .env   # 填写真实配置
.venv/bin/python run.py   # stdio 模式启动，供 MCP 客户端连接
```

客户端配置（Marvis / 其它 MCP 客户端）：

```json
{
  "mcpServers": {
    "beikeshop": {
      "command": "/path/to/mcp-server/.venv/bin/python",
      "args": ["/path/to/mcp-server/run.py"]
    }
  }
}
```

## 能力域与工具

| 能力域 | 工具 |
|---|---|
| 连接自检 | `beikeshop_health` |
| 商品找寻 | `search_products` / `get_product_detail` / `list_categories` / `list_brands` |
| 资料搬运 | `fetch_product_from_url` / `import_product_from_url` / `import_products_from_csv` |
| 翻译 | `translate_text` / `build_product_descriptions` / `translate_product` |
| 定价 | `get_exchange_rate` / `suggest_price` / `set_product_price` / `update_price_by_margin` |
| 发布 | `create_product` / `update_product` / `set_product_active` / `delete_product` |

## 配套的 BeikeShop 侧增强

本 MCP 依赖对 BeikeShop Admin API 的一处增强（已在
`beike/AdminAPI/Controllers/ProductController.php` 的 `show` 方法中完成）：

- 商品详情接口额外返回 `categories`（分类 ID）、`descriptions`（全语言描述）、
  `skus_detail`（含成本价的原始 SKU）。

原因：BeikeShop 的商品更新是**全量覆盖式**（重建描述 / SKU / 分类），而原详情接口
不返回分类与全部语言描述。若不增强，MCP 改价 / 上下架 / 翻译时会把分类清空并丢失
其它语言，属于损坏数据的高风险操作。增强后 MCP 可实现无损回写。

> 部署到云服务器时，记得把该文件的改动一并同步。

## 注意事项

- **删除商品** `delete_product` 不可恢复，请谨慎使用。
- **价格均为商城当前基准货币**；跨境定价建议先用 `suggest_price` 计算，
  再通过 `set_product_price` 或 `update_price_by_margin` 落地。
- 1688 / AliExpress 存在反爬风控，`fetch_product_from_url` 可能被拦截，
  失败时会返回明确提示（可改用浏览器方式抓取）。
- 商品图片需为服务器可访问的路径；外部 URL 是否可入库取决于商城配置。
