<?php

/**
 * McpRegistry.php
 *
 * MCP 工具注册表：集中定义所有工具的名称、描述、输入 Schema 与实现映射。
 * 工具输入/输出结构与仓库内 mcp-server/（Python stdio 版）保持一致，便于客户端迁移。
 *
 * @package Plugin\Mcp\Services
 */

namespace Plugin\Mcp\Services;

use InvalidArgumentException;
use Plugin\Mcp\Services\Tools\ImportTools;
use Plugin\Mcp\Services\Tools\PricingTools;
use Plugin\Mcp\Services\Tools\ProductTools;
use Plugin\Mcp\Services\Tools\SystemTools;
use Plugin\Mcp\Services\Tools\TranslateTools;

class McpRegistry
{
    protected array $definitions;

    public function __construct()
    {
        $this->definitions = [
            // ---------- 系统 ----------
            [
                'name'        => 'beikeshop_health',
                'description' => '检查 BeikeShop 商城与 MCP 服务运行状态（版本、数据库、工具数量等）。',
                'inputSchema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            ],

            // ---------- 商品查询 ----------
            [
                'name'        => 'search_products',
                'description' => '按关键词/分类/品牌/状态等条件搜索商品列表，返回 id、名称、默认 SKU、价格、上下架状态等。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'keyword'     => ['type' => 'string', 'description' => '搜索关键词（匹配 SKU/型号/名称）'],
                        'category_id' => ['type' => 'integer', 'description' => '按分类 ID 过滤'],
                        'brand_id'    => ['type' => 'integer', 'description' => '按品牌 ID 过滤'],
                        'active'      => ['type' => 'integer', 'enum' => [0, 1], 'description' => '上架状态：1=上架，0=下架'],
                        'sku'         => ['type' => 'string', 'description' => '按 SKU 精确模糊匹配'],
                        'price'       => ['type' => 'string', 'description' => '价格区间，如 "10-100"'],
                        'per_page'    => ['type' => 'integer', 'description' => '每页数量，默认 20，最大 100'],
                        'page'        => ['type' => 'integer', 'description' => '页码，默认 1'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'get_product_detail',
                'description' => '获取单个商品完整详情：全语言描述、SKU 列表（含成本价）、分类、品牌、图片。用于改价/翻译/上下架前的读取。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'integer', 'description' => '商品 ID'],
                    ],
                    'required'             => ['product_id'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'list_categories',
                'description' => '列出全部分类（含层级路径），返回分类 ID、名称、父级等信息。',
                'inputSchema' => [
                    'type'                 => 'object',
                    'properties'           => [],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'list_brands',
                'description' => '列出全部品牌，返回品牌 ID、名称、排序等信息。',
                'inputSchema' => [
                    'type'                 => 'object',
                    'properties'           => [],
                    'additionalProperties' => false,
                ],
            ],

            // ---------- 定价 / 汇率 ----------
            [
                'name'        => 'get_exchange_rate',
                'description' => '获取实时汇率并按需换算金额（基于 open.er-api.com 的 USD 基准汇率）。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'from'   => ['type' => 'string', 'description' => '源币种代码，如 USD/CNY/EUR，默认 USD'],
                        'to'     => ['type' => 'string', 'description' => '目标币种代码，如 CNY，默认 CNY'],
                        'amount' => ['type' => 'number', 'description' => '换算金额，默认 1'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'suggest_price',
                'description' => '根据成本价、币种与目标利润率建议售价，返回建议售价与换算明细。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'cost_price'     => ['type' => 'number', 'description' => '成本价（数字，不写币种符号）'],
                        'cost_currency'  => ['type' => 'string', 'description' => '成本价币种，如 CNY，默认 CNY'],
                        'sell_currency'  => ['type' => 'string', 'description' => '销售币种，默认 USD'],
                        'target_margin'  => ['type' => 'number', 'description' => '目标利润率（百分比，如 30 表示 30%），默认 30'],
                        'round'          => ['type' => 'integer', 'description' => '保留小数位，默认 2'],
                    ],
                    'required'             => ['cost_price'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'set_product_price',
                'description' => '直接修改商品指定 SKU 的售价/原价（支持按 SKU 编码或默认 SKU）。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'product_id'  => ['type' => 'integer', 'description' => '商品 ID'],
                        'sku'         => ['type' => 'string', 'description' => 'SKU 编码；不传则更新默认 SKU'],
                        'price'       => ['type' => 'number', 'description' => '新售价'],
                        'origin_price'=> ['type' => 'number', 'description' => '新原价（划线价，可选）'],
                    ],
                    'required'             => ['product_id', 'price'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'update_price_by_margin',
                'description' => '按目标利润率批量更新商品售价：售价 = 成本价 × (1 + 利润率)。可指定商品范围。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'margin'      => ['type' => 'number', 'description' => '利润率（百分比），如 30'],
                        'product_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => '指定商品 ID 列表；不传则更新全部有成本价的商品'],
                        'currency'    => ['type' => 'string', 'description' => '成本价币种用于汇率换算（可选，仅当成本价需换算时使用）'],
                    ],
                    'required'             => ['margin'],
                    'additionalProperties' => false,
                ],
            ],

            // ---------- 翻译 / 描述 ----------
            [
                'name'        => 'translate_text',
                'description' => '调用百度翻译对文本进行翻译（需在插件后台配置百度翻译 APP ID 与密钥）。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'text' => ['type' => 'string', 'description' => '待翻译文本'],
                        'from' => ['type' => 'string', 'description' => '源语言，如 zh/en/auto，默认 auto'],
                        'to'   => ['type' => 'string', 'description' => '目标语言，默认 zh'],
                    ],
                    'required'             => ['text'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'build_product_descriptions',
                'description' => '基于给定的商品标题/卖点/详情，为多个语言生成商品描述（名称/摘要/详情的多语言包），供 create_product / update_product 直接使用。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'name'           => ['type' => 'string', 'description' => '商品标题（源语言）'],
                        'summary'        => ['type' => 'string', 'description' => '商品卖点/摘要（可选）'],
                        'content'        => ['type' => 'string', 'description' => '商品详情 HTML 或文本（可选）'],
                        'source_locale'  => ['type' => 'string', 'description' => '源语言代码，默认 zh_cn'],
                        'target_locales' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '需要生成的语言列表，默认全部启用语言'],
                    ],
                    'required'             => ['name'],
                    'additionalProperties' => false,
                ],
            ],

            // ---------- 商品写入 ----------
            [
                'name'        => 'create_product',
                'description' => '创建商品。descriptions 为多语言描述包，skus 为 SKU 列表（须含 sku 编码），categories 为分类 ID 数组。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'skus'        => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'SKU 列表：[{sku, model?, price, origin_price?, cost_price?, quantity, is_default?}]'],
                        'descriptions'=> ['type' => 'object', 'description' => '多语言描述：{locale: {name, summary?, content?, meta_title?, meta_description?, meta_keywords?}}'],
                        'categories'  => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => '分类 ID 数组'],
                        'brand_id'    => ['type' => 'integer', 'description' => '品牌 ID，默认 0'],
                        'images'      => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '图片路径数组'],
                        'active'      => ['type' => 'integer', 'enum' => [0, 1], 'description' => '是否上架，默认 1'],
                        'position'    => ['type' => 'integer', 'description' => '排序，默认 0'],
                        'shipping'    => ['type' => 'boolean', 'description' => '是否配送，默认 true'],
                        'weight'      => ['type' => 'number', 'description' => '重量，默认 0'],
                        'variables'   => ['type' => 'string', 'description' => '多规格 JSON 字符串，默认 []'],
                        'attributes'  => ['type' => 'array', 'description' => '商品属性数组'],
                        'relations'   => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => '关联商品 ID'],
                    ],
                    'required'             => ['skus', 'descriptions'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'update_product',
                'description' => '全量更新商品（ProductService 为覆盖式）。传入需更新的字段即可，未传字段保留原值。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'product_id'  => ['type' => 'integer', 'description' => '商品 ID'],
                        'skus'        => ['type' => 'array', 'items' => ['type' => 'object']],
                        'descriptions'=> ['type' => 'object'],
                        'categories'  => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'brand_id'    => ['type' => 'integer'],
                        'images'      => ['type' => 'array', 'items' => ['type' => 'string']],
                        'active'      => ['type' => 'integer', 'enum' => [0, 1]],
                        'position'    => ['type' => 'integer'],
                        'shipping'    => ['type' => 'boolean'],
                        'weight'      => ['type' => 'number'],
                        'attributes'  => ['type' => 'array'],
                        'relations'   => ['type' => 'array', 'items' => ['type' => 'integer']],
                    ],
                    'required'             => ['product_id'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'set_product_active',
                'description' => '批量上架/下架商品。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'product_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => '商品 ID 列表'],
                        'active'      => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1=上架，0=下架'],
                    ],
                    'required'             => ['product_ids', 'active'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'delete_product',
                'description' => '删除商品（软删除，移入回收站）。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'product_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => '商品 ID 列表'],
                    ],
                    'required'             => ['product_ids'],
                    'additionalProperties' => false,
                ],
            ],

            // ---------- 资料搬运 ----------
            [
                'name'        => 'fetch_product_from_url',
                'description' => '从商品 URL 抓取标题、图片、价格、描述等基础信息，返回结构化草稿（不上架）。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'url' => ['type' => 'string', 'description' => '商品详情页 URL'],
                    ],
                    'required'             => ['url'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'import_product_from_url',
                'description' => '从商品 URL 抓取并按草稿创建为上架商品（可指定分类/品牌）。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'url'         => ['type' => 'string', 'description' => '商品详情页 URL'],
                        'category_ids'=> ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => '分类 ID 数组'],
                        'brand_id'    => ['type' => 'integer', 'description' => '品牌 ID 默认 0'],
                        'active'      => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1=直接上架（默认），0=仅草稿'],
                    ],
                    'required'             => ['url'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'import_products_from_csv',
                'description' => '从服务端 CSV 文件批量导入商品。列：name, sku, price, quantity, model, category_ids(分号分隔), brand_id, description 可选。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'file_path'  => ['type' => 'string', 'description' => 'CSV 文件绝对路径（服务端可访问）'],
                        'locale'     => ['type' => 'string', 'description' => '导入文本语言，默认 zh_cn'],
                        'active'     => ['type' => 'integer', 'enum' => [0, 1], 'description' => '是否上架，默认 1'],
                    ],
                    'required'             => ['file_path'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * 返回全部工具定义（用于 tools/list）
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * 调用指定工具（用于 tools/call）
     */
    public function call(string $name, array $args): mixed
    {
        $classMap = [
            'beikeshop_health'             => SystemTools::class,
            'search_products'              => ProductTools::class,
            'get_product_detail'           => ProductTools::class,
            'list_categories'              => ProductTools::class,
            'list_brands'                  => ProductTools::class,
            'get_exchange_rate'            => PricingTools::class,
            'suggest_price'                => PricingTools::class,
            'set_product_price'            => PricingTools::class,
            'update_price_by_margin'       => PricingTools::class,
            'translate_text'               => TranslateTools::class,
            'build_product_descriptions'   => TranslateTools::class,
            'create_product'               => ProductTools::class,
            'update_product'               => ProductTools::class,
            'set_product_active'           => ProductTools::class,
            'delete_product'               => ProductTools::class,
            'fetch_product_from_url'       => ImportTools::class,
            'import_product_from_url'      => ImportTools::class,
            'import_products_from_csv'     => ImportTools::class,
        ];

        if (! isset($classMap[$name])) {
            throw new InvalidArgumentException("Unknown tool: {$name}");
        }

        $method = static::methodMap()[$name] ?? $name;

        return call_user_func([$classMap[$name], $method], $args);
    }

    /**
     * 工具名到实现方法名的映射（PHP 方法名不能含下划线风格时使用）
     */
    public static function methodMap(): array
    {
        return [
            'beikeshop_health'           => 'health',
            'search_products'            => 'searchProducts',
            'get_product_detail'         => 'getProductDetail',
            'list_categories'            => 'listCategories',
            'list_brands'                => 'listBrands',
            'get_exchange_rate'          => 'exchangeRate',
            'suggest_price'              => 'suggestPrice',
            'set_product_price'          => 'setProductPrice',
            'update_price_by_margin'     => 'updatePriceByMargin',
            'translate_text'             => 'translateText',
            'build_product_descriptions' => 'buildProductDescriptions',
            'create_product'             => 'createProduct',
            'update_product'             => 'updateProduct',
            'set_product_active'         => 'setProductActive',
            'delete_product'             => 'deleteProduct',
            'fetch_product_from_url'     => 'fetchFromUrl',
            'import_product_from_url'    => 'importFromUrl',
            'import_products_from_csv'   => 'importFromCsv',
        ];
    }
}
