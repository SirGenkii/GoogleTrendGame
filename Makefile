COMPOSE = docker compose -f docker/docker-compose.yml
VENV = .venv
PYTHON = $(VENV)/bin/python
DATA_ENV = WIKI_DB_URL=postgresql+psycopg2://data:data@localhost:5533/data PYTHONPATH=scraper/src
YEAR ?= $(shell date +%Y)
SEMESTER ?= S1
LIMIT ?= 500
START_YEAR ?= 2016
END_YEAR ?= 2025
END_SEM_LAST ?= S1

.PHONY: up down rebuild logs ps install activate data-init-db data-seed data-question data-import-top data-import-range data-generate-range data-shell game-shell game-migrate game-key reset-db

up:
	$(COMPOSE) up -d --build

down:
	$(COMPOSE) down

rebuild:
	$(COMPOSE) up -d --build --force-recreate

logs:
	$(COMPOSE) logs -f

ps:
	$(COMPOSE) ps

install: $(PYTHON)

$(PYTHON):
	python3 -m venv $(VENV)
	$(PYTHON) -m pip install --upgrade pip
	$(PYTHON) -m pip install -r scraper/requirements.txt

activate: $(PYTHON)
	@echo "Activating venv... (Ctrl-D to exit)"
	@bash -lc "source $(VENV)/bin/activate && exec $$SHELL"

data-init-db: $(PYTHON)
	$(DATA_ENV) $(PYTHON) -m wiki_service.cli init-db

data-upgrade-db: $(PYTHON)
	$(DATA_ENV) $(PYTHON) -m wiki_service.cli upgrade-db

data-seed: $(PYTHON)
	$(DATA_ENV) $(PYTHON) -m wiki_service.cli seed

data-question: $(PYTHON)
	$(DATA_ENV) $(PYTHON) -m wiki_service.cli generate-question

data-import-top: $(PYTHON)
	YEAR=$(YEAR) SEMESTER=$(SEMESTER) LIMIT=$(LIMIT) $(DATA_ENV) $(PYTHON) -m wiki_service.cli import-top

data-import-range: $(PYTHON)
	START_YEAR=$(START_YEAR) END_YEAR=$(END_YEAR) END_SEM_LAST=$(END_SEM_LAST) LIMIT=$(LIMIT) $(DATA_ENV) $(PYTHON) -m wiki_service.cli import-range

data-generate-range: $(PYTHON)
	START_YEAR=$(START_YEAR) END_YEAR=$(END_YEAR) END_SEM_LAST=$(END_SEM_LAST) $(DATA_ENV) $(PYTHON) -m wiki_service.cli generate-range

data-shell:
	$(COMPOSE) run --rm data-app bash

data-db-reset:
	$(COMPOSE) stop data-app
	$(COMPOSE) stop data-db
	$(COMPOSE) rm -f data-db
	$(COMPOSE) up -d data-db
	sleep 2
	$(MAKE) data-init-db
	$(MAKE) data-upgrade-db

game-shell:
	$(COMPOSE) run --rm game-app bash

game-migrate:
	$(COMPOSE) run --rm game-app php artisan migrate

game-key:
	$(COMPOSE) run --rm game-app php artisan key:generate

reset-db:
	$(COMPOSE) down -v
