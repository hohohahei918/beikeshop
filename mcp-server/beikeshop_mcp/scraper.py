# -*- coding: utf-8 -*-
"""商品资料抓取器。

从商品详情页 URL 提取结构化信息（标题 / 价格 / 图片 / 描述），
供「资料搬运」能力使用。支持：
- 1688：  https://detail.1688.com/offer/{id}.html
- AliExpress: https://www.aliexpress.com/item/{id}.html
- 其它通用电商/商品页：基于 og 标签 + JSON-LD + meta 的通用提取

注意：1688 / AliExpress 等站点有较强反爬（风控 / 登录墙 / 懒加载），
requests 静态抓取可能拿不到完整数据。遇到反爬时工具会返回明确提示，
可配合浏览器抓取或手动补充。
"""

from __future__ import annotations

import json
import logging
import re
from typing import Any, Optional
from urllib.parse import urlparse

import requests
from bs4 import BeautifulSoup

from .config import settings

logger = logging.getLogger(__name__)


class ScrapeError(Exception):
    """抓取失败。"""


def _session() -> requests.Session:
    s = requests.Session()
    s.headers.update({
        "User-Agent": settings.user_agent,
        "Accept-Language": "zh-CN,zh;q=0.9,en;q=0.8",
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    })
    return s


def _get_html(url: str) -> str:
    try:
        resp = _session().get(url, timeout=settings.scrape_timeout, allow_redirects=True)
    except requests.RequestException as exc:
        raise ScrapeError(f"抓取失败: {exc}") from exc
    if resp.status_code >= 400:
        raise ScrapeError(f"抓取失败: HTTP {resp.status_code}")
    if "vaptcha" in resp.text or "验证" in resp.text[:4000] and resp.text.count("<") < 20:
        raise ScrapeError("站点触发了人机验证（风控），静态抓取被拦截，请改用浏览器抓取")
    return resp.text


def _og(soup: BeautifulSoup, prop: str) -> str:
    tag = soup.find("meta", attrs={"property": f"og:{prop}"}) or \
          soup.find("meta", attrs={"name": f"og:{prop}"})
    return (tag.get("content") or "").strip() if tag else ""


def _json_ld_first(soup: BeautifulSoup) -> Optional[dict]:
    for script in soup.find_all("script", type="application/ld+json"):
        try:
            data = json.loads(script.string or "")
        except (json.JSONDecodeError, TypeError):
            continue
        if isinstance(data, dict):
            return data
        if isinstance(data, list) and data:
            return data[0]
    return None


def _extract_images(soup: BeautifulSoup, ld: dict | None, base: str) -> list[str]:
    images: list[str] = []
    # from JSON-LD
    if ld:
        for key in ("image", "images"):
            value = ld.get(key)
            if isinstance(value, str):
                images.append(value)
            elif isinstance(value, list):
                images.extend(i for i in value if isinstance(i, str))
    # og:image (可以是逗号/空格分隔的多个)
    og_image = _og(soup, "image")
    if og_image:
        images.extend(p.strip() for p in re.split(r"[,\s]+", og_image) if p.strip())
    # 通用 img 标签
    for img in soup.find_all("img", src=True):
        src = img["src"]
        if src.startswith("//"):
            src = "https:" + src
        if not src.startswith("http"):
            continue
        if any(k in src.lower() for k in (".gif", "spacer", "placeholder", "loading")):
            continue
        if src not in images:
            images.append(src)
        if len(images) >= 10:
            break
    return images


def _extract_price(soup: BeautifulSoup, ld: dict | None) -> tuple[float | None, str]:
    """返回 (数值价格, 货币代码)。"""
    currency = ""
    if ld and isinstance(ld.get("offers"), dict):
        offer = ld["offers"]
        if isinstance(offer.get("price"), (int, float, str)):
            try:
                price = float(offer["price"])
                currency = offer.get("priceCurrency") or currency
                return price, currency
            except (TypeError, ValueError):
                pass
    # og:price:amount / product:price:amount
    for key in ("og:price:amount", "product:price:amount", "og:price"):
        tag = soup.find("meta", attrs={"property": key}) or soup.find("meta", attrs={"name": key})
        if tag and tag.get("content"):
            try:
                return float(tag["content"]), ""
            except (TypeError, ValueError):
                pass
    # 常见货币 meta
    money_tag = soup.find("meta", attrs={"property": "og:price:currency"})
    if money_tag:
        currency = money_tag.get("content") or ""
    return None, currency


def _is_captcha_block(html: str) -> bool:
    lowered = html[:6000].lower()
    suspicious = ("vaptcha", "captcha", "secsdk", "punish")
    return any(k in lowered for k in suspicious)


def extract_product(url: str) -> dict[str, Any]:
    """抓取商品页并返回结构化数据。

    返回结构：
    {
      "source_url": str,
      "platform": str,
      "model": str,            # 建议的 SKU/货号（尽量从 URL 取）
      "title": str,
      "price": float | None,
      "currency": str,
      "images": [str, ...],
      "description": str,      # 尽可能干净的纯文本描述
      "content_html": str,     # 描述（保留段落结构的 HTML），可为空
      "raw_title": str,        # 原始标题（可能含平台后缀）
    }
    """
    parsed = urlparse(url)
    host = (parsed.netloc or "").lower()
    platform = "unknown"
    if "1688.com" in host:
        platform = "1688"
    elif "aliexpress" in host or "alipay" in host or "alibaba" in host:
        platform = "aliexpress"
    elif "taobao" in host or "tmall" in host:
        platform = "taobao"
    elif "amazon" in host:
        platform = "amazon"
    elif "ebay" in host:
        platform = "ebay"

    html = _get_html(url)
    if _is_captcha_block(html):
        raise ScrapeError(
            f"{platform} 触发了人机验证（风控 / 登录墙），静态抓取被拦截。"
            "可在 Marvis 中改用浏览器方式打开该链接提取资料"
        )
    soup = BeautifulSoup(html, "html.parser")
    ld = _json_ld_first(soup)

    title = _og(soup, "title") or (soup.title.string.strip() if soup.title else "")
    h1 = soup.find("h1")
    raw_title = title
    if h1 and h1.get_text(strip=True):
        title = h1.get_text(strip=True)
    if not title:
        title = raw_title

    price, currency = _extract_price(soup, ld)
    images = _extract_images(soup, ld, url)

    # 描述：优先 JSON-LD 的 description / 页面 meta / 文本块
    description = ""
    if ld and ld.get("description"):
        description = str(ld["description"]).strip()
    if not description:
        description = _og(soup, "description")
    content_html = ""
    if not description:
        content_section = soup.find("div", class_=re.compile(
            r"(description|desc|detail|product-?info|rich-text)", re.I))
        if content_section:
            content_html = str(content_section)
            description = content_section.get_text(" ", strip=True)[:2000]

    # model：从 URL 或 og 提取（1688 offer id / ali item id）
    model = ""
    offer_match = re.search(r"/offer/(\d+)", url)
    item_match = re.search(r"/item/(\d+)", url)
    asin_match = re.search(r"/dp/([A-Z0-9]{10})", url, re.I)
    if offer_match:
        model = f"1688-{offer_match.group(1)}"
    elif item_match:
        model = f"ALI-{item_match.group(1)}"
    elif asin_match:
        model = asin_match.group(1)

    return {
        "source_url": url,
        "platform": platform,
        "model": model,
        "title": title or raw_title,
        "raw_title": raw_title,
        "price": price,
        "currency": currency,
        "images": images[:12],
        "description": (description or "")[:3000],
        "content_html": content_html[:20000],
    }
