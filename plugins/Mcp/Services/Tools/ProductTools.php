<?php

/**
 * ProductTools.php
 *
 * 商品相关 MCP 工具实现：搜索、详情、分类、品牌、创建、更新、上下架、删除。
 *
 * @package Plugin\Mcp\Services\Tools
 */

namespace Plugin\Mcp\Services\Tools;

use Beike\Admin\Services\ProductService;
use Beike\Models\Product;
use Beike\Models\ProductSku;
use Beike\Repositories\BrandRepo;
use Beike\Repositories\CategoryRepo;
use Beike\Repositories\ProductRepo;
use Illuminate\Support\Collection;

class ProductTools
{
    /**
     * 搜索商品列表
     */
    public static function searchProducts(array $args): array
    {
        $perPage = max(1, min(100, (int) ($args['per_page'] ?? 20)));
        $page    = max(1, (int) ($args['page'] ?? 1));

        $filters = [
            'keyword'     => $args['keyword'] ?? '',
            'category_id' => $args['category_id'] ?? '',
            'brand_id'    => $args['brand_id'] ?? '',
            'active'      => array_key_exists('active', $args) ? (int) $args['active'] : '',
            'sku'         => $args['sku'] ?? '',
            'price'       => $args['price'] ?? '',
            'sort'        => $args['sort'] ?? 'products.created_at',
            'order'       => $args['order'] ?? 'desc',
            'per_page'    => $perPage,
            'page'        => $page,
        ];

        $paginator = ProductRepo::list($filters);
        $items     = [];

        foreach ($paginator->items() as $product) {
            /** @var Product $product */
            $master = $product->masterSku ?: $product->skus->first();
            $items[] = [
                'id'           => (int) $product->id,
                'name'         => (string) ($product->name ?: $product->description?->name ?: ''),
                'sku'          => (string) ($master?->sku ?? ''),
                'model'        => (string) ($master?->model ?? ''),
                'price'        => (float) ($master?->price ?? 0),
                'origin_price' => (float) ($master?->origin_price ?? 0),
                'cost_price'   => (float) ($master?->cost_price ?? 0),
                'quantity'     => (int) ($master?->quantity ?? 0),
                'active'       => (int) $product->active,
                'brand_id'     => (int) ($product->brand_id ?? 0),
                'created_at'   => (string) ($product->created_at ?? ''),
                'url'          => url('product/' . $product->id),
            ];
        }

        return [
            'total'    => (int) $paginator->total(),
            'per_page' => (int) $paginator->perPage(),
            'page'     => (int) $paginator->currentPage(),
            'items'    => $items,
        ];
    }

    /**
     * 商品完整详情（多语言描述 + SKU + 分类 + 品牌）
     */
    public static function getProductDetail(array $args): array
    {
        $productId = (int) ($args['product_id'] ?? 0);
        if ($productId <= 0) {
            throw new \InvalidArgumentException('product_id is required');
        }

        $product = ProductRepo::getProductDetail($productId);
        $product->load(['descriptions', 'categories.description', 'skus']);

        $descriptions = [];
        foreach ($product->descriptions as $desc) {
            $descriptions[$desc->locale] = [
                'name'             => (string) $desc->name,
                'content'          => (string) $desc->content,
                'meta_title'       => (string) ($desc->meta_title ?? ''),
                'meta_description' => (string) ($desc->meta_description ?? ''),
                'meta_keywords'    => (string) ($desc->meta_keywords ?? ''),
            ];
        }

        $skus = [];
        foreach ($product->skus as $sku) {
            $skus[] = [
                'id'           => (int) $sku->id,
                'sku'          => (string) $sku->sku,
                'model'        => (string) ($sku->model ?? ''),
                'price'        => (float) $sku->price,
                'origin_price' => (float) ($sku->origin_price ?? 0),
                'cost_price'   => (float) ($sku->cost_price ?? 0),
                'quantity'     => (int) ($sku->quantity ?? 0),
                'is_default'   => (bool) ($sku->is_default ?? false),
                'variants'     => $sku->variants,
            ];
        }

        $categories = $product->categories->map(fn ($category) => [
            'id'   => (int) $category->id,
            'name' => (string) ($category->description?->name ?? ''),
        ])->values()->all();

        return [
            'id'          => (int) $product->id,
            'images'      => $product->images ?: [],
            'video'       => (string) ($product->video ?? ''),
            'active'      => (int) ($product->active ?? 1),
            'position'    => (int) ($product->position ?? 0),
            'brand_id'    => (int) ($product->brand_id ?? 0),
            'brand_name'  => (string) ($product->brand?->name ?? ''),
            'shipping'    => (bool) ($product->shipping ?? true),
            'weight'      => (float) ($product->weight ?? 0),
            'variables'   => $product->variables,
            'descriptions'=> $descriptions,
            'skus'        => $skus,
            'categories'  => $categories,
            'url'         => url('product/' . $product->id),
        ];
    }

    /**
     * 分类列表（含层级路径名）
     */
    public static function listCategories(array $args): array
    {
        $categories = CategoryRepo::flatten(locale());

        return ['items' => $categories->map(fn ($c) => [
            'id'        => (int) $c->id,
            'name'      => (string) $c->name,
            'parent_id' => (int) ($c->parent_id ?? 0),
            'position'  => (int) ($c->position ?? 0),
        ])->values()->all()];
    }

    /**
     * 品牌列表
     */
    public static function listBrands(array $args): array
    {
        $brands = BrandRepo::list(['per_page' => 1000]);

        return ['items' => collect($brands->items())->map(fn ($brand) => [
            'id'   => (int) $brand->id,
            'name' => (string) $brand->name,
        ])->values()->all()];
    }

