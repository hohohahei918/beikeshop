<?php

/**
 * ProductController.php
 *
 * @copyright  2023 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2023-04-20 16:48:47
 * @modified   2023-04-20 16:48:47
 */

namespace Beike\AdminAPI\Controllers;

use Beike\Admin\Http\Requests\ProductRequest;
use Beike\Admin\Http\Resources\ProductResource;
use Beike\Admin\Services\ProductService;
use Beike\Models\Product;
use Beike\Repositories\ProductRepo;
use Beike\Shop\Http\Resources\ProductDetail;
use Illuminate\Http\Request;

class ProductController
{
    /**
     * 产品列表
     *
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        $requestData = $request->all();
        if (! isset($requestData['sort'])) {
            $requestData['sort'] = 'products.updated_at';
        }
        $productList = ProductRepo::list($requestData);
        $products    = ProductResource::collection($productList);

        return hook_filter('admin_api.product.index.data', $products);
    }

    /**
     * 产品列表
     *
     * @param Request $request
     * @param Product $product
     * @return mixed
     */
    public function show(Request $request, Product $product)
    {
        $relationIds = $product->relations->pluck('id')->toArray();
        $product     = ProductRepo::getProductDetail($product);

        $data = [
            'product'   => (new ProductDetail($product))->jsonSerialize(),
            'relations' => ProductRepo::getProductsByIds($relationIds)->jsonSerialize(),
        ];

        // MCP 增强：附加商品分类与全语言描述，供智能体 MCP 服务做无损回写（改价/上下架/翻译）
        $data['product']['categories']    = $product->categories()->pluck('category_id')->toArray();
        $data['product']['descriptions']  = $product->descriptions()
            ->get(['locale', 'name', 'summary', 'content', 'meta_title', 'meta_description', 'meta_keywords'])
            ->keyBy('locale');
        // MCP 增强：原始 SKU（含成本价），供定价/改价回写使用
        $data['product']['skus_detail']   = $product->skus()
            ->get(['id', 'sku', 'model', 'price', 'origin_price', 'cost_price', 'quantity', 'is_default'])
            ->keyBy('id');

        return hook_filter('admin_api.product.show.data', $data);
    }

    /**
     * 创建商品
     *
     * @param ProductRequest $request
     * @return mixed
     */
    public function store(ProductRequest $request)
    {
        try {
            $requestData = $request->all();
            $product     = (new ProductService)->create($requestData);

            $data = [
                'request_data' => $requestData,
                'product'      => $product,
            ];
            hook_action('admin_api.product.store.after', $data);

            return json_success(trans('common.created_success'));
        } catch (\Exception $e) {
            return json_fail($e->getMessage());
        }
    }

    /**
     * 编辑商品
     *
     * @param ProductRequest $request
     * @param Product        $product
     * @return mixed
     */
    public function update(ProductRequest $request, Product $product)
    {
        try {
            $requestData = $request->all();
            $product     = (new ProductService)->update($product, $requestData);

            $data = [
                'request_data' => $requestData,
                'product'      => $product,
            ];
            hook_action('admin_api.product.update.after', $data);

            return json_success(trans('common.updated_success'));
        } catch (\Exception $e) {
            return json_fail($e->getMessage());
        }
    }

    /**
     * 删除商品
     *
     * @param Request $request
     * @param Product $product
     * @return array
     */
    public function destroy(Request $request, Product $product)
    {
        $product->delete();
        hook_action('admin_api.product.destroy.after', $product);

        return json_success(trans('common.deleted_success'));
    }
}
