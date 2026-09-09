# -*- coding: utf-8 -*-
"""百度翻译（通用文本翻译 API）封装。

需在 .env 配置：
  BAIDU_TRANSLATE_APPID
  BAIDU_TRANSLATE_SECRET_KEY

端点: https://fanyi-api.baidu.com/api/trans/vip/translate
签名: sign = md5(appid + q + salt + key)
"""

from __future__ import annotations

import hashlib
import logging
import random
import time
from typing import Optional

import requests

from .config import settings

logger = logging.getLogger(__name__)

API_URL = "https://fanyi-api.baidu.com/api/trans/vip/translate"

# 免费版 Q 的字节上限（保守值，留出余量用于切割）
_SAFE_BYTES = 5000


class BaiduTranslateError(Exception):
    """百度翻译调用失败。"""


# BeikeShop/locale 代码 -> 百度语言代码
LOCALE_TO_BAIDU = {
    "zh_cn": "zh",
    "zh": "zh",
    "zh_hk": "cht",
    "zh_tw": "cht",
    "cht": "cht",
    "en_us": "en",
    "en": "en",
    "ja_jp": "jp",
    "jp": "jp",
    "ko_kr": "kor",
    "kor": "kor",
    "fr_fr": "fra",
    "fra": "fra",
    "de_de": "de",
    "de": "de",
    "es_es": "spa",
    "spa": "spa",
    "ru_ru": "ru",
    "ru": "ru",
    "pt_pt": "pt",
    "pt": "pt",
    "it_it": "it",
    "it": "it",
    "vi_vn": "vie",
    "vie": "vie",
    "th_th": "th",
    "th": "th",
    "ar": "ara",
}


def locale_to_baidu(locale: str) -> str:
    """把 BeikeShop locale 代码映射为百度语言代码，失败时返回自身。"""
    code = (locale or "").lower().replace("-", "_")
    if code in LOCALE_TO_BAIDU:
        return LOCALE_TO_BAIDU[code]
    # 兜底：取下划线/连字符前的部分
    head = code.split("_")[0]
    return LOCALE_TO_BAIDU.get(head, code)


def _chunk_by_bytes(text: str, limit: int = _SAFE_BYTES):
    """按字节上限切分文本，不破坏 unicode 字符。"""
    chunks, current, current_bytes = [], [], 0
    for char in text:
        char_bytes = len(char.encode("utf-8"))
        if current and current_bytes + char_bytes > limit:
            chunks.append("".join(current))
            current, current_bytes = [], 0
        current.append(char)
        current_bytes += char_bytes
    if current:
        chunks.append("".join(current))
    return chunks or [""]


def _sign(appid: str, q: str, salt: str, secret_key: str) -> str:
    raw = f"{appid}{q}{salt}{secret_key}"
    return hashlib.md5(raw.encode("utf-8")).hexdigest()


def _do_request(appid: str, secret_key: str, q: str, to_lang: str,
                from_lang: str, timeout: int) -> list[dict]:
    salt = str(random.randint(32768, 65536))
    sign = _sign(appid, q, salt, secret_key)
    params = {
        "q": q,
        "from": from_lang,
        "to": to_lang,
        "appid": appid,
        "salt": salt,
        "sign": sign,
    }
    try:
        resp = requests.post(API_URL, data=params, timeout=timeout,
                             headers={"User-Agent": settings.user_agent})
    except requests.RequestException as exc:
        raise BaiduTranslateError(f"请求百度翻译失败: {exc}") from exc

    try:
        body = resp.json()
    except ValueError:
        raise BaiduTranslateError(f"百度翻译返回非 JSON: {resp.text[:300]}")

    error_code = body.get("error_code")
    if error_code and error_code != "52000":
        error_msg = body.get("error_msg") or error_code
        raise BaiduTranslateError(f"百度翻译错误 {error_code}: {error_msg}")

    return body.get("trans_result", [])


def translate(text: str, target_lang: str, source_lang: str = "auto",
              appid: str | None = None, secret_key: str | None = None) -> str:
    """翻译单段文本，自动切段处理超长内容。

    :param text: 待翻译文本
    :param target_lang: 目标语言（BeikeShop locale 代码或百度代码均可）
    :param source_lang: 源语言，默认 auto 自动检测
    :return: 翻译后的文本
    """
    appid = appid or settings.baidu_appid
    secret_key = secret_key or settings.baidu_secret_key
    if not (appid and secret_key):
        raise BaiduTranslateError("未配置百度翻译 BAIDU_TRANSLATE_APPID / BAIDU_TRANSLATE_SECRET_KEY")

    if not text:
        return ""

    to_lang = locale_to_baidu(target_lang)
    from_lang = "auto" if source_lang in ("auto", "") else locale_to_baidu(source_lang)

    parts = _chunk_by_bytes(text)
    translated: list[str] = []
    for i, part in enumerate(parts):
        if not part:
            continue
        results = _do_request(appid, secret_key, part, to_lang, from_lang,
                              settings.baidu_http_timeout)
        if not results:
            continue
        translated.append("".join(item.get("dst", "") for item in results))
    return "\n".join(translated)


def translate_batch(items: dict, target_lang: str, source_lang: str = "auto") -> dict:
    """翻译一个 {字段名: 文本} 字典，逐字段翻译并返回新字典。"""
    return {
        key: translate(value, target_lang, source_lang) if value else ""
        for key, value in items.items()
        if value is not None
    }
