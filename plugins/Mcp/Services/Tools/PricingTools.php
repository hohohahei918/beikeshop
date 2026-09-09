<?php

/**
 * PricingTools.php
 *
 * 定价/汇率类 MCP 工具实现：
 * - get_exchange_rate     实时汇率换算（open.er-api.com）
 * - suggest_price         按成本与利润率建议售价
 * - set_product_price     直接修改指定 SKU 售价
 * - update_price_by_margin 按利润率批量更新售价
 *
 * @package Plugin\Mcp\Services\Tools
 */

namespace Plugin\Mcp\Services\Tools;

use Beike\Models\Product;
use Beike\Models\ProductSku;
use Plugin\Mcp\Services\HttpClient;

class PricingTools
{
    private const RATES_API = 'https://open.er-api.com/v6/latest/USD';

    /**
     * 获取 USD 基准汇率表（带进程级缓存，避免工具高频调用打爆接口）
     */
    protected static function ratesMap(): array
    {
        static $map = null;
        static $ts  = 0;

        if (is_null($map) || (time() - $ts) > 3600) {
            $json = HttpClient::getJson(self::RATES_API);
            $map  = is_array($json['rates'] ?? null) ? $json['rates'] : [];
            $ts   = time();
        }

        if (! $map) {
            throw new \RuntimeException('Failed to fetch exchange rates');
        }

        return $map;
    }

    /**
     * 实时汇率换算
     */
    public static function exchangeRate(array $args): array
    {
        $from   = strtoupper((string) ($args['from'] ?? 'USD'));
        $to     = strtoupper((string) ($args['to'] ?? 'CNY'));
        $amount = (float) ($args['amount'] ?? 1);

        $rates = self::ratesMap();
        if (! isset($rates[$from]) || ! isset($rates[$to])) {
            throw new \InvalidArgumentException("Unsupported currency: {$from} / {$to}");
        }

        $rate      = $rates[$to] / $rates[$from];
        $converted = $amount * $rate;

        return [
            'from'        => $from,
            'to'          => $to,
            'amount'      => $amount,
            'rate'        => round($rate, 6),
            'converted'   => round($converted, 4),
            'base'        => 'USD',
            'source'      => 'open.er-api.com',
            'updated_at'  => date('c'),
        ];
    }

    /**
     * 建议售价 = 成本价换算到销售币种 × (1 + 利润率)
     */
    public static function suggestPrice(array $args): array
    {
        $cost       = (float) ($args['cost_price'] ?? 0);
        $costCur    = strtoupper((string) ($args['cost_currency'] ?? 'CNY'));
        $sellCur    = strtoupper((string) ($args['sell_currency'] ?? 'USD'));
        $margin     = (float) ($args['target_margin'] ?? 30);
        $round      = (int) ($args['round'] ?? 2);

        if ($cost <= 0) {
            throw new \InvalidArgumentException('cost_price must be greater than 0');
        }

        $rates = self::ratesMap();
        if (! isset($rates[$costCur]) || ! isset($rates[$sellCur])) {
            throw new \InvalidArgumentException("Unsupported currency: {$costCur} / {$sellCur}");
        }

        $rate        = $rates[$sellCur] / $rates[$costCur];
        $costInSell  = $cost * $rate;
        $suggested   = $costInSell * (1 + $margin / 100);

        return [
            'cost_price'      => $cost,
            'cost_currency'   => $costCur,
            'sell_currency'   => $sellCur,
            'target_margin'   => $margin,
            'exchange_rate'   => round($rate, 6),
            'suggested_price' => round($suggested, $round),
            'formula'         => 'suggested = cost × rate × (1 + margin%)',
        ];
    }

    /**
     * 修改指定商品 SKU 售价
     */
    public static function setProductPrice(array $args): array
    {
        $productId = (int) ($args['product_id'] ?? 0);
        $price     = (float) ($args['price'] ?? -1);
        $skuCode   = (string) ($args['sku'] ?? '');
        $origin    = array_key_exists('origin_price', $args) ? (float) $args['origin_price'] : null;

        if ($productId <= 0) {
            throw new \InvalidArgumentException('product_id is required');
        }
        if ($price < 0) {
            throw new \InvalidArgumentException('price is required');
        }

        $product = Product::query()->find($productId);
        if (! $product) {
            throw new \InvalidArgumentException("Product not found: {$productId}");
        }

        $sku = $skuCode !== ''
            ? ProductSku::query()->where('product_id', $productId)->where('sku', $skuCode)->first()
            : $product->masterSku;

        if (! $sku) {
            throw new \InvalidArgumentException("SKU not found: " . ($skuCode ?: 'default'));
        }

        $sku->price = $price;
        if (! is_null($origin)) {
            $sku->origin_price = $origin;
        }
        $sku->save();

        return [
            'success'       => true,
            'product_id'    => $productId,
            'sku'           => $sku->sku,
            'price'         => (float) $sku->price,
            'origin_price'  => (float) $sku->origin_price,
        ];
    }

    /**
     * 按利润率批量更新售价：price = cost × (1 + margin%)
     */
    public static function updatePriceByMargin(array $args): array
    {
        $margin   = (float) ($args['margin'] ?? 0);
        $currency = strtoupper((string) ($args['currency'] ?? ''));
        $ids      = array_values(array_filter(array_map('intval', (array) ($args['product_ids'] ?? []))));

        if ($margin < 0) {
            throw new \InvalidArgumentException('margin must be >= 0');
        }

        $rate = 1.0;
        if ($currency !== '' && $currency !== 'USD') {
            $rates = self::ratesMap();
            if (isset($rates[$currency])) {
                // 成本价换算回 USD（售价基准币种 = 后台基准货币）
                $rate = 1 / $rates[$currency];
            }
        }

        $query = ProductSku::query()->where('cost_price', '>', 0);
        if ($ids) {
            $query->whereIn('product_id', $ids);
        }

        $updated = 0;
        $skus    = $query->get();
        foreach ($skus as $sku) {
            $cost     = (float) $sku->cost_price * $rate;
            $newPrice = round($cost * (1 + $margin / 100), 2);
            $sku->price = $newPrice;
            $sku->save();
            $updated++;
        }

        return [
            'success'          => true,
            'margin'           => $margin,
            'updated'          => $updated,
            'currency_adjusted'=> $currency ?: null,
            'scope'            => $ids ? ['product_ids' => $ids] : 'all products with cost_price > 0',
        ];
    }
}
