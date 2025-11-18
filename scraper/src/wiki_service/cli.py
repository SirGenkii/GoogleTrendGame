from __future__ import annotations

import csv
import os
from datetime import date
from typing import Annotated
from pathlib import Path
from typing import Optional

import typer
import httpx
from tabulate import tabulate

from .config import get_settings
from .db import Base, engine, get_session
from .models import Article, ArticleSemesterStat, ArticleTheme, Question, Theme
from .question_builder import (
    QuestionPayload,
    build_question,
    ensure_semester_stat,
    ensure_article,
    ensure_theme,
    link_article_theme,
    upsert_semester_stat_from_series,
)
from .themes import THEMES
from .top_views_fetcher import TopViewsFetcher

app = typer.Typer(help="Service data Wikipédia - génération de questions basées sur les pageviews.")
settings = get_settings()


def iter_periods(start_year: int, end_year: int, end_semester_last_year: str = "S1") -> list[tuple[int, str]]:
    periods: list[tuple[int, str]] = []
    for year in range(start_year, end_year + 1):
        for sem in ("S1", "S2"):
            if year == end_year and end_semester_last_year == "S1" and sem == "S2":
                continue
            periods.append((year, sem))
    return periods


@app.command()
def init_db() -> None:
    """Créer les tables (schema data)."""
    typer.echo("Création des tables...")
    Base.metadata.create_all(bind=engine)
    typer.echo("OK.")


@app.command()
def seed(
    theme: Annotated[str, typer.Option("--theme", "-t")] = "Général",
    articles_file: Annotated[Optional[Path], typer.Option("--articles-file", "-f")] = None,
) -> None:
    """Seed d'un thème + quelques articles (titres FR)."""
    rows: list[str] = []
    if articles_file and articles_file.exists():
        with articles_file.open() as f:
            reader = csv.reader(f)
            for row in reader:
                if row:
                    rows.append(row[0].strip())
    elif settings.sample_articles_file:
        path = Path(settings.sample_articles_file)
        if path.exists():
            with path.open() as f:
                reader = csv.reader(f)
                for row in reader:
                    if row:
                        rows.append(row[0].strip())
    if not rows:
        rows = ["France", "Paris", "Football", "Jeu vidéo", "Cinéma", "Tesla", "Google", "OpenAI"]

    with get_session() as session:
        theme_obj = session.query(Theme).filter_by(name=theme).one_or_none()
        if not theme_obj:
            theme_obj = Theme(name=theme)
            session.add(theme_obj)
            session.flush()

        for title in rows:
            article = session.query(Article).filter_by(project=settings.wikimedia_project, slug=title.replace(" ", "_")).one_or_none()
            if not article:
                article = Article(project=settings.wikimedia_project, slug=title.replace(" ", "_"), title=title)
                session.add(article)
                session.flush()
            link = session.query(ArticleTheme).filter_by(article_id=article.id, theme_id=theme_obj.id).one_or_none()
            if not link:
                session.add(ArticleTheme(article=article, theme=theme_obj))

    typer.echo(f"Seed du thème '{theme}' avec {len(rows)} articles.")


@app.command()
def compute_stat(
    title: Annotated[str, typer.Argument(help="Titre d'article Wikipédia FR")],
    year: Annotated[int, typer.Option("--year", "-y")] = 2023,
    semester: Annotated[str, typer.Option("--semester", "-s", help="S1 ou S2")] = "S1",
) -> None:
    """Calcule et stocke les stats pageviews d'un article pour un semestre."""
    from .question_builder import ensure_article, ensure_theme
    from .wikimedia_client import WikimediaClient

    with get_session() as session:
        theme = ensure_theme(session, "Général")
        article = ensure_article(session, title, project=settings.wikimedia_project)
        link_article_theme(session, article, theme)
        client = WikimediaClient()
        stat = ensure_semester_stat(session, article, year, semester, client)
        typer.echo(f"Stats {title} {year}-{semester} : total={stat.views_total}, avg={stat.views_avg_daily:.1f}")


@app.command()
def generate_question(
    theme: Annotated[str, typer.Option("--theme", "-t")] = "Général",
    year: Annotated[int, typer.Option("--year", "-y")] = date.today().year,
    semester: Annotated[str, typer.Option("--semester", "-s")] = "S1",
    articles: Annotated[Optional[str], typer.Option(help="Liste de titres séparés par des virgules")] = None,
) -> None:
    """Génère une question (4 articles) pour un thème/semestre."""
    articles_list = [a.strip() for a in articles.split(",")] if articles else None
    with get_session() as session:
        payload: QuestionPayload = build_question(
            session=session,
            theme_name=theme,
            year=year,
            semester=semester,
            articles=articles_list,
        )
        typer.echo(
            f"Question #{payload.id} - thème {payload.theme} {payload.year}-{payload.semester} avec {payload.articles}"
        )


@app.command()
def list_questions(limit: int = typer.Option(10, "-n")) -> None:
    """Liste les dernières questions générées."""
    with get_session() as session:
        qs = session.query(Question).order_by(Question.created_at.desc()).limit(limit).all()
        table = []
        for q in qs:
            table.append(
                {
                    "id": q.id,
                    "theme": q.theme.name,
                    "period": f"{q.year}-{q.semester}",
                    "articles": ", ".join(link.article.title for link in q.articles),
                    "status": q.status,
                }
            )
        typer.echo(tabulate(table, headers="keys"))


