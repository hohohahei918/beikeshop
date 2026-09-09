# -*- coding: utf-8 -*-
"""定价工具：建议售价计算与汇率获取。

定价公式：
    目标货币售价 = 成本价(原始货币) × (目标货币汇率 / 成本货币汇率) × (1 + 目标毛利率)

汇率从 FX_API_URL（默认 open.er-api.com，无需 key）拉取，
也可通过 .env 的 FX_RATE_OVERRIDE 手工指定兜底值。
"""

from __future__ import annotations

import logging
from typing import Optional

import requests

from .config import settings

logger = logging.getLogger(__name__)


class PricingError(Exception):
    """定价/汇率获取失败。"""


def fetch_exchange_rates(base: str = "USD") -> dict:
    """从外部 API 拉取汇率字典 {CODE: 相对 base 的汇率}。

    兼容两种常见返回格式：
    - {"rates": {"CNY": 7.2, ...}}
    - {"conversion_rates": {"CNY": 7.2, ...}}
    若带 FX_RATE_OVERRIDE，则以其覆盖（优先使用用户手工值）。
    """
    rates: dict = {}
    try:
        resp = requests.get(settings.fx_api_url, timeout=settings.http_timeout,
                            headers={"User-Agent": settings.user_agent})
        body = resp.json()
    except (requests.RequestException, ValueError) as exc:
        logger.warning("汇率获取失败，使用 FX_RATE_OVERRIDE 兜底: %s", exc)
        body = {}

    for key in ("rates", "conversion_rates"):
        if isinstance(body.get(key), dict):
            rates.update(body[key])

    # 手工覆盖优先
    if settings.fx_rate_override:
        rates.update(settings.fx_rate_override)
    return rates


def get_rate(currency: str, rates: Optional[dict] = None) -> float:
    """获取某货币相对 USD 的汇率（1 USD = X 该货币）。"""
    if rates is None:
        rates = fetch_exchange_rates("USD")
    code = (currency or "USD").upper()
    value = rates.get(code)
    if value:
        return float(value)
    if code == "USD":
        return 1.0
    raise PricingError(f"未获取到货币 {code} 的汇率，请检查 FX_API_URL 或配置 FX_RATE_OVERRIDE")


def suggest_price(
    cost_price: float,
    gross_margin_pct: float,
    cost_currency: str = "CNY",
    target_currency: str = "USD",
    ending: Optional[float] = None,
    min_price: Optional[float] = None,
) -> dict:
    """计算建议售价。

    :param cost_price: 成本价（数值，可为 0）
    :param gross_margin_pct: 目标毛利率（百分数，如 30 表示 30%）
    :param cost_currency: 成本价货币代码（如 CNY）
    :param target_currency: 目标售价货币代码（如 USD）
    :param ending: 价格尾数美化，如 0.99 表示售价保留 .99；None 表示不处理
    :param min_price: 最低售价下限（目标货币），可选
    :return: 含计算过程与结果的字典
    """
    if gross_margin_pct <= 0:
        raise PricingError("毛利率必须大于 0")
    if cost_price < 0:
        raise PricingError("成本价不能为负数")

    rates = fetch_exchange_rates("USD")
    cost_rate = get_rate(cost_currency, rates)
    target_rate = get_rate(target_currency, rates)

    raw_price = cost_price * (target_rate / cost_rate) * (1 + gross_margin_pct / 100.0)

    price = raw_price
    if ending is not None and price >= ending:
        price = float(int(price)) + ending
    if min_price and price < min_price:
        price = min_price

    return {
        "cost_price": round(cost_price, 4),
        "gross_margin_pct": gross_margin_pct,
        "cost_currency": cost_currency.upper(),
        "target_currency": target_currency.upper(),
        "fx_rate": round(target_rate / cost_rate, 6),
        "raw_price": round(raw_price, 4),
        "suggested_price": round(price, 4),
        "ending_applied": bool(ending is not None),
        "min_price_applied": bool(min_price and price == min_price),
    }
