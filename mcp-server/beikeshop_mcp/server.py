# -*- coding: utf-8 -*-
"""BeikeShop MCP Server 主入口。

基于 FastMCP（MCP Python SDK v1.x），通过 stdio 与 MCP 客户端通信。
覆盖能力域：商品找寻 / 资料搬运 / 翻译 / 定价 / 发布。

运行方式：
    .venv/bin/python -m beikeshop_mcp.server            # 代码模块方式
    或
    .venv/bin/python run.py                              # 脚本入口
"""

from __future__ import annotations

import csv
import io
import json
import logging
import uuid
from typing import Any, Optional

from mcp.server.fastmcp import FastMCP

from .beike_client import BeikeAPIError, client, get_product_list_payload
from .baidu_translate import BaiduTranslateError, locale_to_baidu, translate, translate_batch
from .config import settings
from .pricing import PricingError, fetch_exchange_rates, suggest_price
from .scraper import ScrapeError, extract_product

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(name)s %(levelname)s %(message)s",
)
logger = logging.getLogger("beikeshop_mcp")

mcp = FastMCP(
    "beikeshop-mcp",
    instructions=(
        "BeikeShop 电商运营 MCP 服务。可完成：商品检索与详情查询、"
        "从商品 URL/CSV 搬运资料生成商品草稿、百度翻译多语言化、"
        "基于成本+毛利率+汇率的定价建议与改价、商品创建/更新/上下架/删除。"
    ),
)


# =====================================================================
# 通用工具函数
# =====================================================================

def _require_api() -> None:
    if not settings.api_ready:
        raise BeikeAPIError(
            "未配置 BeikeShop API，请在 mcp-server/.env 中设置 "
            "BEIKESHOP_API_URL 与 BEIKESHOP_ADMIN_TOKEN"
        )


def _build_descriptions(
    name: str,
    locale: str,
    summary: str = "",
    content: str = "",
    meta_title: str = "",
    meta_description: str = "",
    meta_keywords: str = "",
    other_descriptions: Optional[dict] = None,
) -> dict:
    """构建 BeikeShop 多语言描述集 {locale: {...}}。"""
    descriptions = {
        locale: {
            "name": name,
            "summary": summary or "",
            "content": content or "",
            "meta_title": meta_title or "",
            "meta_description": meta_description or "",
            "meta_keywords": meta_keywords or "",
        }
    }
    for loc, desc in (other_descriptions or {}).items():
        base = dict(descriptions[locale])
        base.update({k: v for k, v in desc.items() if v})
        descriptions[loc] = base
    return descriptions


def _build_sku(
    price: float,
    sku_code: str = "",
    model: str = "",
    origin_price: float = 0.0,
    cost_price: float = 0.0,
    quantity: int = 0,
) -> dict:
    return {
        "sku": sku_code or f"SKU-{uuid.uuid4().hex[:8].upper()}",
        "model": model or "",
        "price": float(price or 0),
        "origin_price": float(origin_price or 0),
        "cost_price": float(cost_price or 0),
        "quantity": int(quantity or 0),
    }


def _build_payload(
    descriptions: dict,
    skus: list,
    category_ids: Optional[list] = None,
    brand_id: int = 0,
    images: Optional[list] = None,
    active: bool = False,
    weight: float = 0.0,
    shipping: bool = True,
    video: str = "",
    attributes: Optional[list] = None,
) -> dict:
    """组装 BeikeShop Admin API 的商品完整载荷。"""
    return {
        "brand_id": int(brand_id or settings.default_brand_id),
        "position": 0,
        "weight": float(weight or 0),
        "shipping": bool(shipping),
        "video": video or "",
        "active": bool(active),
        "images": list(images or []),
        "descriptions": descriptions,
        "attributes": list(attributes or []),
        "skus": skus,
        "categories": [int(c) for c in (category_ids or [])],
        "relations": [],
    }


