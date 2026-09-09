<?php

/**
 * TranslateTools.php
 *
 * 翻译/多语言描述类 MCP 工具实现。
 *
 * @package Plugin\Mcp\Services\Tools
 */

namespace Plugin\Mcp\Services\Tools;

use Plugin\Mcp\Services\HttpClient;

class TranslateTools
{
    private const BAIDU_API = 'https://fanyi-api.baidu.com/api/trans/vip/translate';

    /**
     * 框架 locale 代码 -> 百度翻译语言代码
     */
    private const LOCALE_TO_BAIDU = [
        'zh_cn' => 'zh',
        'zh'    => 'zh',
        'zh_tw' => 'cht',
        'en'    => 'en',
        'en_us' => 'en',
        'en_gb' => 'en',
        'ja'    => 'jp',
        'ja_jp' => 'jp',
        'ko'    => 'kor',
        'ko_kr' => 'kor',
        'fr'    => 'fra',
        'fr_fr' => 'fra',
        'de'    => 'de',
        'de_de' => 'de',
        'es'    => 'spa',
        'es_es' => 'spa',
        'ru'    => 'ru',
        'ru_ru' => 'ru',
        'it'    => 'it',
        'pt'    => 'pt',
        'vi'    => 'vie',
        'th'    => 'th',
        'id'    => 'id',
        'ar'    => 'ara',
        'tr'    => 'tr',
    ];

    /**
     * 调用百度翻译
     */
    protected static function baiduTranslate(string $text, string $from, string $to): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $appid  = (string) plugin_setting('mcp.baidu_appid', '');
        $secret = (string) plugin_setting('mcp.baidu_secret', '');

        if ($appid === '' || $secret === '') {
            throw new \RuntimeException('Baidu Translate is not configured. Set BAIDU APP ID / Secret in plugin admin page.');
        }

        $salt = (string) time();
        $sign = md5($appid . $text . $salt . $secret);

        $url = self::BAIDU_API . '?' . http_build_query([
            'q'     => $text,
            'from'  => $from,
            'to'    => $to,
            'appid' => $appid,
            'salt'  => $salt,
            'sign'  => $sign,
        ]);

        $json = HttpClient::getJson($url);
        if (! empty($json['error_code'])) {
            throw new \RuntimeException(sprintf('Baidu Translate error [%s]: %s', $json['error_code'], $json['error_msg'] ?? 'unknown'));
        }

        $result = '';
        foreach (($json['trans_result'] ?? []) as $item) {
            $result .= (string) ($item['dst'] ?? '');
        }

        return $result;
    }

    /**
     * 通用文本翻译
     */
    public static function translateText(array $args): array
    {
        $text = (string) ($args['text'] ?? '');
        $from = (string) ($args['from'] ?? 'auto');
        $to   = (string) ($args['to'] ?? 'zh');

        if ($text === '') {
            throw new \InvalidArgumentException('text is required');
        }

        $translated = self::baiduTranslate($text, $from, $to);

        return [
            'src'             => $text,
            'from'            => $from,
            'to'              => $to,
            'translated_text' => $translated,
        ];
    }

    /**
     * 基于源语言基础信息生成多语言商品描述包（可直接喂给 create_product / update_product）
     */
    public static function buildProductDescriptions(array $args): array
    {
        $name      = (string) ($args['name'] ?? '');
        $summary   = (string) ($args['summary'] ?? '');
        $content   = (string) ($args['content'] ?? '');
        $sourceLoc = (string) ($args['source_locale'] ?? 'zh_cn');

        if ($name === '') {
            throw new \InvalidArgumentException('name is required');
        }

        $targetLocales = $args['target_locales'] ?? [];
        $targetLocales = array_values(array_filter(array_map('strval', (array) $targetLocales)));
        if (! $targetLocales) {
            $targetLocales = ['zh_cn', 'en'];
        }

        $sourceBaidu = self::LOCALE_TO_BAIDU[$sourceLoc] ?? $sourceLoc;
        $descriptions = [];

        foreach ($targetLocales as $locale) {
            $baidu = self::LOCALE_TO_BAIDU[$locale] ?? $locale;

            if ($locale === $sourceLoc || $baidu === $sourceBaidu) {
                $locName = $name;
                $locContent = trim($summary . "\n\n" . $content);
            } else {
                $locName = self::baiduTranslate($name, $sourceBaidu, $baidu);
                $full    = trim($summary . "\n\n" . $content);
                $locContent = $full === '' ? '' : self::baiduTranslate($full, $sourceBaidu, $baidu);
            }

            $descriptions[$locale] = [
                'name'             => $locName,
                'content'          => $locContent,
                'meta_title'       => mb_substr($locName, 0, 255),
                'meta_description' => mb_substr($locContent, 0, 500),
                'meta_keywords'    => '',
            ];
        }

        return ['source_locale' => $sourceLoc, 'target_locales' => $targetLocales, 'descriptions' => $descriptions];
    }
}
