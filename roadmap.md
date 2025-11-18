# Wikipedia Popularity Battle – Roadmap (FR)

Objectif : un jeu web (solo puis multi) où les joueurs s’affrontent sur la **popularité d’articles Wikipédia** (pageviews) sur des périodes données.

Les données viennent de l’API **Wikimedia Pageviews** sur **Wikipédia FR** uniquement pour la V1. Pas de scraping sauvage, uniquement des appels d’API + stockage en base.

Deux couches principales :
- **Service Python “data”** : récupère les stats de pageviews, calcule des moyennes par période (semestre), détecte des thèmes, prépare des “questions” jouables.
- **Jeu web Laravel** : consomme ces questions pré-calculées et gère le gameplay (classement, rounds, scores, futur multi).

---

## 0. Vision du projet

### 0.1. Concept général

- Créer un **party game** basé sur la popularité d’articles Wikipédia FR.
- Les joueurs doivent deviner / classer **quel article est le plus consulté** sur une période donnée.

### 0.2. MVP – Mode solo “classe 4 articles”

- On travaille uniquement avec **fr.wikipedia.org**.
- Une “question” =
  - un **thème** (ex. “Jeux vidéo”, “Cinéma”, “Foot”, “Tech”, “Pays”, “Personnalités françaises”…),
  - une **année**,
  - un **semestre** (`S1` = Jan–Jun, `S2` = Jul–Dec),
  - **4 articles** (Wikipédia FR) liés au thème.
- Pour chaque article, on connaît :
  - **moyenne de pageviews sur ce semestre** (ou total et moyenne, selon ce qu’on garde en DB).
- Le joueur voit les 4 titres d’articles + la période (année + semestre) + le thème et doit les **classer du moins au plus populaire**.
- Le backend calcule l’ordre réel à partir des moyennes et donne un score.

### 0.3. Étape suivante – Mode “Duel battle”

- Deux joueurs ou plus se rejoignent dans une room.
- Le serveur choisit :
  - un **thème**,
  - une **année** + un **semestre**.
- Chaque joueur choisit **un article du thème** (parmi ceux proposés ou en tapant son choix).
- On compare la **moyenne de pageviews** des articles sur ce semestre → celui qui a le plus gagne la manche.

---

## 1. Choix techniques & structure du repo

### 1.1. Stack globale

- **Docker** pour orchestrer tous les services.
- **Service data (Python)** :
  - Python 3.x
  - `httpx` ou `requests` pour appeler l’API Wikimedia Pageviews.
  - Client DB (PostgreSQL recommandé) + ORM (SQLAlchemy ou simple wrapper maison).
- **Jeu web** :
  - Laravel (PHP 8.2+)
  - DB (PostgreSQL ou MySQL/MariaDB – idéalement **PostgreSQL** pour tout le projet).
  - Front : Blade + un peu de JS pour l’UI (drag & drop, timers…).

> Recommandation : utiliser **PostgreSQL** pour les deux DB (data & game), soit deux bases séparées, soit un seul serveur avec deux schémas.

### 1.2. Arborescence cible

```text
project-root/
  roadmap.md
  docker/
    docker-compose.yml
    nginx/
      default.conf
  scraper/              # service data Python (Wikimedia Pageviews)
    pyproject.toml / requirements.txt
    src/
      wiki_service/
        __init__.py
        config.py
        models.py
        db.py
        wikimedia_client.py
        theme_builder.py
        question_builder.py
        cli.py
  game/
    laravel-app/        # code Laravel
      (structure standard)
```

---

## 2. Docker & environnement de dev

### 2.1. Docker Compose

Tâches :
- Créer `docker/docker-compose.yml` avec les services :
  - `data-db` (Postgres pour le service wiki).
  - `game-db` (Postgres pour le jeu).
  - `data-app` (conteneur Python).
  - `game-app` (PHP-FPM + Laravel).
  - `nginx` (reverse proxy pour le jeu web).
- Définir un réseau commun `wiki-battle-net`.
- Ajouter des volumes persistants pour les DB (`data-db-data`, `game-db-data`).
- Monter le code local dans les conteneurs de dev (`./scraper`, `./game/laravel-app`).

