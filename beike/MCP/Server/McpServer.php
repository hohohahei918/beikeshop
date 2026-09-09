<?php

/**
 * McpServer.php
 *
 * BeikeShop 内置 MCP (Model Context Protocol) server。
 *
 * - 协议: JSON-RPC 2.0 over Streamable HTTP (单端点 POST /mcp)
 * - 协议版本: 2025-06-18
 * - 鉴权: 复用 admin_user_tokens 表签发的 admin token (见 AuthenticateWithAdminToken)
 * - 工具范围: products / orders / categories / brands 的增删改查
 *
 * 工具实现直接复用 Beike\Repositories\* 与 Beike\Admin\Services\*，
 * 与后台 admin_api 路由共享同一份业务逻辑，避免重复实现与状态漂移。
 */

namespace Beike\MCP\Server;

use Beike\Admin\Services\CategoryService;
use Beike\Admin\Services\ProductService;
use Beike\Models\AdminUser;
use Beike\Models\Brand;
use Beike\Models\Category;
use Beike\Models\Order;
use Beike\Models\OrderShipment;
use Beike\Models\Product;
use Beike\Repositories\BrandRepo;
use Beike\Repositories\CategoryRepo;
use Beike\Repositories\OrderRepo;
use Beike\Repositories\ProductRepo;
use Beike\Services\ShipmentService;
use Beike\Services\StateMachineService;
use Illuminate\Support\Facades\Log;

class McpServer
{
    public const PROTOCOL_VERSION = '2025-06-18';

    public const SERVER_NAME = 'beikeshop-mcp';

    public const SERVER_VERSION = '1.0.0';

    /**
     * 处理一个 (或一批) JSON-RPC 请求。
     *
     * @param array $payload  已经 json_decode 后的请求数据
     * @return array          JSON-RPC 响应 (单条) 或响应数组 (批量)
     */
    public function handleRequest(array $payload): array
    {
        // 批量请求
        if (array_is_list($payload)) {
            $responses = [];
            foreach ($payload as $request) {
                if (! is_array($request)) {
                    continue;
                }
                $response = $this->handleSingle($request);
                if ($response !== null) {
                    $responses[] = $response;
                }
            }

            return $responses;
        }

        // 单条请求
        $response = $this->handleSingle($payload);

        return $response ?? [];
    }

