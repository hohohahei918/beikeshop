# -*- coding: utf-8 -*-
"""配置加载模块。

支持从环境变量或项目根目录的 .env 文件读取配置。
"""

from __future__ import annotations

import os
from dataclasses import dataclass, field
from pathlib import Path

try:
    from dotenv import load_dotenv
except ImportError:  # pragma: no cover
    load_dotenv = None


def _load_env_file() -> None:
    """加载项目目录下的 .env（幂等，可多次调用）。"""
    if load_dotenv is None:
        return
    # 优先当前工作目录，其次 mcp-server 项目目录
    for base in (Path.cwd(), Path(__file__).resolve().parent.parent):
        env_path = base / ".env"
        if env_path.exists():
            load_dotenv(env_path, override=False)


_load_env_file()


@dataclass
class Settings:
    """BeikeShop MCP Server 运行配置。"""

    # BeikeShop 站点根地址（如 https://shop.example.com），必填
    beikeshop_api_url: str = field(
        default_factory=lambda: os.getenv("BEIKESHOP_API_URL", "").rstrip("/")
    )
    # 后台管理员 Token（后台-个人中心或 API 文档获取），必填
    beikeshop_admin_token: str = field(
        default_factory=lambda: os.getenv("BEIKESHOP_ADMIN_TOKEN", "")
    )
    # 默认商品语言（locale 代码，如 zh_cn / en / en_us）
    default_locale: str = field(default_factory=lambda: os.getenv("DEFAULT_LOCALE", "zh_cn"))
    # 商品默认分类 ID（创建商品未指定分类时使用），可选
    default_category_id: int = field(
        default_factory=lambda: _to_int(os.getenv("DEFAULT_CATEGORY_ID", "0"))
    )
    # 默认品牌 ID，可选
    default_brand_id: int = field(
        default_factory=lambda: _to_int(os.getenv("DEFAULT_BRAND_ID", "0"))
    )

    # 百度翻译（通用文本翻译 API）
    baidu_appid: str = field(
        default_factory=lambda: os.getenv("BAIDU_TRANSLATE_APPID", "")
    )
    baidu_secret_key: str = field(
        default_factory=lambda: os.getenv("BAIDU_TRANSLATE_SECRET_KEY", "")
    )
    baidu_http_timeout: int = field(
        default_factory=lambda: _to_int(os.getenv("BAIDU_HTTP_TIMEOUT", "10"), 10)
    )

    # 汇率源（无需 key 的公开接口；返回 {"USD": 1.0, "CNY": ..., "USD": ...} 格式）
    fx_api_url: str = field(
        default_factory=lambda: os.getenv(
            "FX_API_URL", "https://open.er-api.com/v6/latest/USD"
        )
    )
    fx_rate_override: dict = field(
        default_factory=lambda: _parse_rate_override(os.getenv("FX_RATE_OVERRIDE", ""))
    )

    # 抓取相关
    user_agent: str = field(
        default_factory=lambda: os.getenv(
            "SCRAPE_USER_AGENT",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
            "(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
        )
    )
    scrape_timeout: int = field(
        default_factory=lambda: _to_int(os.getenv("SCRAPE_TIMEOUT", "15"), 15)
    )
    http_timeout: int = field(
        default_factory=lambda: _to_int(os.getenv("HTTP_TIMEOUT", "20"), 20)
    )

    @property
    def api_ready(self) -> bool:
        return bool(self.beikeshop_api_url and self.beikeshop_admin_token)

    @property
    def baidu_ready(self) -> bool:
        return bool(self.baidu_appid and self.baidu_secret_key)


def _to_int(value: str, default: int = 0) -> int:
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


def _parse_rate_override(raw: str) -> dict:
    """解析 FX_RATE_OVERRIDE，格式 'USD:1,CNY:7.2,EUR:1.08'。"""
    result: dict = {}
    if not raw:
        return result
    for part in raw.split(","):
        if ":" not in part:
            continue
        code, _, val = part.partition(":")
        try:
            result[code.strip().upper()] = float(val.strip())
        except ValueError:
            continue
    return result


# 全局单例
settings = Settings()