### 2.2. Dockerfile pour le service data (Python)

Tâches :
- Créer `scraper/Dockerfile` :
  - Base image Python 3.12 slim.
  - Installer les dépendances système minimales.
  - Copier `requirements.txt` / `pyproject.toml` + installer :
    - `httpx` ou `requests`
    - `psycopg2` / `asyncpg` / SQLAlchemy
    - `pydantic` (optionnel)
  - Point d’entrée : script CLI (ex: `python -m wiki_service.cli`).

### 2.3. Dockerfile pour Laravel

Tâches :
- Créer `game/laravel-app/Dockerfile` :
  - Base image PHP-FPM (8.2) avec extensions nécessaires (`pdo_pgsql`, `bcmath`, etc.).
  - Installer Composer.
  - Copier `composer.json`, `composer.lock` puis `composer install`.
  - Monter le code en volume en dev.
- Nginx :
  - Créer `docker/nginx/default.conf` pour router le trafic vers PHP-FPM Laravel.

---

## 3. Base de données – Modèle de données

### 3.1. DB data (data-db) – Wikipédia & pageviews

Objectif : stocker les articles FR, les thèmes, et les stats agrégées par période (semestre).

**Contraintes données par le projet :**  
- On veut stocker pour chaque entrée :
  - nom de l’article,
  - URL,
  - année,
  - période (`S1` / `S2`),
  - moyenne de pageviews sur la période.

Tables proposées (schéma indicatif) :

- `wiki_articles`
  - `id`
  - `title` (ex: `Zinédine Zidane`)
  - `slug` (ex: `Zin%C3%A9dine_Zidane`, tel qu’utilisé par l’API)
  - `url` (ex: `https://fr.wikipedia.org/wiki/Zin%C3%A9dine_Zidane`)
  - `created_at`, `updated_at`

- `themes`
  - `id`
  - `slug` (ex: `sport`, `cinema`, `pays`)
  - `label` (ex: `Sport`, `Cinéma`, `Pays`)

- `article_themes`
  - `id`
  - `article_id`
  - `theme_id`

- `article_semester_stats`
  - `id`
  - `article_id`
  - `year` (INT, ex: 2023)
  - `semester` (SMALLINT: 1 ou 2)
  - `views_total` (BIGINT) – somme des pageviews sur le semestre
  - `views_avg_daily` (FLOAT) – moyenne par jour sur le semestre
  - `days_count` (INT) – nombre de jours réellement comptés
  - `source` (STRING, ex: `wikimedia_pageviews_api`)
  - `created_at`

> Le “top 500” d’articles par thème pour une période donnée sera dérivé d’une requête sur `article_semester_stats` + `article_themes`.

### 3.2. DB du jeu (game-db)

Objectif : servir le jeu sans dépendre des appels API en temps réel.

Tables proposées :

- `questions`
  - `id`
  - `theme_slug`
  - `year`
  - `semester` (1 ou 2)
  - `source_type` (ex: `wiki_semester_stats`)
  - `created_from_data_batch_id` (nullable: référence logique à un batch de génération si besoin)
  - `created_at`

- `question_articles`
  - `id`
  - `question_id`
  - `article_title`
  - `article_url`
  - `article_id` (nullable: référence à `data-db.wiki_articles` si on veut garder le lien)
  - `views_avg_daily` (FLOAT)
  - `views_total` (BIGINT)
  - (optionnel) `series` (JSON: pour garder la série journalière si on veut afficher un graphe plus tard)

- `games`
  - `id`
  - `code` (code de room à partager)
  - `status` (`waiting`, `running`, `finished`)
  - `current_round_id` (nullable)
  - `created_at`, `updated_at`

- `players`
  - `id`
  - `game_id`
  - `name` (pseudo)
  - `session_token` (pour identifier le joueur côté front)
  - `score` (score total sur la partie)
  - `created_at`, `updated_at`

- `rounds`
  - `id`
  - `game_id`
  - `question_id`
  - `round_number`
  - `status` (`waiting_answers`, `revealed`)
  - `deadline_at` (fin du chrono côté serveur)
  - `created_at`, `updated_at`