def _roundtrip_payload(product_id: int, overrides: Optional[dict] = None) -> tuple[dict, dict]:
    """读取商品详情并重建完整更新载荷（无损：含全语言描述/分类/成本价）。"""
    detail = client.get_product(product_id)
    product = detail.get("product") or {}
    overrides = overrides or {}

    # 全语言描述（优先使用 show 接口增强返回，否则用默认语言重建）
    raw_descriptions = product.get("descriptions") or {}
    if raw_descriptions:
        descriptions = raw_descriptions
    else:
        descriptions = _build_descriptions(
            product.get("name") or "",
            settings.default_locale,
            content=product.get("description") or "",
        )

    # 原始 SKU（含 cost_price），兜底用 skus
    raw_skus = product.get("skus_detail") or product.get("skus") or []
    skus = [
        _build_sku(
            float(s.get("price") or 0),
            s.get("sku") or "",
            s.get("model") or "",
            float(s.get("origin_price") or 0),
            float(s.get("cost_price") or 0),
            int(s.get("quantity") or 0),
        )
        for s in raw_skus
    ]

    payload = _build_payload(
        descriptions=descriptions,
        skus=skus,
        category_ids=product.get("categories") or [],
        brand_id=int(product.get("brand_id") or 0),
        active=bool(product.get("active")),
        weight=float(product.get("weight") or 0),
    )
    payload.update(overrides)
    return payload, product


# =====================================================================
# 1. 连接自检
# =====================================================================

@mcp.tool()
def beikeshop_health() -> dict:
    """检查 MCP 服务到 BeikeShop 的 API 连通性与 Token 有效性。"""
    return client.health()


# =====================================================================
# 2. 商品找寻
# =====================================================================

@mcp.tool()
def search_products(
    keyword: Optional[str] = None,
    name: Optional[str] = None,
    sku: Optional[str] = None,
    category_id: Optional[int] = None,
    brand_id: Optional[int] = None,
    price_range: Optional[str] = None,
    active: Optional[bool] = None,
    per_page: int = 20,
    page: int = 1,
) -> dict:
    """在商城中检索商品列表（支持关键词/SKU/分类/品牌/价格区间/上下架状态）。

    :param keyword: 关键词（同时匹配商品名 / SKU / 型号）
    :param name: 商品名称模糊匹配
    :param sku: SKU 模糊匹配
    :param category_id: 分类 ID
    :param brand_id: 品牌 ID
    :param price_range: 价格区间，如 "10-100"（货币单位对应商城当前币种）
    :param active: True 仅上架，False 仅下架，不传则全部
    :param per_page: 每页数量，默认 20
    :param page: 页码，默认 1
    """
    _require_api()
    params = {"per_page": per_page, "page": page}
    if keyword:
        params["keyword"] = keyword
    if name:
        params["name"] = name
    if sku:
        params["sku"] = sku
    if category_id:
        params["category_id"] = category_id
    if brand_id:
        params["brand_id"] = brand_id
    if price_range:
        params["price"] = price_range
    if active is not None:
        params["active"] = 1 if active else 0

    result = client.list_products(params)
    items = get_product_list_payload(result)
    return {
        "count": len(items),
        "products": items,
        "page": page,
        "per_page": per_page,
    }


@mcp.tool()
def get_product_detail(product_id: int) -> dict:
    """获取单个商品的完整详情（含全语言描述、全部 SKU、分类、库存、价格）。"""
    _require_api()
    return client.get_product(product_id)


@mcp.tool()
def list_categories() -> list:
    """获取商城全部分类列表。"""
    _require_api()
    result = client.list_categories()
    return result.get("data", result) if isinstance(result, dict) else result


@mcp.tool()
def list_brands() -> list:
    """获取商城全部品牌列表。"""
    _require_api()
    result = client.list_brands()
    return result.get("data", result) if isinstance(result, dict) else result


# =====================================================================
# 3. 资料搬运
# =====================================================================

@mcp.tool()
def fetch_product_from_url(url: str) -> dict:
    """从商品详情页 URL 抓取商品资料（标题/价格/图片/描述/SKU）。

    支持 1688、AliExpress 及通用商品页（基于 og 标签 + JSON-LD）。
    1688/AliExpress 有反爬风控，可能被拦截，将返回明确提示。
    """
    return extract_product(url)


