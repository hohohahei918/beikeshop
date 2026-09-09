<?php

/**
 * ImportTools.php
 *
 * 资料搬运类 MCP 工具实现：
 * - fetch_product_from_url   从商品 URL 抓取草稿
 * - import_product_from_url  抓取并创建商品
 * - import_products_from_csv 从 CSV 批量导入
 *
 * 说明：URL 抓取为通用基础实现（og meta / title / 图片 / 描述），
 * 对于 1688 / AliExpress 等平台的规则化校验识别，建议在调用侧按需求二次确认。
 *
 * @package Plugin\Mcp\Services\Tools
 */

namespace Plugin\Mcp\Services\Tools;

use Beike\Admin\Services\ProductService;
use Plugin\Mcp\Services\HttpClient;

class ImportTools
{
    /**
     * 从商品 URL 抓取结构化草稿
     */
    public static function fetchFromUrl(array $args): array
    {
        $url = (string) ($args['url'] ?? '');
        $url = trim($url);

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('A valid url is required');
        }

        $html = HttpClient::get($url, 20);

        return self::parseProductPage($url, $html);
    }

    /**
     * 抓取并按草稿创建商品
     */
    public static function importFromUrl(array $args): array
    {
        $draft = self::fetchFromUrl($args);

        $title = $draft['title'] !== '' ? $draft['title'] : 'Imported from ' . parse_url($draft['url'], PHP_URL_HOST);
        $sku   = 'IMP-' . substr(md5($draft['url']), 0, 10);

        $price     = (float) $draft['price'];
        $quantity  = 0;

        $content = trim($draft['description']);

        // 说明：图片仍保留远程 URL（若无法本地化，可后续在后台替换）；
        // 跨境平台（1688/速卖通）镜像图可能防盗链，建议按需重新上传。
        $data = [
            'skus' => [[
                'sku'          => $sku,
                'model'        => '',
                'price'        => $price,
                'origin_price' => 0.0,
                'cost_price'   => 0.0,
                'quantity'     => $quantity,
                'is_default'   => 1,
            ]],
            'descriptions' => [
                'zh_cn' => [
                    'name'      => $title,
                    'content'   => $content,
                ],
            ],
            'categories'   => array_values(array_filter(array_map('intval', (array) ($args['category_ids'] ?? [])))),
            'brand_id'     => (int) ($args['brand_id'] ?? 0),
            'images'       => $draft['images'],
            'active'       => (int) ($args['active'] ?? 1),
            'position'     => 0,
            'shipping'     => true,
            'weight'       => 0,
            'variables'    => '[]',
            'attributes'   => [],
            'relations'    => [],
        ];

        $product = (new ProductService)->create($data);

        return [
            'success'       => true,
            'product_id'    => (int) $product->id,
            'imported_from' => $draft['url'],
            'title'         => $title,
            'sku'           => $sku,
            'note'          => 'Images keep remote URLs; re-upload locally if hotlink-protected.',
        ];
    }

    /**
     * 从服务端 CSV 批量导入商品
     * 列：name, sku, price, quantity, model, category_ids(;分隔), brand_id, description
     */
    public static function importFromCsv(array $args): array
    {
        $path  = (string) ($args['file_path'] ?? '');
        $locale = (string) ($args['locale'] ?? 'zh_cn');
        $active = (int) ($args['active'] ?? 1);

        if ($path === '' || ! file_exists($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException("CSV file not found or unreadable: {$path}");
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open CSV: {$path}");
        }

        $header   = fgetcsv($handle);
        $header   = $header ? array_map('trim', $header) : [];
        $colIndex = [];
        foreach (['name', 'sku', 'price', 'quantity', 'model', 'category_ids', 'brand_id', 'description'] as $col) {
            $colIndex[$col] = array_search($col, $header, true);
        }

        $service  = new ProductService;
        $created  = 0;
        $errors   = [];

        while (($row = fgetcsv($handle)) !== false) {
            $get = function (string $col) use ($row, $colIndex) {
                $idx = $colIndex[$col];

                return ($idx !== false && isset($row[$idx])) ? $row[$idx] : '';
            };

            $name = trim($get('name'));
            if ($name === '') {
                $errors[] = 'Skipped row with empty name';
                continue;
            }

            $catIds = array_values(array_filter(array_map('intval', explode(';', $get('category_ids')))));

            try {
                $data = [
                    'skus' => [[
                        'sku'          => trim($get('sku')) !== '' ? trim($get('sku')) : ('CSV-' . substr(md5($name), 0, 10)),
                        'model'        => trim($get('model')),
                        'price'        => (float) $get('price'),
                        'origin_price' => 0.0,
                        'cost_price'   => 0.0,
                        'quantity'     => (int) $get('quantity'),
                        'is_default'   => 1,
                    ]],
                    'descriptions' => [
                        $locale => ['name' => $name, 'content' => $get('description')],
                    ],
                    'categories' => $catIds,
                    'brand_id'   => (int) $get('brand_id'),
                    'images'     => [],
                    'active'     => $active,
                    'position'   => 0,
                    'shipping'   => true,
                    'weight'     => 0,
                    'variables'  => '[]',
                    'attributes' => [],
                    'relations'  => [],
                ];

                $product = $service->create($data);
                $created++;
            } catch (\Throwable $e) {
                $errors[] = "Failed to import '{$name}': " . $e->getMessage();
            }
        }

        fclose($handle);

        return [
            'success' => true,
            'created' => $created,
            'errors'  => array_slice($errors, 0, 20),
            'had_errors' => count($errors) > 0,
        ];
    }

    // ------------------------------------------------------------------
    // 内部辅助：解析商品页
    // ------------------------------------------------------------------

    protected static function parseProductPage(string $url, string $html): array
    {
        // 去除字符声明干扰，统一解码
        $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');

        $title = self::meta($html, 'og:title');
        if ($title === '') {
            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
                $title = trim(strip_tags($m[1]));
            }
        }
        if ($title === '' && preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
            $title = trim(strip_tags($m[1]));
        }

        $image = self::meta($html, 'og:image');
        $desc  = self::meta($html, 'og:description');
        if ($desc === '' && preg_match('/<meta[^>]+name=["\']description["\'][^>]*content=["\'](.*?)["\']/is', $html, $m)) {
            $desc = trim(strip_tags($m[1]));
        }

        $price = (float) self::meta($html, 'og:price:amount');
        if ($price <= 0 && preg_match('/(?:price|amount)["\']?\s*:\s*["\']?([0-9]+(?:\.[0-9]{1,4})?)/i', $html, $m)) {
            $price = (float) $m[1];
        }

        $currency = self::meta($html, 'og:price:currency') ?: '';

        $images = [];
        if ($image !== '') {
            $images[] = $image;
        }
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $src) {
                $src = html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (str_starts_with($src, '//')) {
                    $src = 'https:' . $src;
                } elseif (str_starts_with($src, '/')) {
                    $parsed = parse_url($url);

                    $src = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . $src;
                }
                if (filter_var($src, FILTER_VALIDATE_URL) && ! in_array($src, $images, true)) {
                    $images[] = $src;
                }
                if (count($images) >= 10) {
                    break;
                }
            }
        }

        return [
            'url'         => $url,
            'title'       => html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'price'       => $price,
            'currency'    => $currency,
            'image'       => $image,
            'images'      => $images,
            'description' => html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ];
    }

    /**
     * 取 meta property/content 值（兼容 og: 与标准）。
     */
    protected static function meta(string $html, string $key): string
    {
        $pattern = '/<meta[^>]+(?:property|name)=["\']' . preg_quote($key, '/') . '["\'][^>]*content=["\'](.*?)["\']/is';
        if (preg_match($pattern, $html, $m)) {
            return trim(strip_tags($m[1]));
        }
        // content 可能在 property 之前
        $pattern2 = '/<meta[^>]+content=["\'](.*?)["\'][^>]+(?:property|name)=["\']' . preg_quote($key, '/') . '["\']/is';
        if (preg_match($pattern2, $html, $m)) {
            return trim(strip_tags($m[1]));
        }

        return '';
    }
}