- `answers`
  - `id`
  - `round_id`
  - `player_id` (nullable pour le mode solo si on veut stocker quand même)
  - `ordered_question_article_ids` (JSON : liste des `question_articles.id` dans l’ordre proposé)
  - `score` (score obtenu sur cette manche)
  - `submitted_at`

---

## 4. Service Python – Pageviews & génération de questions

### 4.1. Setup du projet Python

Tâches :
- Initialiser le projet (`pyproject.toml` ou `requirements.txt`).  
- Créer `wiki_service/config.py` pour gérer :
  - URL de l’API Wikimedia REST,
  - nom du projet wiki (`fr.wikipedia`),
  - paramètres par défaut (dates, taux de retry, etc.).
- Créer `wiki_service/db.py` pour la connexion DB (SQLAlchemy ou wrapper psycopg2).
- Créer `wiki_service/wikimedia_client.py` :
  - Fonctions clés :
    - `fetch_pageviews_per_article(article_slug, start_date, end_date)` → retourne la série journalière.
    - (éventuellement) `fetch_top_articles_by_day(date)` → pour découvrir les articles les plus vus.

### 4.2. Seed des thèmes & découverte des articles populaires

Objectif : avoir quelques **thèmes principaux** et jusqu’à **500 articles par thème** comme base pour le MVP.

Stratégie possible V1 (simple) :
- Définir manuellement une liste de **thèmes** (sport, cinéma, jeux vidéo, pays, personnalités, etc.).
- Pour chaque thème, préparer un **fichier JSON** de “seed articles” avec :
  - titre Wikipédia FR,
  - slug (ou on le dérive depuis le titre).

Phase 2 (optionnelle, semi-auto) :
- Utiliser l’API Wikimedia pour récupérer les articles les plus vus sur certaines périodes (top pageviews).
- Filtrer / mapper ces articles vers des thèmes via :
  - catégories Wikipédia,
  - règles simples (regex/tags),
  - ou un petit script de tagging manuel assisté.

Tâches :
- Commande CLI : `seed_themes_and_articles` :
  - insère les `themes` dans `data-db.themes`,
  - insère les `wiki_articles` de base,
  - insère les `article_themes` pour les lier.

### 4.3. Calcul des stats par semestre

Tâches :
- Définir une fonction utilitaire pour construire les périodes :
  - `get_semester_range(year, semester)` → `(start_date, end_date)`.
- Créer une commande CLI : `compute_semester_stats` avec paramètres :
  - `--year` (ex: 2020–2024),
  - `--semester` (1 ou 2),
  - `--theme` (optionnel) ou `--all-themes`,
  - `--limit-per-theme` (par ex. max 500 articles).

Processus :
1. Pour un thème donné :
   - récupérer la liste d’articles liés au thème (avec éventuellement un limit).
2. Pour chaque article :
   - appeler `fetch_pageviews_per_article` avec le range du semestre.
   - calculer :
     - `views_total` (somme des vues),
     - `views_avg_daily` (total / nb de jours non nuls),
     - `days_count`.
3. Écrire/mettre à jour une entrée dans `article_semester_stats`.

Important :
- Gérer les erreurs réseau / 404 (article inexistant, renommé…).  
- Mettre un **rate limit** raisonnable sur l’API (sleep entre les calls, éventuellement parallélisme limité).

### 4.4. Construction des “questions” pour le jeu

Objectif : transformer les stats agrégées en **questions jouables** pour le mode solo “classe 4 articles”.

Tâches :
- Créer `wiki_service/question_builder.py` avec une commande CLI : `build_questions_for_game` :
  - Paramètres :
    - `--year-range` (ex: 2018-2024),
    - `--themes` (liste ou `--all`),
    - `--questions-per-theme` (ex: 100).
- Processus pour chaque thème/année/semestre :
  1. Récupérer tous les `article_semester_stats` correspondant (pour la période + thème).
  2. Filtrer :
     - retirer les articles avec `views_total` trop faibles.
     - éviter les articles dupliqués / très similaires (optionnel pour plus tard).
  3. Tirer des groupes de 4 articles aléatoirement pour construire une question.
  4. Pour chaque question :
     - insérer dans `game-db.questions` :
       - `theme_slug`, `year`, `semester`.
     - insérer dans `game-db.question_articles` les 4 articles associés avec :
       - `article_title`,
       - `article_url`,
       - `views_total`,
       - `views_avg_daily`.