@mcp.tool()
def import_product_from_url(
    url: str,
    locale: Optional[str] = None,
    category_ids: Optional[list] = None,
    brand_id: int = 0,
    price_override: Optional[float] = None,
    cost_price: Optional[float] = None,
    quantity: int = 0,
    active: bool = False,
) -> dict:
    """抓取指定商品 URL 的资料并创建为商城商品草稿。

    :param url: 商品页 URL（1688 / AliExpress / 通用商品页）
    :param locale: 主语言 locale，默认取配置 DEFAULT_LOCALE
    :param category_ids: 商品分类 ID 列表
    :param brand_id: 品牌 ID，默认取配置 DEFAULT_BRAND_ID
    :param price_override: 覆盖抓取到的价格（目标货币）
    :param cost_price: 成本价
    :param quantity: 初始库存，默认 0
    :param active: 是否直接上架，默认 False（草稿）
    """
    _require_api()
    loc = locale or settings.default_locale
    data = extract_product(url)

    price = price_override if price_override is not None else data.get("price")
    if not price:
        raise ScrapeError("未能从页面识别到价格，请通过 price_override 指定")

    descriptions = _build_descriptions(
        name=data["title"], locale=loc,
        content=data.get("description") or data.get("content_html") or "",
    )
    skus = [_build_sku(price=price, sku_code=data.get("model") or "",
                       cost_price=cost_price or 0, quantity=quantity)]
    payload = _build_payload(
        descriptions=descriptions, skus=skus,
        category_ids=category_ids or [], brand_id=brand_id,
        images=data.get("images") or [], active=active,
    )

    created = client.create_product(payload)
    return {
        "created": True,
        "source_url": url,
        "platform": data.get("platform"),
        "product_name": data["title"],
        "price": price,
        "images": len(data.get("images") or []),
        "api_message": created.get("message") or created,
    }


@mcp.tool()
def import_products_from_csv(
    csv_text: str,
    locale: Optional[str] = None,
    default_active: bool = False,
) -> dict:
    """从 CSV 文本批量导入商品。

    CSV 需含表头，必填列：name, price。
    可选列：summary, content, sku, model, cost_price, origin_price, quantity,
            brand_id, category_ids(分号分隔), images(分号分隔), active(1/0)。

    :param csv_text: CSV 完整文本（UTF-8，第一行为表头）
    :param locale: 主语言 locale
    :param default_active: 未指定 active 列时的默认状态
    """
    _require_api()
    loc = locale or settings.default_locale
    reader = csv.DictReader(io.StringIO(csv_text))
    rows = list(reader)
    if not rows:
        raise ValueError("CSV 内容为空或缺少表头")

    results = []
    for idx, row in enumerate(rows, start=1):
        try:
            name = (row.get("name") or "").strip()
            if not name:
                raise ValueError("缺少 name 列")
            price = float(row.get("price") or 0)
            category_ids = [
                int(x) for x in str(row.get("category_ids", "")).split(";") if str(x).strip().isdigit()
            ]
            images = [x.strip() for x in str(row.get("images", "")).split(";") if x.strip()]
            descriptions = _build_descriptions(
                name=name, locale=loc,
                summary=row.get("summary") or "",
                content=row.get("content") or "",
            )
            skus = [_build_sku(
                price=price,
                sku_code=row.get("sku") or "",
                model=row.get("model") or "",
                origin_price=float(row.get("origin_price") or 0),
                cost_price=float(row.get("cost_price") or 0),
                quantity=int(row.get("quantity") or 0),
            )]
            active = default_active
            if str(row.get("active", "")).strip() in ("1", "true", "True"):
                active = True
            payload = _build_payload(
                descriptions=descriptions, skus=skus,
                category_ids=category_ids,
                brand_id=int(row.get("brand_id") or 0) if row.get("brand_id") else 0,
                images=images, active=active,
            )
            client.create_product(payload)
            results.append({"row": idx, "success": True, "name": name})
        except Exception as exc:  # noqa: BLE001 - 单行失败不影响导入整体
            results.append({"row": idx, "success": False, "name": row.get("name", ""),
                            "error": str(exc)})

    success = sum(1 for r in results if r["success"])
    return {"total": len(results), "success": success, "failed": len(results) - success,
            "detail": results}


# =====================================================================
# 4. 翻译
# =====================================================================

@mcp.tool()
def translate_text(
    text: str,
    target_language: str,
    source_language: str = "auto",
) -> str:
    """使用百度翻译将文本翻译为目标语言（locale 代码或百度语言代码）。

    :param text: 待翻译文本（超长自动切段）
    :param target_language: 目标语言，如 "en" / "en_us" / "ja" / "fr"
    :param source_language: 源语言，默认 auto 自动检测
    """
    try:
        return translate(text, target_language, source_language)
    except BaiduTranslateError as exc:
        raise RuntimeError(f"翻译失败: {exc}") from exc


