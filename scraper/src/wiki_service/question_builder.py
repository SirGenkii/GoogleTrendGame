from __future__ import annotations

from dataclasses import dataclass
from datetime import date
from random import sample
from typing import List, Sequence

import httpx
from sqlalchemy import select
from sqlalchemy.orm import Session

from .config import get_settings
from .models import Article, ArticleSemesterStat, ArticleTheme, Question, QuestionArticle, Theme
from .wikimedia_client import WikimediaClient


@dataclass
class QuestionPayload:
    id: int
    theme: str
    year: int
    semester: str
    articles: List[str]


def semester_dates(year: int, semester: str) -> tuple[date, date]:
    if semester not in ("S1", "S2"):
        raise ValueError("Semester must be S1 or S2.")
    if semester == "S1":
        return date(year, 1, 1), date(year, 6, 30)
    return date(year, 7, 1), date(year, 12, 31)


def ensure_theme(session: Session, name: str) -> Theme:
    theme = session.scalar(select(Theme).where(Theme.name == name))
    if theme:
        return theme
    theme = Theme(name=name)
    session.add(theme)
    session.flush()
    return theme


def ensure_article(session: Session, title: str, project: str) -> Article:
    slug = title.replace(" ", "_")
    article = session.scalar(
        select(Article).where(Article.project == project, Article.slug == slug)
    )
    if article:
        return article
    article = Article(project=project, slug=slug, title=title)
    session.add(article)
    session.flush()
    return article


def link_article_theme(session: Session, article: Article, theme: Theme) -> None:
    exists = session.scalar(
        select(ArticleTheme.id).where(
            ArticleTheme.article_id == article.id, ArticleTheme.theme_id == theme.id
        )
    )
    if exists:
        return
    session.add(ArticleTheme(article=article, theme=theme))
    session.flush()


def ensure_semester_stat(
    session: Session,
    article: Article,
    year: int,
    semester: str,
    client: WikimediaClient,
) -> ArticleSemesterStat:
    stat = session.scalar(
        select(ArticleSemesterStat).where(
            ArticleSemesterStat.article_id == article.id,
            ArticleSemesterStat.year == year,
            ArticleSemesterStat.semester == semester,
        )
    )
    if stat:
        return stat

    start, end = semester_dates(year, semester)
    try:
        daily_views = client.fetch_daily_views(article.slug, start=start, end=end)
    except httpx.HTTPStatusError as exc:
        # L'article ou la période peut ne pas exister dans l'API (404/400) : on laisse remonter pour skipper la question.
        raise
    total = sum(item["views"] for item in daily_views)
    days = max(len(daily_views), 1)
    avg_daily = total / days
    stat = ArticleSemesterStat(
        article=article,
        year=year,
        semester=semester,
        views_total=total,
        views_avg_daily=avg_daily,
        series=daily_views,
    )
    session.add(stat)
    session.flush()
    return stat


def upsert_semester_stat_from_series(
    session: Session,
    article: Article,
    year: int,
    semester: str,
    series: list[dict],
) -> ArticleSemesterStat:
    stat = session.scalar(
        select(ArticleSemesterStat).where(
            ArticleSemesterStat.article_id == article.id,
            ArticleSemesterStat.year == year,
            ArticleSemesterStat.semester == semester,
        )
    )
    if stat:
        return stat
    total = sum(point.get("views", 0) for point in series)
    stat = ArticleSemesterStat(
        article=article,
        year=year,
        semester=semester,
        views_total=total,
        views_avg_daily=total / max(len(series), 1),
        series=series,
    )
    session.add(stat)
    session.flush()
    return stat


def pick_random_articles(session: Session, theme: Theme, limit: int = 4) -> List[Article]:
    article_ids = session.scalars(
        select(Article.id).join(ArticleTheme).where(ArticleTheme.theme_id == theme.id)
    ).all()
    if len(article_ids) < limit:
        raise ValueError("Not enough articles linked to this theme.")
    chosen_ids = sample(article_ids, limit)
    return session.scalars(select(Article).where(Article.id.in_(chosen_ids))).all()


def build_question(
    session: Session,
    theme_name: str,
    year: int,
    semester: str,
    articles: Sequence[str] | None = None,
) -> QuestionPayload:
    settings = get_settings()
    theme = ensure_theme(session, theme_name)
    client = WikimediaClient(project=settings.wikimedia_project)

    if articles:
        article_objs = []
        for title in articles:
            art = ensure_article(session, title, project=settings.wikimedia_project)
            link_article_theme(session, art, theme)
            article_objs.append(art)
    else:
        article_objs = pick_random_articles(session, theme, 4)

    existing = session.scalar(
        select(Question).where(
            Question.theme_id == theme.id,
            Question.year == year,
            Question.semester == semester,
        )
    )
    if existing:
        return QuestionPayload(
            id=existing.id,
            theme=theme.name,
            year=year,
            semester=semester,
            articles=[qa.article.title for qa in existing.articles],
        )

    question = Question(theme=theme, year=year, semester=semester, status="ready")
    session.add(question)
    session.flush()

    for article in article_objs:
        stat = ensure_semester_stat(session, article, year, semester, client)
        qa = QuestionArticle(
            question=question,
            article=article,
            views_total=stat.views_total,
            views_avg_daily=stat.views_avg_daily,
        )
        session.add(qa)

    session.flush()
    return QuestionPayload(
        id=question.id,
        theme=theme.name,
        year=year,
        semester=semester,
        articles=[article.title for article in article_objs],
    )