> Ce script jouera le rôle d’ETL entre `data-db` et `game-db`.

---

## 5. Backend du jeu – Laravel

### 5.1. Setup Laravel

Tâches :
- Initialiser Laravel dans `game/laravel-app`.
- Configurer `.env` pour se connecter à `game-db` (service Docker `game-db`).  
- Créer les migrations pour toutes les tables de la section 3.2 (`questions`, `question_articles`, `games`, `players`, `rounds`, `answers`).
- Lancer les migrations dans le conteneur.

### 5.2. Logique métier – services & modèles

Tâches :
- Créer les modèles Eloquent correspondants.
- Créer un service `GameService` qui gère :
  - Création de partie (`createGame`).
  - Ajout de joueur (`joinGame`) – même si le MVP est solo, prévoir déjà une structure simple (un player par game).
  - Démarrage d’une nouvelle manche (`startRound`) :
    - Choisir une **question** aléatoire parmi celles disponibles (par thème / année / semestre ou full random).
    - Créer un `round` avec une `deadline_at` (ex: 30s).
  - Réception de la réponse (`submitAnswer`) :
    - vérifier que le round est en statut `waiting_answers` et que `now()` <= `deadline_at`.
    - enregistrer `ordered_question_article_ids`.
  - Clôture d’une manche (`closeRound`) :
    - calculer l’ordre réel des articles (tri par `views_avg_daily` ou `views_total`).
    - calculer un score :
      - ex : +1 par article à la bonne place, +bonus si ordre parfait.
    - mettre à jour `players.score` pour ce game.

### 5.3. API / Routes principales

Tâches :
- Routes HTTP / API JSON (V1 simple) :
  - `POST /games` : créer une partie, renvoyer un `game_code` + données de base.
  - `POST /games/{code}/join` : rejoindre une partie avec un pseudo (pour le futur multi).
  - `POST /games/{code}/start` : démarrer la partie / première manche.
  - `GET /games/{code}` : récupérer l’état du jeu (joueurs, round en cours, deadline, etc.).
  - `POST /games/{code}/rounds/{round}/answer` : envoyer l’ordre proposé par le joueur.
  - `GET /games/{code}/rounds/{round}` : récupérer les résultats (ordre réel, scores).

> Pour le MVP solo, un seul “joueur” par game peut suffire, mais garder ces endpoints génériques rend la transition vers le multi plus facile.

### 5.4. Gestion du chrono côté serveur

Tâches :
- Dans `startRound`, fixer `deadline_at = now() + X secondes`.
- Dans `submitAnswer`, refuser / ignorer la réponse si `now() > deadline_at`.
- Pour l’affichage du compte à rebours côté front :
  - exposer `deadline_at` dans les réponses de `GET /games/{code}` et `GET /games/{code}/rounds/{round}`.
  - le front calcule `remaining_seconds = deadline_at - now_client`.

---

## 6. Frontend – UI minimaliste (MVP)

### 6.1. Pages minimales

Tâches :
- **Page d’accueil** :
  - Bouton “Lancer une partie solo” → `POST /games`, puis redirection vers la room.
  - (Plus tard) champ pour rejoindre une partie multijoueur.

- **Page “Room / Partie”** :
  - Afficher :
    - thème,
    - année + semestre,
    - les 4 titres d’articles Wikipédia,
    - la période clairement (ex: “Janvier–Juin 2021”).
  - UI pour **ordonner les 4 articles** :
    - drag & drop (lib JS légère) ou boutons ↑ / ↓.
  - Compte à rebours (affiché à partir de `deadline_at`).

### 6.2. Page “Résultats de la manche”

Tâches :
- Afficher :
  - l’ordre réel des articles (du moins au plus populaire selon `views_avg_daily` ou `views_total`),
  - les valeurs de pageviews (optionnel pour V1, mais utile pour le fun),
  - le score obtenu sur cette manche,
  - le score cumulé (pour une partie avec plusieurs manches).
