from datetime import datetime, timezone
from typing import List, Optional

from sqlalchemy import Column, DateTime, Integer, String, UniqueConstraint, ForeignKey, Float, JSON, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from .db import Base


class TimestampMixin:
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=lambda: datetime.now(timezone.utc)
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        default=lambda: datetime.now(timezone.utc),
        onupdate=lambda: datetime.now(timezone.utc),
    )


class Theme(Base, TimestampMixin):
    __tablename__ = "themes"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(255), unique=True, nullable=False)

    articles: Mapped[List["ArticleTheme"]] = relationship("ArticleTheme", back_populates="theme")
    questions: Mapped[List["Question"]] = relationship("Question", back_populates="theme")


class Article(Base, TimestampMixin):
    __tablename__ = "articles"
    __table_args__ = (UniqueConstraint("project", "slug", name="uq_project_slug"),)

    id: Mapped[int] = mapped_column(primary_key=True)
    project: Mapped[str] = mapped_column(String(50), default="fr.wikipedia", nullable=False)
    slug: Mapped[str] = mapped_column(String(255), nullable=False)
    title: Mapped[str] = mapped_column(String(255), nullable=False)
    page_id: Mapped[Optional[int]] = mapped_column(Integer, nullable=True)
    summary: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    image_url: Mapped[Optional[str]] = mapped_column(String(1024), nullable=True)

    themes: Mapped[List["ArticleTheme"]] = relationship("ArticleTheme", back_populates="article")
    stats: Mapped[List["ArticleSemesterStat"]] = relationship("ArticleSemesterStat", back_populates="article")
    question_links: Mapped[List["QuestionArticle"]] = relationship("QuestionArticle", back_populates="article")


class ArticleTheme(Base):
    __tablename__ = "article_themes"
    __table_args__ = (UniqueConstraint("article_id", "theme_id", name="uq_article_theme"),)

    id: Mapped[int] = mapped_column(primary_key=True)
    article_id: Mapped[int] = mapped_column(ForeignKey("articles.id"), nullable=False)
    theme_id: Mapped[int] = mapped_column(ForeignKey("themes.id"), nullable=False)

    article: Mapped[Article] = relationship("Article", back_populates="themes")
    theme: Mapped[Theme] = relationship("Theme", back_populates="articles")


class ArticleSemesterStat(Base, TimestampMixin):
    __tablename__ = "article_semester_stats"
    __table_args__ = (
        UniqueConstraint("article_id", "year", "semester", name="uq_article_semester"),
    )

    id: Mapped[int] = mapped_column(primary_key=True)
    article_id: Mapped[int] = mapped_column(ForeignKey("articles.id"), nullable=False)
    year: Mapped[int] = mapped_column(Integer, nullable=False)
    semester: Mapped[str] = mapped_column(String(2), nullable=False)  # S1 or S2
    views_total: Mapped[int] = mapped_column(Integer, nullable=False)
    views_avg_daily: Mapped[float] = mapped_column(Float, nullable=False)
    series: Mapped[Optional[dict]] = mapped_column(JSON, nullable=True)  # optional daily series for graphs

    article: Mapped[Article] = relationship("Article", back_populates="stats")


class Question(Base, TimestampMixin):
    __tablename__ = "questions"
    __table_args__ = (UniqueConstraint("theme_id", "year", "semester", name="uq_question_theme_period"),)

    id: Mapped[int] = mapped_column(primary_key=True)
    theme_id: Mapped[int] = mapped_column(ForeignKey("themes.id"), nullable=False)
    year: Mapped[int] = mapped_column(Integer, nullable=False)
    semester: Mapped[str] = mapped_column(String(2), nullable=False)
    status: Mapped[str] = mapped_column(String(20), default="ready", nullable=False)

    theme: Mapped[Theme] = relationship("Theme", back_populates="questions")
    articles: Mapped[List["QuestionArticle"]] = relationship("QuestionArticle", back_populates="question")


class QuestionArticle(Base):
    __tablename__ = "question_articles"
    __table_args__ = (UniqueConstraint("question_id", "article_id", name="uq_question_article"),)

    id: Mapped[int] = mapped_column(primary_key=True)
    question_id: Mapped[int] = mapped_column(ForeignKey("questions.id"), nullable=False)
    article_id: Mapped[int] = mapped_column(ForeignKey("articles.id"), nullable=False)
    views_total: Mapped[int] = mapped_column(Integer, nullable=False)
    views_avg_daily: Mapped[float] = mapped_column(Float, nullable=False)

    question: Mapped[Question] = relationship("Question", back_populates="articles")
    article: Mapped[Article] = relationship("Article", back_populates="question_links")
