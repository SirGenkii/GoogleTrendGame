# Wikipedia Popularity Battle

Service data Python (pageviews Wikimedia) + backend Laravel pour le jeu de classement d’articles Wikipédia.

Mon discord pour discuter et s'organiser : julienb1506

## Démarrage rapide
- Prérequis : Docker + Docker Compose, `make`, Python 3.x pour le venv local du service data.
- Lancer l’infra (DBs + Laravel + Nginx) : `make up`. Si Dockerfile modifié : `make rebuild`.
- Préparer le venv Python : `make install` (crée `.venv`, installe `scraper/requirements.txt`).
- Initialiser le service data (via venv, DB exposée en 5533) : `make data-init-db`.
- Importer les tops Wikipédia et classer par thème : `make data-import-top YEAR=2024 SEMESTER=S1 LIMIT=500` (variables passées en env).
- Import massif multi-années : `make data-import-range START_YEAR=2017 END_YEAR=2025 END_SEM_LAST=S1 LIMIT=500` puis `make data-generate-range` pour générer toutes les questions.
- Générer une question de test (pioche dans les articles disponibles) : `make data-question`.
- Préparer Laravel : `make game-migrate` puis `make game-key`.
- Accès HTTP : http://localhost:8080 (via Nginx → game-app).

## Service data (Python)
- Vars env : `scraper/.env.example` (`WIKI_DB_URL`, `WIKI_PROJECT`, `WIKI_USER_AGENT`, etc.). Les commandes Make exportent `WIKI_DB_URL=postgresql+psycopg2://data:data@localhost:5533/data` pour cibler le conteneur `data-db`.
- CLI Typer via venv : `make data-init-db|data-seed|data-question` (ou direct `source .venv/bin/activate` puis `python -m wiki_service.cli ...`).
- HTTP client : `httpx` vers Wikimedia Pageviews ; stockage Postgres via SQLAlchemy.
- Schéma : `themes`, `articles`, `article_semester_stats`, `questions`, `question_articles`.

## Backend Laravel
- `.env` déjà orienté Postgres (service `game-db`).
- Migrations prêtes : `games`, `players`, `rounds`, `answers`.
- Dockerfile PHP-FPM 8.2 avec `pdo_pgsql` ; front Nginx sur port 8080.
- API (connexion lecture directe sur `data-db`) :
  - `POST /api/games` (création + host player)
  - `POST /api/games/{code}/players` (join)
  - `POST /api/games/{code}/rounds` (démarrer un round, options theme/year/semester)
  - `POST /api/rounds/{id}/answers` (envoyer l’ordre des articles)
  - `GET /api/games/{code}` (état de la partie + rounds/players)

## Structure
- `docker/` : compose et config Nginx.
- `scraper/` : service Python `wiki_service` (config, DB, client Wikimedia, builder de questions).
- `game/laravel-app/` : squelette Laravel 12 + migrations/models jeu.