- Bouton “Manche suivante” → nouvel appel à `startRound`.

> V1 : rafraîchissement en **polling simple** sur `GET /games/{code}` toutes les X secondes.

---

## 7. Temps réel & améliorations UX (phase 2)

### 7.1. WebSockets (optionnel)

Tâches :
- Intégrer un système temps réel :
  - Pusher / Ably / Laravel WebSockets, etc.
- Événements :
  - joueur rejoint la partie,
  - manche commence,
  - manche se termine,
  - mise à jour des scores en live.

### 7.2. Visualisation des courbes de pageviews (optionnel)

Tâches :
- Utiliser une lib de graph JS (Chart.js/ApexCharts) pour afficher, dans l’écran de résultats :
  - la courbe journalière des pageviews pour chaque article sur le semestre.
- Nécessite : stocker une série journalière (ou mensuelle) dans `question_articles.series` ou la récupérer à la volée.

---

## 8. Features avancées (phase 2+)

### 8.1. Mode “Duel battle” (deux joueurs)

Tâches :
- Étendre la logique de `games` / `players` pour gérer plusieurs joueurs par partie.
- Mode de jeu :
  - le serveur choisit un thème + année + semestre.
  - chaque joueur choisit un article (parmi une liste de candidats du thème, ou via saisie texte avec auto-complétion).
  - backend récupère pour chaque article la `article_semester_stats` correspondante.
  - comparaison des `views_avg_daily` :
    - afficher le gagnant de la manche,
    - mettre à jour les scores cumulés.

### 8.2. Mode “freestyle”

Tâches :
- Permettre aux joueurs de taper un nom d’article librement (avec auto-complétion via API Wikipédia classique).
- Résoudre le titre vers un article FR exact (slug).
- Aller chercher les stats de pageviews si non déjà en DB :
  - soit via l’API (call live),
  - soit via un job asynchrone + cache.
- Comparer les volumes comme pour le duel.

### 8.3. Ajout d’autres langues / pays

Tâches :
- Étendre `wiki_articles` avec un champ `project` (ex: `fr.wikipedia`, `en.wikipedia`).
- Recalculer des stats par projet et éventuellement par pays si tu exploites des endpoints plus spécifiques.
- Permettre :
  - des thèmes multi-langues,
  - des modes “Choisis la langue avant de jouer” pour augmenter la difficulté.

---

## 9. Logging, monitoring & déploiement

### 9.1. Logs & observabilité

Tâches :
- Ajouter du logging structuré côté Python :
  - appels API Wikimedia,
  - stats calculées,
  - erreurs réseau.
- Logs côté Laravel :
  - création de parties,
  - erreurs de rounds,
  - exceptions.
- Garder un minimum de traces pour analyser les perfs et la qualité des questions.

### 9.2. Déploiement Docker

Tâches :
- Créer un `docker-compose.prod.yml` minimal :
  - conteneur Laravel (build optimisé, pas de montage de code en live),
  - conteneur Nginx,
  - conteneur DB (ou DB managée),
  - conteneur Python si tu veux continuer à lancer les jobs de génération sur la même infra.
- Gérer les secrets via variables d’environnement (.env, secrets Docker, etc.).
- Ajouter une doc `DEPLOY.md` simple expliquant :
  - comment builder les images,
  - comment lancer les migrations,
  - comment lancer les scripts de génération de questions.

---

## 10. Utilisation de cette roadmap avec Cursor

- Garder `roadmap.md` à la racine du projet.
- Utiliser des prompts ciblés du type :
  - “Implémente la section 2.1 de `roadmap.md` dans `docker/docker-compose.yml`.”
  - “Code les migrations Laravel pour les tables décrites en 3.2 de `roadmap.md`.”
  - “Crée le module Python `wikimedia_client` comme décrit en 4.1.”
  - “Implémente `compute_semester_stats` pour fr.wikipedia entre 2020 et 2024 comme dans 4.3.”
- Avancer par petits blocs : Docker → data service → génération de stats → ETL questions → backend Laravel → front minimaliste → améliorations.
