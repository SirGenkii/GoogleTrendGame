from __future__ import annotations

from collections import defaultdict
from datetime import date
from typing import Dict, List

import httpx

from .config import get_settings

IGNORED_ARTICLES = {"Main_Page", "Sp%C3%A9cial%3ARecherche", "Special:Search"}


class TopViewsFetcher:
    BASE_URL = "https://wikimedia.org/api/rest_v1/metrics/pageviews/top"

    def __init__(self, project: str | None = None, user_agent: str | None = None) -> None:
        settings = get_settings()
        self.project = project or settings.wikimedia_project
        self.user_agent = user_agent or settings.user_agent
        self.client = httpx.Client(timeout=30, headers={"User-Agent": self.user_agent})

    def _months_for_semester(self, semester: str) -> List[int]:
        if semester not in ("S1", "S2"):
            raise ValueError("Semester must be S1 or S2.")
        return list(range(1, 7)) if semester == "S1" else list(range(7, 13))

    def fetch_semester_top(self, year: int, semester: str) -> Dict[str, dict]:
        months = self._months_for_semester(semester)
        per_article_daily: dict[str, dict[str, int]] = defaultdict(dict)

        for month in months:
            url = f"{self.BASE_URL}/{self.project}/all-access/{year}/{month:02d}/all-days"
            resp = self.client.get(url)
            if resp.status_code == 404:
                # Données top non disponibles pour ce mois (souvent avant 2016). On saute.
                continue
            resp.raise_for_status()
            payload = resp.json()
            for item in payload.get("items", []):
                day = item.get("day")  # e.g. "20240101"
                if not day:
                    continue
                for article in item.get("articles", []):
                    title = article.get("article")
                    if not title or title in IGNORED_ARTICLES:
                        continue
                    views = int(article.get("views", 0))
                    per_article_daily[title][day] = per_article_daily[title].get(day, 0) + views

        aggregated: Dict[str, dict] = {}
        for title, daily_map in per_article_daily.items():
            total = sum(daily_map.values())
            series = [{"timestamp": day, "views": views} for day, views in sorted(daily_map.items())]
            aggregated[title] = {
                "views_total": total,
                "views_avg_daily": total / max(len(daily_map), 1),
                "series": series,
            }
        return aggregated