    /**
     * 处理单条 JSON-RPC 消息。
     *
     * @return array|null  返回响应数组；若为 notification (无 id) 则返回 null
     */
    private function handleSingle(array $request): ?array
    {
        $id = $request['id'] ?? null;

        // 基础 JSON-RPC 校验
        if (($request['jsonrpc'] ?? null) !== '2.0') {
            return $this->error($id, -32600, 'Invalid Request: jsonrpc must be "2.0"');
        }

        $method = (string) ($request['method'] ?? '');
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        try {
            $result = match ($method) {
                'initialize'     => $this->handleInitialize($params),
                'notifications/initialized',
                'notifications/cancelled' => null, // 通知: 不需要响应
                'tools/list'     => $this->handleToolsList(),
                'tools/call'     => $this->handleToolsCall($params),
                'ping'           => ['pong' => true],
                default          => throw new McpException(-32601, "Method not found: {$method}"),
            };
        } catch (McpException $e) {
            return $this->error($id, $e->getCode(), $e->getMessage(), $e->getData());
        } catch (\Throwable $e) {
            Log::error('MCP server error', [
                'method' => $method,
                'error'  => $e->getMessage(),
                'trace'  => $e->getTraceAsString(),
            ]);

            return $this->error($id, -32603, 'Internal error: ' . $e->getMessage());
        }

        // notification (无 id): 不返回响应
        if ($id === null) {
            return null;
        }

        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        ];
    }

    private function handleInitialize(array $params): array
    {
        // 协商协议版本: 若客户端请求的版本我们支持则回声，否则返回自己的最新版本
        $clientVersion = (string) ($params['protocolVersion'] ?? '');

        return [
            'protocolVersion' => $clientVersion ?: self::PROTOCOL_VERSION,
            'serverInfo'      => [
                'name'    => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
            'capabilities'    => [
                'tools'     => new \stdClass(),
                'logging'   => new \stdClass(),
            ],
            'instructions'    => 'BeikeShop MCP server. Tools cover product, order, category, brand management. Each tool is gated by the corresponding admin permission (e.g. products_index, orders_update_status).',
        ];
    }

    /**
     * tools/list: 返回所有工具定义。
     */
    private function handleToolsList(): array
    {
        $tools = [];
        foreach ($this->toolDefinitions() as $name => $def) {
            $tools[] = [
                'name'        => $name,
                'description' => $def['description'],
                'inputSchema' => $def['inputSchema'],
            ];
        }

        return ['tools' => $tools];
    }

    /**
     * tools/call: 调度到具体工具 handler。
     */
    private function handleToolsCall(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $definitions = $this->toolDefinitions();
        if (! isset($definitions[$name])) {
            throw new McpException(-32602, "Unknown tool: {$name}");
        }

        $def    = $definitions[$name];
        $admin  = $this->currentAdminUser();
        $perm   = $def['permission'] ?? null;

        // 复用 spatie Gate::before (root 用户自动放行) — 与 admin_api 中间件等价
        if ($perm !== null && $admin !== null && ! $admin->can($perm)) {
            throw new McpException(-32603, "Permission denied: requires {$perm}");
        }

        // 工具执行错误 (校验失败 / 资源不存在 / 业务异常) 按规范用 isError: true 返回,
        // 不再当作 JSON-RPC error, 让客户端 LLM 能拿到错误信息继续推理。
        try {
            $handler = $def['handler'];
            $data    = $handler($args, $admin);
            $payload = ['status' => 'success', 'data' => $data];
            $isError = false;
        } catch (McpException $e) {
            $payload = ['status' => 'fail', 'message' => $e->getMessage(), 'data' => $e->getData()];
            $isError = true;
        } catch (\Throwable $e) {
            Log::warning('MCP tool error', [
                'tool'  => $name,
                'error' => $e->getMessage(),
            ]);
            $payload = ['status' => 'fail', 'message' => $e->getMessage()];
            $isError = true;
        }

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode(
                        $payload,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                ],
            ],
            'isError' => $isError,
        ];
    }

    /**
     * 工具注册表。每条工具包含:
     *  - description: 工具描述 (LLM 会读这个决定何时调用)
     *  - permission: 对应 admin 端权限名 (与 admin_api 路由映射保持一致)
     *  - inputSchema: JSON Schema 描述入参
     *  - handler:    callable(array $args, ?AdminUser $admin): mixed
     */
    private function toolDefinitions(): array
    {
        return [

            // ===== Products =====
            'product_list' => [
                'description' => 'List products with filters. Returns a paginated list (per_page default 20). Useful for browsing the catalog.',
                'permission'  => 'products_index',
                'inputSchema'  => [
                    'type'       => 'object',
                    'properties' => [
                        'keyword'    => ['type' => 'string', 'description' => 'Search keyword (matches product name in current locale).'],
                        'category_id'=> ['type' => 'integer', 'description' => 'Filter by category id.'],
                        'brand_id'   => ['type' => 'integer', 'description' => 'Filter by brand id.'],
                        'active'     => ['type' => 'integer', 'description' => '1=active only, 0=inactive, omit=all.'],
                        'page'       => ['type' => 'integer', 'description' => 'Page number (1-based).'],
                        'per_page'   => ['type' => 'integer', 'description' => 'Items per page.'],
                        'sort'       => ['type' => 'string', 'description' => 'Sort column. Default: products.updated_at.'],
                        'order'      => ['type' => 'string', 'description' => 'asc|desc. Default: desc.'],
                    ],
                ],
                'handler' => function (array $args) {
                    if (! isset($args['sort'])) {
                        $args['sort'] = 'products.updated_at';
                    }
                    $paginator = ProductRepo::list($args);
                    $items     = $paginator->getCollection()->map(fn ($p) => $this->serializeProduct($p));

                    return $this->paginatorPayload($paginator, $items);
                },
            ],

            'product_get' => [
                'description' => 'Get full detail of a single product by id, including descriptions, skus, attributes, brand and relations.',
                'permission'  => 'products_show',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'Product id.'],
                    ],
                    'required' => ['id'],
                ],
                'handler' => function (array $args) {
                    $product = Product::query()->findOrFail((int) $args['id']);

                    return $this->serializeProduct(ProductRepo::getProductDetail($product));
                },
            ],

            'product_create' => [
                'description' => 'Create a new product. Requires at least one SKU and one description (per locale). See input schema.',
                'permission'  => 'products_create',
                'inputSchema'  => [
                    'type'       => 'object',
                    'properties' => [
                        'descriptions' => [
                            'type'        => 'object',
                            'description' => 'Map of locale => {name (required, 3-255 chars), content, meta_title, meta_keywords, meta_description}. Example: {"zh_cn": {"name": "T-shirt"}, "en": {"name": "T-shirt"}}',
                            'additionalProperties' => true,
                        ],
                        'skus' => [
                            'type'  => 'array',
                            'items'=> [
                                'type'       => 'object',
                                'properties' => [
                                    'sku'          => ['type' => 'string'],
                                    'price'        => ['type' => 'number'],
                                    'cost_price'   => ['type' => 'number'],
                                    'origin_price' => ['type' => 'number'],
                                    'quantity'     => ['type' => 'integer'],
                                    'image'        => ['type' => 'string'],
                                    'is_default'   => ['type' => 'boolean'],
                                ],
                                'required' => ['sku', 'price'],
                            ],
                            'minItems' => 1,
                        ],
                        'brand_id'    => ['type' => 'integer'],
                        'categories'  => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'attributes'  => ['type' => 'array', 'items' => ['type' => 'object']],
                        'relations'   => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'images'      => ['type' => 'array', 'items' => ['type' => 'string']],
                        'variables'   => ['type' => 'string', 'description' => 'JSON-encoded variant attribute map.'],
                        'weight'      => ['type' => 'number'],
                        'position'    => ['type' => 'integer'],
                        'shipping'    => ['type' => 'boolean'],
                        'video'       => ['type' => 'string'],
                    ],
                    'required' => ['descriptions', 'skus'],
                ],
                'handler' => function (array $args) {
                    $product = (new ProductService)->create($args);

                    return $this->serializeProduct(ProductRepo::getProductDetail($product));
                },
            ],

            'product_update' => [
                'description' => 'Update an existing product. Same payload shape as product_create. Existing skus/descriptions/attributes are replaced (PUT semantics).',
                'permission'  => 'products_update',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'           => ['type' => 'integer'],
                        'descriptions' => ['type' => 'object'],
                        'skus'         => ['type' => 'array'],
                        'brand_id'     => ['type' => 'integer'],
                        'categories'   => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'attributes'   => ['type' => 'array'],
                        'relations'    => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'images'       => ['type' => 'array', 'items' => ['type' => 'string']],
                        'variables'    => ['type' => 'string'],
                        'weight'       => ['type' => 'number'],
                        'position'     => ['type' => 'integer'],
                        'shipping'     => ['type' => 'boolean'],
                        'video'        => ['type' => 'string'],
                    ],
                    'required' => ['id', 'descriptions', 'skus'],
                ],
                'handler' => function (array $args) {
                    $id      = (int) $args['id'];
                    $product = Product::query()->findOrFail($id);
                    unset($args['id']);
                    $product = (new ProductService)->update($product, $args);

                    return $this->serializeProduct(ProductRepo::getProductDetail($product));
                },
            ],

            'product_delete' => [
                'description' => 'Delete a product by id (soft delete via Eloquent).',
                'permission'  => 'products_delete',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required'   => ['id'],
                ],
                'handler' => function (array $args) {
                    $product = Product::query()->findOrFail((int) $args['id']);
                    $product->delete();

                    return ['deleted' => true, 'id' => $product->id];
                },
            ],

            // ===== Orders =====
            'order_list' => [
                'description' => 'List orders with filters. Returns paginated orders sorted by created_at desc.',
                'permission'  => 'orders_index',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'      => ['type' => 'string', 'description' => 'created|unpaid|paid|shipped|completed|cancelled|refunding'],
                        'keyword'     => ['type' => 'string', 'description' => 'Match against order number / customer email / customer name.'],
                        'start_date'  => ['type' => 'string', 'description' => 'ISO date (Y-m-d). Inclusive lower bound on created_at.'],
                        'end_date'    => ['type' => 'string', 'description' => 'ISO date (Y-m-d). Inclusive upper bound on created_at.'],
                        'page'        => ['type' => 'integer'],
                        'per_page'    => ['type' => 'integer'],
                    ],
                ],
                'handler' => function (array $args) {
                    $paginator = OrderRepo::filterOrders($args);
                    $items     = $paginator->getCollection()->map(fn ($o) => $this->serializeOrder($o));

                    return $this->paginatorPayload($paginator, $items);
                },
            ],

            'order_get' => [
                'description' => 'Get full detail of one order: totals, histories, shipments, and the list of next backend statuses the order is allowed to transition to.',
                'permission'  => 'orders_show',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required'   => ['id'],
                ],
                'handler' => function (array $args) {
                    $order = Order::query()->findOrFail((int) $args['id']);
                    $order->load(['orderTotals', 'orderHistories', 'orderShipments']);

                    $data = $this->serializeOrder($order);
                    $data['next_statuses'] = StateMachineService::getInstance($order)->nextBackendStatuses();

                    return $data;
                },
            ],

            'order_update_status' => [
                'description' => 'Transition an order to a new status via the state machine. Status must be one of next_statuses returned by order_get. Optional express info triggers shipment creation when transitioning to "shipped".',
                'permission'  => 'orders_update_status',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'           => ['type' => 'integer', 'description' => 'Order id.'],
                        'status'       => ['type' => 'string', 'description' => 'Target status. Must be reachable from the current status.'],
                        'comment'      => ['type' => 'string', 'description' => 'Internal history comment.'],
                        'notify'       => ['type' => 'boolean', 'description' => 'Whether to send a customer notification. Default true.'],
                        'express_code' => ['type' => 'string', 'description' => 'Carrier code (e.g. sf-express). Required when status=shipped.'],
                        'express_number' => ['type' => 'string', 'description' => 'Tracking number. Required when status=shipped.'],
                    ],
                    'required' => ['id', 'status'],
                ],
                'handler' => function (array $args) {
                    $order = Order::query()->findOrFail((int) $args['id']);

                    $shipment = ShipmentService::handleShipment($args['express_code'] ?? null, $args['express_number'] ?? null);

                    $sm = new StateMachineService($order);
                    $sm->setShipment($shipment)
                        ->changeStatus(
                            $args['status'],
                            (string) ($args['comment'] ?? ''),
                            (bool) ($args['notify'] ?? true)
                        );

                    $order->refresh();

                    return [
                        'updated'        => true,
                        'id'             => $order->id,
                        'current_status' => $order->status,
                        'next_statuses'  => StateMachineService::getInstance($order)->nextBackendStatuses(),
                    ];
                },
            ],

            'order_update_shipment' => [
                'description' => 'Update an existing shipment record (carrier/tracking) for an order.',
                'permission'  => 'orders_update_status',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'order_id'          => ['type' => 'integer'],
                        'shipment_id'       => ['type' => 'integer'],
                        'express_code'      => ['type' => 'string'],
                        'express_number'    => ['type' => 'string'],
                        'express_company'   => ['type' => 'string'],
                    ],
                    'required' => ['order_id', 'shipment_id'],
                ],
                'handler' => function (array $args) {
                    $order = Order::query()->findOrFail((int) $args['order_id']);
                    $shipment = OrderShipment::query()
                        ->where('order_id', $order->id)
                        ->findOrFail((int) $args['shipment_id']);

                    ShipmentService::updateShipment($shipment, $args);

                    return $shipment->fresh();
                },
            ],

            // ===== Categories =====
            'category_list' => [
                'description' => 'List all product categories as a tree (root categories with nested children), each with descriptions in the current locale.',
                'permission'  => 'categories_index',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [],
                ],
                'handler' => function () {
                    $categories = CategoryRepo::getAdminList();

                    return $categories->map(fn ($c) => $this->serializeCategory($c));
                },
            ],

            'category_get' => [
                'description' => 'Get one category with its description in the current locale.',
                'permission'  => 'categories_show',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required'   => ['id'],
                ],
                'handler' => function (array $args) {
                    $category = Category::query()->findOrFail((int) $args['id']);
                    $category->load('description');

                    return $this->serializeCategory($category);
                },
            ],

            'category_create' => [
                'description' => 'Create a new category. descriptions is a map of locale => {name (required), content, meta_*}. parent_id=0 means a root category.',
                'permission'  => 'categories_create',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'parent_id'     => ['type' => 'integer', 'description' => '0 for root.'],
                        'position'      => ['type' => 'integer'],
                        'active'        => ['type' => 'boolean'],
                        'image'         => ['type' => 'string'],
                        'descriptions'  => [
                            'type' => 'object',
                            'description' => 'Map of locale => {name (required), content, meta_title, meta_keywords, meta_description}.',
                            'additionalProperties' => true,
                        ],
                    ],
                    'required' => ['descriptions'],
                ],
                'handler' => function (array $args) {
                    $category = (new CategoryService)->createOrUpdate($args, null);

                    return $this->serializeCategory($category->fresh('description'));
                },
            ],

            'category_update' => [
                'description' => 'Update an existing category (PUT semantics for descriptions).',
                'permission'  => 'categories_update',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'           => ['type' => 'integer'],
                        'parent_id'    => ['type' => 'integer'],
                        'position'     => ['type' => 'integer'],
                        'active'       => ['type' => 'boolean'],
                        'image'        => ['type' => 'string'],
                        'descriptions' => ['type' => 'object'],
                    ],
                    'required' => ['id', 'descriptions'],
                ],
                'handler' => function (array $args) {
                    $category = Category::query()->findOrFail((int) $args['id']);
                    (new CategoryService)->createOrUpdate($args, $category);

                    return $this->serializeCategory($category->fresh('description'));
                },
            ],

            'category_delete' => [
                'description' => 'Delete a category and all its descendants (recursive).',
                'permission'  => 'categories_delete',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required'   => ['id'],
                ],
                'handler' => function (array $args) {
                    $category = Category::query()->findOrFail((int) $args['id']);
                    CategoryRepo::delete($category);

                    return ['deleted' => true, 'id' => (int) $args['id']];
                },
            ],

            // ===== Brands =====
            'brand_list' => [
                'description' => 'List brands with optional filters. Returns paginated results sorted by created_at desc.',
                'permission'  => 'brands_index',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'name'   => ['type' => 'string', 'description' => 'Substring match on brand name.'],
                        'first'  => ['type' => 'string', 'description' => 'First letter filter.'],
                        'active' => ['type' => 'integer', 'description' => '1=active, 0=inactive.'],
                    ],
                ],
                'handler' => function (array $args) {
                    $paginator = BrandRepo::list($args);
                    $items     = $paginator->getCollection()->map(fn ($b) => $this->serializeBrand($b));

                    return $this->paginatorPayload($paginator, $items);
                },
            ],

            'brand_get' => [
                'description' => 'Get one brand by id.',
                'permission'  => 'brands_show',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required'   => ['id'],
                ],
                'handler' => function (array $args) {
                    $brand = Brand::query()->findOrFail((int) $args['id']);

                    return $this->serializeBrand($brand);
                },
            ],

            'brand_create' => [
                'description' => 'Create a new brand.',
                'permission'  => 'brands_create',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'name'       => ['type' => 'string'],
                        'first'      => ['type' => 'string', 'description' => 'First letter (used for grouped A-Z listing).'],
                        'logo'       => ['type' => 'string'],
                        'sort_order' => ['type' => 'integer'],
                        'active'     => ['type' => 'boolean'],
                    ],
                    'required' => ['name'],
                ],
                'handler' => function (array $args) {
                    $brand = BrandRepo::create($args);

                    return $this->serializeBrand($brand->fresh());
                },
            ],

            'brand_update' => [
                'description' => 'Update an existing brand.',
                'permission'  => 'brands_update',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'         => ['type' => 'integer'],
                        'name'       => ['type' => 'string'],
                        'first'      => ['type' => 'string'],
                        'logo'       => ['type' => 'string'],
                        'sort_order' => ['type' => 'integer'],
                        'active'     => ['type' => 'boolean'],
                    ],
                    'required' => ['id', 'name'],
                ],
                'handler' => function (array $args) {
                    $brand = Brand::query()->findOrFail((int) $args['id']);
                    BrandRepo::update($brand, $args);

                    return $this->serializeBrand($brand->fresh());
                },
            ],

            'brand_delete' => [
                'description' => 'Delete a brand by id.',
                'permission'  => 'brands_delete',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required'   => ['id'],
                ],
                'handler' => function (array $args) {
                    $brand = Brand::query()->findOrFail((int) $args['id']);
                    BrandRepo::delete($brand);

                    return ['deleted' => true, 'id' => (int) $args['id']];
                },
            ],
        ];
    }

    // ----------------------------------------------------------------------
    // Helpers: serialization, pagination, error envelope, current admin
    // ----------------------------------------------------------------------

    private function currentAdminUser(): ?AdminUser
    {
        $admin = registry('admin_user');

        return $admin instanceof AdminUser ? $admin : null;
    }

    /**
     * 把 LengthAwarePaginator 拍平成 MCP 友好的结构。
     */
    private function paginatorPayload($paginator, $items): array
    {
        return [
            'items'         => $items,
            'total'         => $paginator->total(),
            'per_page'      => $paginator->perPage(),
            'current_page'  => $paginator->currentPage(),
            'last_page'     => $paginator->lastPage(),
            'from'          => $paginator->firstItem(),
            'to'            => $paginator->lastItem(),
            'has_more_pages' => $paginator->hasMorePages(),
        ];
    }

    private function serializeProduct($product): array
    {
        if ($product === null) {
            return [];
        }

        $skus = $product->relationLoaded('skus') ? $product->skus : $product->skus()->get();

        $descriptions = [];
        foreach (($product->descriptions ?: []) as $desc) {
            $descriptions[$desc->locale] = [
                'name'             => $desc->name,
                'content'          => $desc->content,
                'meta_title'       => $desc->meta_title,
                'meta_description' => $desc->meta_description,
                'meta_keywords'    => $desc->meta_keywords,
            ];
        }

        return [
            'id'          => $product->id,
            'brand_id'    => $product->brand_id,
            'active'      => (bool) $product->active,
            'position'    => $product->position,
            'weight'      => (float) $product->weight,
            'shipping'    => (bool) $product->shipping,
            'video'       => $product->video,
            'images'      => $product->images ?? [],
            'categories'  => $product->relationLoaded('categories')
                ? $product->categories->pluck('id')->all()
                : $product->categories()->pluck('categories.id')->all(),
            'relations'   => $product->relationLoaded('relations')
                ? $product->relations->pluck('id')->all()
                : $product->relations()->pluck('products.id')->all(),
            'descriptions'=> $descriptions,
            'skus'        => $skus->map(fn ($sku) => [
                'id'           => $sku->id,
                'sku'          => $sku->sku,
                'price'        => (float) $sku->price,
                'cost_price'   => (float) $sku->cost_price,
                'origin_price' => (float) $sku->origin_price,
                'quantity'     => (int) $sku->quantity,
                'image'        => $sku->image,
                'is_default'   => (bool) $sku->is_default,
                'position'     => $sku->position,
            ])->all(),
            'created_at'  => optional($product->created_at)->toDateTimeString(),
            'updated_at'  => optional($product->updated_at)->toDateTimeString(),
        ];
    }

    private function serializeOrder($order): array
    {
        if ($order === null) {
            return [];
        }

        return [
            'id'             => $order->id,
            'number'         => $order->number,
            'status'         => $order->status,
            'total'          => $order->total,
            'customer_name'  => $order->customer_name,
            'customer_email' => $order->email,
            'customer_telephone' => $order->telephone,
            'payment_method' => $order->payment_method,
            'shipping_method'=> $order->shipping_method,
            'comment'        => $order->comment,
            'ip'             => $order->ip,
            'created_at'     => optional($order->created_at)->toDateTimeString(),
            'updated_at'     => optional($order->updated_at)->toDateTimeString(),
            'totals'         => $order->relationLoaded('orderTotals')
                ? $order->orderTotals->map(fn ($t) => ['code' => $t->code, 'title' => $t->title, 'value' => (float) $t->value])->all()
                : [],
            'histories'      => $order->relationLoaded('orderHistories')
                ? $order->orderHistories->map(fn ($h) => ['status' => $h->status, 'comment' => $h->comment, 'notify' => (bool) $h->notify, 'created_at' => optional($h->created_at)->toDateTimeString()])->all()
                : [],
            'shipments'      => $order->relationLoaded('orderShipments')
                ? $order->orderShipments->map(fn ($s) => ['id' => $s->id, 'express_code' => $s->express_code, 'express_number' => $s->express_number, 'express_company' => $s->express_company])->all()
                : [],
        ];
    }

    private function serializeCategory($category): array
    {
        if ($category === null) {
            return [];
        }

        $data = [
            'id'         => $category->id,
            'parent_id'  => $category->parent_id,
            'position'   => $category->position,
            'active'     => (bool) $category->active,
            'image'      => $category->image,
            'name'       => optional($category->description)->name,
            'content'    => optional($category->description)->content,
        ];

        if ($category->relationLoaded('children')) {
            $data['children'] = $category->children->map(fn ($c) => $this->serializeCategory($c))->all();
        }

        return $data;
    }

    private function serializeBrand($brand): array
    {
        if ($brand === null) {
            return [];
        }

        return [
            'id'         => $brand->id,
            'name'       => $brand->name,
            'first'      => $brand->first,
            'logo'       => $brand->logo,
            'sort_order' => $brand->sort_order,
            'active'     => (bool) $brand->active,
            'created_at' => optional($brand->created_at)->toDateTimeString(),
            'updated_at' => optional($brand->updated_at)->toDateTimeString(),
        ];
    }

    private function error(int|string|null $id, int $code, string $message, $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }

        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => $error,
        ];
    }
}
