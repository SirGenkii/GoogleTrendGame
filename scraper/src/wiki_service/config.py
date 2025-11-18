from functools import lru_cache
from typing import Optional

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=(".env", ".env.local", ".env.example"), env_prefix="WIKI_")

    db_url: str = Field(
        default="postgresql+psycopg2://data:data@data-db:5432/data",
        env="DB_URL",
    )
    log_level: str = Field(default="INFO", alias="LOG_LEVEL")
    wikimedia_project: str = Field(default="fr.wikipedia", alias="PROJECT")
    user_agent: str = Field(
        default="WikiPopBattle/0.1 (contact@example.com)",
        alias="USER_AGENT",
    )
    default_start_year: int = Field(default=2020, alias="DEFAULT_START_YEAR")
    default_end_year: int = Field(default=2024, alias="DEFAULT_END_YEAR")
    batch_size: int = Field(default=50, alias="BATCH_SIZE")
    sample_articles_file: Optional[str] = Field(default=None, alias="SAMPLE_ARTICLES_FILE")


@lru_cache
def get_settings() -> Settings:
    return Settings()