@mcp.tool()
def build_product_descriptions(
    name: str,
    target_locales: list,
    summary: Optional[str] = None,
    content: Optional[str] = None,
    source_language: str = "auto",
) -> dict:
    """将商品标题/简介/详情翻译为多个语言，生成可直接用于创建商品的多语言描述集。

    返回 {locale: {name, summary, content}}，可用于 create_product 的
    other_descriptions 参数。首个目标语言若与源语言相同会被跳过。

    :param name: 商品标题（必填）
    :param target_locales: 目标语言列表，如 ["en", "ja", "fr"]
    :param summary: 商品简介（可选）
    :param content: 商品详情（可选）
    :param source_language: 源语言，默认 auto
    """
    result = {}
    for loc in target_locales:
        if locale_to_baidu(loc) == locale_to_baidu(source_language):
            result[loc] = {"name": name, "summary": summary or "", "content": content or ""}
            continue
        fields = {"name": name}
        if summary:
            fields["summary"] = summary
        if content:
            fields["content"] = content
        translated = translate_batch(fields, loc, source_language)
        result[loc] = {
            "name": translated.get("name") or name,
            "summary": translated.get("summary") or "",
            "content": translated.get("content") or "",
        }
    return result


@mcp.tool()
def translate_product(
    product_id: int,
    target_locales: list,
    source_locale: Optional[str] = None,
) -> dict:
    """读取已有商品，将其描述翻译为目标语言并写回（无损回写，保留分类/库存/成本价）。

    :param product_id: 商品 ID
    :param target_locales: 目标语言列表，如 ["en", "ja"]
    :param source_locale: 源语言 locale，默认取配置 DEFAULT_LOCALE
    """
    _require_api()
    payload, product = _roundtrip_payload(product_id)
    src = source_locale or settings.default_locale

    src_desc = payload["descriptions"].get(src) or list(payload["descriptions"].values())[0]
    base = {
        "name": src_desc.get("name") or "",
        "summary": src_desc.get("summary") or "",
        "content": src_desc.get("content") or "",
    }

    for loc in target_locales:
        if loc == src or loc in payload["descriptions"]:
            continue
        translated = translate_batch(
            {k: v for k, v in base.items() if v}, loc, src
        )
        new_desc = dict(src_desc)
        new_desc["name"] = translated.get("name") or src_desc.get("name") or ""
        new_desc["summary"] = translated.get("summary") or ""
        new_desc["content"] = translated.get("content") or ""
        payload["descriptions"][loc] = new_desc

    client.update_product(product_id, payload)
    return {"updated": True, "product_id": product_id,
            "target_locales": target_locales, "product_name": base.get("name")}


# =====================================================================
# 5. 定价
# =====================================================================

@mcp.tool()
def get_exchange_rate(base: str = "USD", targets: str = "CNY,USD,EUR,JPY") -> dict:
    """获取主流货币汇率（1 单位 base 兑换多少目标货币）。

    :param base: 基准货币代码，默认 USD
    :param targets: 逗号分隔的目标货币，如 "CNY,USD,EUR"
    """
    try:
        rates = fetch_exchange_rates(base)
        wanted = [t.strip().upper() for t in targets.split(",") if t.strip()]
        result = {c: rates.get(c) for c in wanted if rates.get(c) is not None}
        return {"base": base.upper(), "rates": result,
                "note": "来自外部汇率接口，可配置 FX_RATE_OVERRIDE 手工兜底"}
    except PricingError as exc:
        raise RuntimeError(str(exc)) from exc


@mcp.tool()
def suggest_price(
    cost_price: float,
    gross_margin_pct: float,
    cost_currency: str = "CNY",
    target_currency: str = "USD",
    ending: Optional[float] = None,
    min_price: Optional[float] = None,
) -> dict:
    """基于成本价 + 目标毛利率 + 实时汇率计算建议售价。

    :param cost_price: 成本价（数值）
    :param gross_margin_pct: 目标毛利率百分数，如 30 表示 30%
    :param cost_currency: 成本计价货币，如 CNY
    :param target_currency: 目标售价货币，如 USD
    :param ending: 尾数美化，如 0.99 使售价以 .99 结尾；None 不处理
    :param min_price: 最低售价下限（目标货币）
    """
    return suggest_price(cost_price, gross_margin_pct, cost_currency,
                         target_currency, ending, min_price)