    /**
     * 创建商品
     */
    public static function createProduct(array $args): array
    {
        if (empty($args['skus']) || ! is_array($args['skus'])) {
            throw new \InvalidArgumentException('skus is required and must be an array');
        }
        if (empty($args['descriptions']) || ! is_array($args['descriptions'])) {
            throw new \InvalidArgumentException('descriptions is required');
        }

        $data = self::normalizeWriteData($args);

        $product = (new ProductService)->create($data);

        return ['success' => true, 'product_id' => (int) $product->id];
    }

    /**
     * 全量更新商品（未传字段保留原值）
     */
    public static function updateProduct(array $args): array
    {
        $productId = (int) ($args['product_id'] ?? 0);
        if ($productId <= 0) {
            throw new \InvalidArgumentException('product_id is required');
        }

        $product = ProductRepo::getProductDetail($productId);
        $product->load(['descriptions', 'skus', 'categories']);

        $data = self::normalizeWriteData($args, $product);

        (new ProductService)->update($product, $data);

        return ['success' => true, 'product_id' => (int) $product->id];
    }

    /**
     * 批量上下架
     */
    public static function setProductActive(array $args): array
    {
        $ids    = array_values(array_filter(array_map('intval', (array) ($args['product_ids'] ?? []))));
        $active = (int) ($args['active'] ?? 1);

        if (! $ids) {
            throw new \InvalidArgumentException('product_ids is required');
        }

        $count = ProductRepo::updateStatusByIds($ids, $active);

        return ['success' => true, 'updated' => (int) ($count ?? count($ids)), 'active' => $active];
    }

    /**
     * 删除商品（软删除）
     */
    public static function deleteProduct(array $args): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($args['product_ids'] ?? []))));

        if (! $ids) {
            throw new \InvalidArgumentException('product_ids is required');
        }

        ProductRepo::DeleteByIds($ids);

        return ['success' => true, 'deleted' => count($ids)];
    }

    // ------------------------------------------------------------------
    // 内部辅助
    // ------------------------------------------------------------------

    /**
     * 将 MCP 入参规范化为 ProductService 需要的 data 结构。
     * update 模式下未提供的字段回填自现有商品，避免覆盖式更新丢失数据。
     */
    protected static function normalizeWriteData(array $args, ?Product $existing = null): array
    {
        $existingSkus = [];
        if ($existing) {
            foreach ($existing->skus as $sku) {
                $existingSkus[] = [
                    'sku'          => $sku->sku,
                    'model'        => $sku->model ?? '',
                    'price'        => (float) $sku->price,
                    'origin_price' => (float) ($sku->origin_price ?? 0),
                    'cost_price'   => (float) ($sku->cost_price ?? 0),
                    'quantity'     => (int) ($sku->quantity ?? 0),
                    'is_default'   => $sku->is_default ? 1 : 0,
                    'variants'     => $sku->variants ?: [],
                ];
            }
        }

        $existingDescriptions = [];
        if ($existing) {
            foreach ($existing->descriptions as $desc) {
                $existingDescriptions[$desc->locale] = [
                    'name'             => $desc->name,
                    'content'          => $desc->content ?? '',
                    'meta_title'       => $desc->meta_title ?? '',
                    'meta_description' => $desc->meta_description ?? '',
                    'meta_keywords'    => $desc->meta_keywords ?? '',
                ];
            }
        }

        $descriptions = [];
        $rawDesc      = array_key_exists('descriptions', $args) ? $args['descriptions'] : $existingDescriptions;
        foreach ($rawDesc as $locale => $desc) {
            $descriptions[$locale] = [
                'name'             => (string) ($desc['name'] ?? ''),
                'content'          => (string) ($desc['content'] ?? ''),
                'meta_title'       => (string) ($desc['meta_title'] ?? ''),
                'meta_description' => (string) ($desc['meta_description'] ?? ''),
                'meta_keywords'    => (string) ($desc['meta_keywords'] ?? ''),
            ];
        }

        $skus = [];
        $rawSku = array_key_exists('skus', $args) ? $args['skus'] : $existingSkus;
        foreach ($rawSku as $sku) {
            $skus[] = [
                'sku'          => (string) ($sku['sku'] ?? ''),
                'model'        => (string) ($sku['model'] ?? ''),
                'price'        => (float) ($sku['price'] ?? 0),
                'origin_price' => (float) ($sku['origin_price'] ?? 0),
                'cost_price'   => (float) ($sku['cost_price'] ?? 0),
                'quantity'     => (int) ($sku['quantity'] ?? 0),
                'is_default'   => (int) ($sku['is_default'] ?? 0),
                'variants'     => $sku['variants'] ?? [],
            ];
        }

        $data = [
            'skus'         => $skus,
            'descriptions' => $descriptions,
            'categories'   => array_values(array_filter(array_map('intval', (array) ($args['categories'] ?? ($existing ? $existing->categories->pluck('id')->toArray() : []))))),
            'brand_id'     => (int) ($args['brand_id'] ?? ($existing->brand_id ?? 0)),
            'images'       => $args['images'] ?? ($existing->images ?? []),
            'active'       => (int) ($args['active'] ?? ($existing->active ?? 1)),
            'position'     => (int) ($args['position'] ?? ($existing->position ?? 0)),
            'shipping'     => (bool) ($args['shipping'] ?? ($existing->shipping ?? true)),
            'weight'       => (float) ($args['weight'] ?? ($existing->weight ?? 0)),
            'variables'    => $args['variables'] ?? ($existing ? json_encode($existing->variables ?? []) : '[]'),
            'attributes'   => $args['attributes'] ?? [],
            'relations'    => array_values(array_filter(array_map('intval', (array) ($args['relations'] ?? [])))),
        ];

        return $data;
    }
}
