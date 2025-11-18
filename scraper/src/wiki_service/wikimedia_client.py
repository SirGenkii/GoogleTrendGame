from __future__ import annotations

from datetime import date
from typing import List

import httpx
from urllib.parse import quote

from .config import get_settings


class WikimediaClient:
    BASE_URL = "https://wikimedia.org/api/rest_v1/metrics/pageviews/per-article"

    def __init__(self, project: str | None = None, user_agent: str | None = None) -> None:
        settings = get_settings()
        self.project = project or settings.wikimedia_project
        self.user_agent = user_agent or settings.user_agent
        self._client = httpx.Client(
            timeout=20,
            headers={"User-Agent": self.user_agent},
        )

    def _format_date(self, d: date) -> str:
        return d.strftime("%Y%m%d")

    def fetch_daily_views(self, article_slug: str, start: date, end: date) -> List[dict]:
        encoded = quote(article_slug.replace(" ", "_"), safe="")
        start_str = self._format_date(start)
        end_str = self._format_date(end)
        url = f"{self.BASE_URL}/{self.project}/all-access/user/{encoded}/daily/{start_str}/{end_str}"
        resp = self._client.get(url)
        resp.raise_for_status()
        payload = resp.json()
        items = payload.get("items", [])
        return [
            {
                "timestamp": item.get("timestamp"),
                "views": int(item.get("views", 0)),
            }
            for item in items
        ]
