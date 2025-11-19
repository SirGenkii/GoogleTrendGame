from __future__ import annotations

from typing import Any, Optional
from urllib.parse import quote

import httpx

from .config import get_settings


class WikiPageClient:
    SUMMARY_URL = "https://{project}/api/rest_v1/page/summary/{title}"

    def __init__(self, project: str | None = None, user_agent: str | None = None) -> None:
        settings = get_settings()
        raw_project = project or settings.wikimedia_project
        if raw_project.endswith(".org"):
            self.project = raw_project
        else:
            self.project = f"{raw_project}.org"
        self.user_agent = user_agent or settings.user_agent
        self.client = httpx.Client(timeout=15, headers={"User-Agent": self.user_agent})

    def fetch_summary(self, slug: str) -> Optional[dict[str, Any]]:
        encoded = quote(slug.replace(" ", "_"))
        url = self.SUMMARY_URL.format(project=self.project, title=encoded)
        resp = self.client.get(url)
        if resp.status_code >= 400:
            return None
        data = resp.json()
        return {
            "title": data.get("title"),
            "page_id": data.get("pageid"),
            "summary": data.get("extract"),
            "image_url": data.get("thumbnail", {}).get("source"),
        }