@mcp.tool()
def set_product_price(
    product_id: int,
    price: float,
    cost_price: Optional[float] = None,
    origin_price: Optional[float] = None,
) -> dict:
    """设置商品「默认 SKU」的售价（可选同时更新成本价/划线价），无损回写。

    :param product_id: 商品 ID
    :param price: 新售价（商城当前基准货币）
    :param cost_price: 更新成本价（可选）
    :param origin_price: 更新划线价（可选）
    """
    _require_api()
    payload, product = _roundtrip_payload(product_id)
    if not payload["skus"]:
        raise ValueError(f"商品 {product_id} 无 SKU")
    first = payload["skus"][0]
    first["price"] = float(price)
    if cost_price is not None:
        first["cost_price"] = float(cost_price)
    if origin_price is not None:
        first["origin_price"] = float(origin_price)
    client.update_product(product_id, payload)
    return {"updated": True, "product_id": product_id,
            "product_name": (product.get("name") or "")[:80],
            "new_price": first["price"]}


@mcp.tool()
def update_price_by_margin(
    product_id: int,
    gross_margin_pct: float,
    cost_currency: str = "CNY",
    target_currency: str = "USD",
    ending: Optional[float] = None,
) -> dict:
    """按「成本 + 目标毛利率 + 汇率」重新计算并更新商品售价（全部 SKU）。

    仅当 SKU 有成本价时才会改价；无成本价的 SKU 保持原价并记录。
    """
    _require_api()
    if gross_margin_pct <= 0:
        raise ValueError("毛利率必须大于 0")
    payload, product = _roundtrip_payload(product_id)

    updated = []
    for sku in payload["skus"]:
        cost = sku.get("cost_price") or 0
        if cost <= 0:
            continue
        suggestion = suggest_price(cost, gross_margin_pct, cost_currency,
                                   target_currency, ending)
        sku["price"] = suggestion["suggested_price"]
        updated.append({"sku": sku["sku"], "cost": cost,
                        "new_price": suggestion["suggested_price"]})

    if not updated:
        return {"updated": False, "product_id": product_id,
                "reason": "该商品所有 SKU 均无成本价，未做改价；请先设置 cost_price"}

    client.update_product(product_id, payload)
    return {"updated": True, "product_id": product_id,
            "product_name": (product.get("name") or "")[:80],
            "changed_skus": updated}


# =====================================================================
# 6. 发布
# =====================================================================

@mcp.tool()
def create_product(
    name: str,
    price: float,
    locale: Optional[str] = None,
    summary: str = "",
    content: str = "",
    sku_code: str = "",
    model: str = "",
    origin_price: float = 0.0,
    cost_price: float = 0.0,
    quantity: int = 0,
    category_ids: Optional[list] = None,
    brand_id: int = 0,
    images: Optional[list] = None,
    other_descriptions: Optional[dict] = None,
    active: bool = False,
) -> dict:
    """创建商品。默认创建为草稿（不上架），需发布时令 active=True。

    :param name: 商品名称（主语言，必填）
    :param price: 售价（必填）
    :param locale: 主语言 locale，默认配置值
    :param summary: 商品简介
    :param content: 商品详情（可含 HTML）
    :param sku_code: SKU 编码，缺省自动生成
    :param model: 型号
    :param origin_price: 划线价（原价）
    :param cost_price: 成本价
    :param quantity: 初始库存，默认 0
    :param category_ids: 分类 ID 列表
    :param brand_id: 品牌 ID
    :param images: 图片路径列表
    :param other_descriptions: 其它语言描述，如
        {"en": {"name": "English Name", "content": "..."}}，
        可用 build_product_descriptions 生成
    :param active: True 立即上架，默认 False 创建为草稿
    """
    _require_api()
    loc = locale or settings.default_locale
    descriptions = _build_descriptions(
        name=name, locale=loc, summary=summary or "", content=content or "",
        other_descriptions=other_descriptions,
    )
    skus = [_build_sku(price=price, sku_code=sku_code, model=model,
                       origin_price=origin_price, cost_price=cost_price,
                       quantity=quantity)]
    payload = _build_payload(
        descriptions=descriptions, skus=skus,
        category_ids=category_ids or [], brand_id=brand_id,
        images=images or [], active=active,
    )
    created = client.create_product(payload)
    return {"created": True, "name": name, "price": price,
            "active": active, "api_message": created.get("message") or created}