@app.command()
def generate_range(
    start_year: Annotated[int, typer.Option("--start-year", "-a")] = 2015,
    end_year: Annotated[int, typer.Option("--end-year", "-b")] = 2025,
    end_semester_last_year: Annotated[str, typer.Option("--end-semester-last-year", "-e")] = "S1",
    themes: Annotated[Optional[str], typer.Option(help="Liste de thèmes séparés par des virgules, sinon tous")] = None,
) -> None:
    """
    Génère des questions pour chaque thème/semestre sur la plage d'années.
    Utilise les articles déjà importés ; saute quand il n'y a pas assez d'articles.
    Les bornes peuvent aussi être pilotées via START_YEAR, END_YEAR, END_SEM_LAST.
    """
    start_year = int(os.getenv("START_YEAR", start_year))
    end_year = int(os.getenv("END_YEAR", end_year))
    end_semester_last_year = os.getenv("END_SEM_LAST", end_semester_last_year)

    theme_names = [t["name"] for t in THEMES] if not themes else [t.strip() for t in themes.split(",") if t.strip()]
    periods = iter_periods(start_year, end_year, end_semester_last_year)

    with get_session() as session:
        for year, sem in periods:
            for theme_name in theme_names:
                try:
                    payload: QuestionPayload = build_question(
                        session=session,
                        theme_name=theme_name,
                        year=year,
                        semester=sem,
                        articles=None,
                    )
                    typer.echo(f"OK question {payload.id} {theme_name} {year}-{sem}")
                except httpx.HTTPStatusError as exc:
                    typer.echo(f"Skip {theme_name} {year}-{sem}: HTTP {exc.response.status_code} {exc.request.url}")
                except Exception as exc:  # noqa: BLE001
                    typer.echo(f"Skip {theme_name} {year}-{sem}: {exc}")



@app.command()
def import_top(
    year: Annotated[int, typer.Option("--year", "-y")] = date.today().year,
    semester: Annotated[str, typer.Option("--semester", "-s")] = "S1",
    limit: Annotated[int, typer.Option("--limit", "-n", help="Nombre max d'articles par thème")] = 500,
) -> None:
    """
    Importe automatiquement les articles les plus vus sur le semestre pour chaque thème défini dans THEMES.

    Règle de matching : si le thème a des keywords, on garde les articles dont le titre contient au moins un keyword
    (case-insensitive). Si pas de keywords, on prend les top globaux.
    """
    year = int(os.getenv("YEAR", year))
    semester = os.getenv("SEMESTER", semester)
    limit = int(os.getenv("LIMIT", limit))

    fetcher = TopViewsFetcher()
    typer.echo(f"Téléchargement des tops {settings.wikimedia_project} pour {year}-{semester}...")
    aggregated = fetcher.fetch_semester_top(year, semester)
    typer.echo(f"{len(aggregated)} articles agrégés depuis les tops.")

    with get_session() as session:
        for theme_cfg in THEMES:
            name = theme_cfg["name"]
            keywords = [kw.lower() for kw in theme_cfg.get("keywords", []) if kw]
            theme = ensure_theme(session, name)

            matched: list[tuple[str, dict]] = []
            for title, data in aggregated.items():
                title_l = title.lower()
                if keywords:
                    if not any(kw in title_l for kw in keywords):
                        continue
                matched.append((title, data))

            matched.sort(key=lambda tup: tup[1]["views_total"], reverse=True)
            selected = matched[:limit]
            typer.echo(f"Thème '{name}': {len(selected)} articles retenus.")

            for title, data in selected:
                article = ensure_article(session, title, project=settings.wikimedia_project)
                link_article_theme(session, article, theme)
                upsert_semester_stat_from_series(
                    session,
                    article,
                    year,
                    semester,
                    data["series"],
                )

    typer.echo("Import terminé.")


@app.command()
def import_range(
    start_year: Annotated[int, typer.Option("--start-year", "-a")] = 2015,
    end_year: Annotated[int, typer.Option("--end-year", "-b")] = 2025,
    end_semester_last_year: Annotated[str, typer.Option("--end-semester-last-year", "-e")] = "S1",
    limit: Annotated[int, typer.Option("--limit", "-n", help="Nombre max d'articles par thème")] = 500,
) -> None:
    """
    Enchaîne les imports de tops sur une plage d'années (2015..2025 par défaut).
    Par défaut arrête à S1 pour la dernière année pour éviter les périodes incomplètes.
    Peut être piloté via les variables d'env START_YEAR, END_YEAR, END_SEM_LAST, LIMIT.
    """
    start_year = int(os.getenv("START_YEAR", start_year))
    end_year = int(os.getenv("END_YEAR", end_year))
    end_semester_last_year = os.getenv("END_SEM_LAST", end_semester_last_year)
    limit = int(os.getenv("LIMIT", limit))

    periods = iter_periods(start_year, end_year, end_semester_last_year)
    typer.echo(f"Import range {start_year}-{end_year} (fin {end_semester_last_year}), limit {limit}.")
    for year, sem in periods:
        typer.echo(f"== Import {year}-{sem} ==")
        import_top(year=year, semester=sem, limit=limit)


def main() -> None:  # pragma: no cover
    app()


if __name__ == "__main__":  # pragma: no cover
    main()
