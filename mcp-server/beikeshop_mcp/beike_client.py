# -*- coding: utf-8 -*-
"""BeikeShop Admin REST API 客户端。

对接项目 /admin_api 路由（Brand/Category/Product/Order），
鉴权方式：请求头 `token: <后台管理员Token>`。
"""

from __future__ import annotations

import logging
from typing import Any, Optional

import requests

from .config import settings

logger = logging.getLogger(__name__)


class BeikeAPIError(Exception):
    """BeikeShop API 调用失败。"""


class BeikeClient:
    """面向 BeikeShop Admin API 的轻量客户端。"""

    def __init__(self, base_url: str | None = None, token: str | None = None,
                 timeout: int | None = None):
        self.base_url = (base_url or settings.beikeshop_api_url).rstrip("/")
        self.token = token or settings.beikeshop_admin_token
        self.timeout = timeout or settings.http_timeout

    # ------------------------------------------------------------------ 基础请求
    def _headers(self, extra: dict | None = None) -> dict:
        headers = {
            "Accept": "application/json",
            "Content-Type": "application/json",
            "User-Agent": settings.user_agent,
            "Cache-Control": "no-cache",
        }
        if self.token:
            headers["token"] = self.token
        if extra:
            headers.update(extra)
        return headers

    def _request(self, method: str, path: str, params: dict | None = None,
                 json_body: Any = None) -> Any:
        if not self.base_url:
            raise BeikeAPIError("未配置 BEIKESHOP_API_URL")
        url = f"{self.base_url}{path}"
        try:
            resp = requests.request(
                method, url, headers=self._headers(), params=params,
                json=json_body, timeout=self.timeout,
            )
        except requests.RequestException as exc:
            raise BeikeAPIError(f"请求 {method} {path} 失败: {exc}") from exc

        try:
            body = resp.json()
        except ValueError:
            raise BeikeAPIError(f"接口返回非 JSON（HTTP {resp.status_code}）: {resp.text[:500]}")

        # json_fail 统一 HTTP 422/500 且 status != success
        if body.get("status") != "success":
            message = body.get("message") or f"HTTP {resp.status_code}"
            raise BeikeAPIError(message)
        return body.get("data", {})

    # ------------------------------------------------------------------ 基础验证
    def me(self) -> dict:
        """获取当前 Token 对应的管理员信息，可用来校验连接。"""
        return self._request("GET", "/admin_api/me")

    def health(self) -> dict:
        """连通性自检。"""
        try:
            admin = self.me()
            name = admin.get("name") or admin.get("email") or admin.get("id")
            return {"ok": True, "admin": name, "url": self.base_url}
        except BeikeAPIError as exc:
            return {"ok": False, "error": str(exc), "url": self.base_url}

    # ------------------------------------------------------------------ 商品
    def list_products(self, params: dict | None = None) -> dict:
        """
        GET /admin_api/products
        常见参数：keyword / name / category_id / brand_id / sku / model
                 / price(min-max) / active / created_start / per_page / page / sort
        """
        query = params or {}
        query.setdefault("per_page", 20)
        return self._request("GET", "/admin_api/products", params=query)

    def get_product(self, product_id: int) -> dict:
        return self._request("GET", f"/admin_api/products/{product_id}")

    def create_product(self, payload: dict) -> dict:
        return self._request("POST", "/admin_api/products", json_body=payload)

    def update_product(self, product_id: int, payload: dict) -> dict:
        return self._request("PUT", f"/admin_api/products/{product_id}", json_body=payload)

    def delete_product(self, product_id: int) -> dict:
        return self._request("DELETE", f"/admin_api/products/{product_id}")

    # ------------------------------------------------------------------ 分类 / 品牌
    def list_categories(self, params: dict | None = None) -> dict:
        return self._request("GET", "/admin_api/categories", params=params or {})

    def list_brands(self, params: dict | None = None) -> dict:
        return self._request("GET", "/admin_api/brands", params=params or {})


# 全局单例
client = BeikeClient()


def get_product_list_payload(result: dict) -> Any:
    """从 /products 响应中取出商品列表（兼容分页结构 / 直接数组）。"""
    # Laravel Resource::collection(paginator) 返回 data + meta 结构
    if isinstance(result, dict) and "data" in result:
        return result["data"]
    if isinstance(result, list):
        return result
    return result