@mcp.tool()
def update_product(
    product_id: int,
    name: Optional[str] = None,
    price: Optional[float] = None,
    locale: Optional[str] = None,
    summary: Optional[str] = None,
    content: Optional[str] = None,
    sku_code: Optional[str] = None,
    cost_price: Optional[float] = None,
    quantity: Optional[int] = None,
    category_ids: Optional[list] = None,
    brand_id: Optional[int] = None,
    images: Optional[list] = None,
    other_descriptions: Optional[dict] = None,
    active: Optional[bool] = None,
) -> dict:
    """覆盖式更新商品（基于当前详情无损重构：保留分类/全语言描述/成本价）。

    注意：BeikeShop 的更新接口是全量覆盖，任何语言 / 分类保留均来自服务端当前值；
    若上传 is None 则保持原值。

    :param product_id: 商品 ID
    :param name: 指定即更新主语言名称
    :param price: 指定即更新默认 SKU 售价
    :param locale: 主语言 locale（改 name 时使用）
    :param summary/content: 主语言简介/详情，传 None 保持
    :param cost_price: 更新默认 SKU 成本价
    :param quantity: 更新默认 SKU 库存
    :param category_ids: 分类 ID 列表（覆盖）
    :param brand_id: 品牌 ID（覆盖）
    :param images: 图片路径（覆盖；不传保持原图）
    :param other_descriptions: 追加/覆盖其它语言描述
    :param active: 上下架状态（覆盖）
    """
    _require_api()
    payload, product = _roundtrip_payload(product_id)
    loc = locale or settings.default_locale

    if name is not None:
        payload["descriptions"].setdefault(loc, {"name": name, "summary": "",
                                                  "content": ""})
        payload["descriptions"][loc]["name"] = name
    if summary is not None:
        payload["descriptions"][loc]["summary"] = summary
    if content is not None:
        payload["descriptions"][loc]["content"] = content

    if payload["skus"]:
        first = payload["skus"][0]
        if price is not None:
            first["price"] = float(price)
        if cost_price is not None:
            first["cost_price"] = float(cost_price)
        if quantity is not None:
            first["quantity"] = int(quantity)

    if category_ids is not None:
        payload["categories"] = [int(c) for c in category_ids]
    if brand_id is not None:
        payload["brand_id"] = int(brand_id)
    if images is not None:
        payload["images"] = list(images)
    if active is not None:
        payload["active"] = bool(active)
    if other_descriptions:
        for loc_, desc in other_descriptions.items():
            payload["descriptions"].setdefault(loc_, {})
            payload["descriptions"][loc_].update({k: v for k, v in desc.items() if v})

    client.update_product(product_id, payload)
    return {"updated": True, "product_id": product_id,
            "product_name": (product.get("name") or "")[:80]}


@mcp.tool()
def set_product_active(product_id: int, active: bool) -> dict:
    """上架或下架商品（无损回写，保留分类/全语言描述/成本价）。

    :param product_id: 商品 ID
    :param active: True 上架，False 下架
    """
    _require_api()
    payload, product = _roundtrip_payload(product_id)
    payload["active"] = bool(active)
    client.update_product(product_id, payload)
    return {"updated": True, "product_id": product_id,
            "product_name": (product.get("name") or "")[:80],
            "active": bool(active)}


@mcp.tool()
def delete_product(product_id: int) -> dict:
    """删除商品（谨慎操作，删除后无法恢复）。"""
    _require_api()
    payload, product = _roundtrip_payload(product_id)
    name = (product.get("name") or "")[:80]
    client.delete_product(product_id)
    return {"deleted": True, "product_id": product_id, "product_name": name}


# =====================================================================
# 入口
# =====================================================================

def main() -> None:
    """以 stdio 方式运行 MCP server。"""
    mcp.run()
