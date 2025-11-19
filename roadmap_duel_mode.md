# Roadmap – Mode Duel (Wikipedia Popularity Battle)

Objectif : ajouter un **mode duel** à deux joueurs basé sur un **pool de 10 cartes “articles Wikipédia”**, avec phases de **pick / ban / pick**, petite phase d’ordonnancement des cartes, puis **4 batailles successives**.

Cette roadmap part du principe que le projet a déjà :
- un système de **rooms**,
- un MVP où plusieurs joueurs peuvent classer 4 articles Wikipédia,
- une base de données qui stocke les stats de popularité des articles par thème / année / semestre.

---

## 0. Contexte & contraintes du mode duel

### 0.1. Règles de haut niveau

- Une room en mode duel contient **exactement 2 joueurs**.
- Pour un duel, le serveur choisit :
  - un **thème**,
  - une **année**,
  - un **semestre** (S1 ou S2).
- À partir de ces paramètres, le serveur tire un **pool de 10 articles** (cartes) dans les meilleurs articles du thème / période.
- Phases du duel :
  1. **Pile ou face** pour déterminer qui est J1 et J2.
  2. **Draft / Ban** :
     - Round 1 (pick) : J1 pick, puis J2 pick.
     - Round 2 (pick) : J2 pick, puis J1 pick.
     - Round 3 (ban) : J1 ban, puis J2 ban.
     - Round 4 (pick) : J1 pick, puis J2 pick.
     - Round 5 (pick) : J2 pick, puis J1 pick.
     → Au final, chaque joueur a **4 cartes**, et il ne reste plus de cartes dans le pool.
  3. **Line-up & swaps** :
     - Chaque joueur voit ses 4 cartes dans un ordre initial.
     - Il peut effectuer des **swaps limités** :
       - Swap entre les positions 1 et 2.
       - Swap entre les positions 3 et 4.
     - Une fois satisfait, il valide son ordre.
  4. **Batailles** :
     - On joue **4 batailles** : carte 1 vs carte 1, 2 vs 2, 3 vs 3, 4 vs 4.
     - Pour chaque bataille :
       - On compare les stats de popularité (views sur le semestre) des deux articles.
       - Celui avec la valeur la plus haute gagne la bataille.
     - Le joueur qui gagne le plus de batailles remporte le duel (MVP : pas de tie-break, 2–2 = égalité ou départage à la somme des vues).

### 0.2. Informations visibles

- Pendant le **draft / ban** :
  - Tous les joueurs voient :
    - le **titre** de l’article,
    - une **image** (miniature) si disponible,
    - un **court extrait** du premier paragraphe.
  - Optionnel (plus tard) : un “tier” de puissance approximatif basé sur le rang (S/A/B/C), sans chiffres exacts.
- Quand un joueur a mis une carte dans sa “main” :
  - Il voit pour **ses cartes** :
    - les statistiques exactes de popularité (par ex. nombre moyen de vues par jour sur le semestre).
  - Il ne voit pas les stats des cartes de l’adversaire.

---

## 1. Modèle de données & état du duel

### 1.1. État global de la room

Tâches :
- Ajouter un champ `mode` ou équivalent pour distinguer :
  - `mode = "solo"` (classement de 4 articles existant),
  - `mode = "duel"`.
- Ajouter un champ `state` pour suivre les phases du duel, par exemple :
  - `waiting_players`
  - `coin_flip`
  - `draft_round_1`
  - `draft_round_2`
  - `draft_round_3_ban`
  - `draft_round_4`
  - `draft_round_5`
  - `lineup`
  - `battle_1`
  - `battle_2`
  - `battle_3`
  - `battle_4`
  - `finished`

### 1.2. Données spécifiques au duel

Tâches :
- Créer une structure (table ou équivalent) pour stocker les **candidats du pool** pour un duel :
  - `duel_id` ou `room_id`
  - `article_id` (référence à l’article en DB data ou copie locale)
  - `title`
  - `url`
  - `image_url` (thumbnail)
  - `excerpt` (court texte pour l’info-bulle)
  - `views_total` / `views_avg_daily` pour le semestre
  - `position_in_pool` (ordre d’affichage des cartes)
- Créer une structure pour les **picks/bans** :
  - `duel_id`
  - `player_id`
  - `article_id`
  - `action_type` (`pick` ou `ban`)
  - `round_number` (1 à 5)
- Créer une structure pour les **line-ups** :
  - `duel_id`
  - `player_id`
  - `slot_1_article_id`
  - `slot_2_article_id`
  - `slot_3_article_id`
  - `slot_4_article_id`
  - `is_validated` (booléen)
- Créer une structure pour les **résultats de bataille** :
  - `duel_id`
  - `battle_index` (1 à 4)
  - `article_id_player_1`
  - `article_id_player_2`
  - `winner_player_id` (nullable si égalité)
  - `views_1`
  - `views_2`

---

## 2. Backend – Logique métier du mode duel

### 2.1. Initialisation d’un duel

Tâches :
- Ajouter une fonction/service pour **créer une room en mode duel** :
  - choisir un thème, une année, un semestre (aléatoirement ou selon paramètres).
  - charger depuis la DB data une liste d’articles du thème/période (top N par `views_total` ou `views_avg_daily`).
  - sélectionner aléatoirement 10 articles distincts pour le pool du duel.
  - récupérer / copier les champs nécessaires :
    - titre, url, stats, image, excerpt.
  - initialiser le `state` de la room à `waiting_players`.

### 2.2. Gestion des joueurs & pile ou face

Tâches :
- Dans une room en mode duel :
  - restreindre à **2 joueurs maximum**.
  - quand 2 joueurs sont présents, passer en state `coin_flip`.
- Implémenter la logique de **pile ou face** :
  - choisir aléatoirement qui est `player_1` et `player_2`.
  - stocker cet ordre dans la room / duel (important pour l’ordre des picks).
  - passer automatiquement en `draft_round_1`.

### 2.3. Phase draft / ban

Tâches :
- Implémenter un gestionnaire de **rounds de draft** qui :
  - sait, pour chaque `state` de type `draft_round_x` :
    - quel joueur doit agir en premier,
    - combien de cartes il doit `pick` ou `ban`,
    - si on enchaîne J1 puis J2, ou J2 puis J1.
  - vérifie que l’article choisi est toujours disponible dans le pool (non déjà pick / ban).
  - enregistre l’action (`pick` ou `ban`) dans la structure dédiée.
  - met à jour la “disponibilité” des cartes dans le pool.
- Après chaque round :
  - vérifier si toutes les actions requises sont faites (ou la fin du timer),
  - passer au `state` suivant.

### 2.4. Phase line-up & swaps

Tâches :
- Une fois le dernier round de pick terminé, chaque joueur doit avoir **4 articles**.
- Définir la line-up initiale :
  - soit dans l’ordre des picks,
  - soit mélangée aléatoirement.
- En `state = lineup` :
  - accepter des actions de type `swap` pour un joueur :
    - swap entre slots 1 et 2,
    - swap entre slots 3 et 4.
  - autoriser plusieurs swaps tant que la line-up n’est pas validée.
  - action `validate_lineup` pour verrouiller l’ordre.
- Quand les 2 joueurs ont `is_validated = true` ou à la fin du timer :
  - s’assurer qu’il y a bien 4 cartes dans 4 slots distincts pour chaque joueur,
  - passer à `battle_1`.

### 2.5. Phase bataille (4 rounds)

Tâches :
- Pour chaque `battle_index` de 1 à 4 :
  - récupérer l’article de J1 dans le slot correspondant,
  - récupérer l’article de J2 dans le slot correspondant,
  - récupérer leurs stats (`views_total` ou `views_avg_daily`),
  - déterminer un **gagnant** (ou une égalité) :
    - si `views_1 > views_2` → J1 gagne,
    - si `views_2 > views_1` → J2 gagne,
    - sinon égalité.
  - stocker le résultat dans la structure des résultats de bataille.
  - incrémenter les compteurs de victoires par joueur au niveau du duel.
  - passer le `state` de `battle_i` à `battle_{i+1}` ou `finished` après la 4ème bataille.
- MVP : si 2–2 après 4 battles :
  - considérer le duel comme match nul (ou utiliser la somme des views des 4 cartes comme tie-break si souhaité).
- Mettre à disposition, dans l’API, toutes les infos nécessaires au front pour animer chaque bataille :
  - titres,
  - images,
  - extrait,
  - valeurs brutes des stats par article,
  - info sur le gagnant/perdant.

---

## 3. Timers & gestion AFK

### 3.1. Timers par phase

Tâches :
- Définir un **temps maximum** pour chaque phase clé :
  - `draft` : ex. 20–30 secondes par pick/ban.
  - `lineup` : ex. 20–30 secondes pour organiser ses cartes.
- Côté backend, pour chaque room en mode duel :
  - stocker un `phase_deadline` (timestamp) quand on entre dans une nouvelle phase.
  - sur chaque action reçue, vérifier que `now <= phase_deadline`.
  - ajouter un mécanisme (cron, job périodique, ou boucle interne) pour :
    - détecter les rooms où la phase est expirée,
    - effectuer l’action par défaut (auto-pick, auto-ban, conserver ordre actuel pour lineup, etc.),
    - passer à la phase suivante.

### 3.2. Comportement AFK

Tâches :
- Si un joueur est inactif pendant un draft / ban :
  - choisir aléatoirement une carte valide dans le pool pour lui (pick ou ban).
- Si un joueur est inactif pendant la line-up :
  - conserver l’ordre courant de ses cartes (sans swaps supplémentaires).
- Si un joueur quitte définitivement la room avant la fin du duel :
  - définir une règle simple :
    - soit déclarer l’autre joueur gagnant par forfait,
    - soit marquer le duel comme annulé.

---

## 4. API / endpoints et payloads front

### 4.1. Endpoints à prévoir (sans entrer dans le code)

Tâches :
- Ajouter des routes spécifiques ou des actions pour le mode duel, par exemple :
  - `POST /duels` : créer une room en mode duel (ou utiliser `/rooms` avec un paramètre de mode).
  - `POST /duels/{id}/join` : rejoindre le duel.
  - `GET /duels/{id}/state` : récupérer l’état courant du duel (phase, pool, picks, lineups, batailles).
  - `POST /duels/{id}/pick` : envoyer une action de pick.
  - `POST /duels/{id}/ban` : envoyer une action de ban.
  - `POST /duels/{id}/swap` : envoyer une demande de swap (avec info sur les slots 1–2 ou 3–4).
  - `POST /duels/{id}/validate-lineup` : valider l’ordre des cartes.
- Les réponses de `GET /duels/{id}/state` doivent inclure :
  - `mode`, `state`, `phase_deadline`,
  - pour le joueur courant :
    - ses cartes (avec stats visibles seulement pour ses articles),
    - les cartes du pool accessibles (avec snippet + image, sans stats),
    - ses actions déjà effectuées (picks/bans/lineup),
  - information minimale sur l’adversaire (pseudo, nombre de cartes pick, nombre de cartes restantes, etc.),
  - en phase bataille :
    - articles en face-à-face,
    - stats prêtes à être animées,
    - résultat de la bataille courante / passées.

---

## 5. Frontend – UX & écrans

### 5.1. Écran de draft / ban

Tâches :
- Afficher les **10 cartes** (articles) du pool pour les deux joueurs :
  - titre,
  - image,
  - court extrait (au survol ou clic).
- Mettre en évidence :
  - quelles cartes sont déjà pickées,
  - quelles cartes sont bannies,
  - quelles cartes restent disponibles.
- Montrer clairement :
  - à qui est le tour (J1 ou J2),
  - ce qui est attendu : pick ou ban,
  - le temps restant (compte à rebours basé sur `phase_deadline`).

### 5.2. Écran de line-up

Tâches :
- Afficher les **4 cartes du joueur** dans l’ordre courant des slots 1–4.
- Permettre d’effectuer des swaps :
  - boutons ou gestures pour swap 1 ↔ 2 et 3 ↔ 4.
- Afficher, pour chaque carte du joueur :
  - les stats de popularité (par exemple vues totales sur le semestre).
- Bouton “Valider mon ordre”.
- Afficher un timer pour la fin de la phase.

### 5.3. Écran de bataille

Tâches :
- Pour chaque bataille (1 à 4) :
  - Afficher les **deux cartes face à face** (gauche = J1, droite = J2) :
    - image,
    - titre,
    - extrait.
  - Préparer une zone pour l’animation des “barres de puissance” :
    - barres qui se remplissent jusqu’à un pourcentage basé sur les stats.
  - À la fin de l’animation :
    - afficher les valeurs exactes (nombre de vues),
    - mettre en surbrillance le gagnant (effets visuels).
- Afficher un **récapitulatif** à la fin :
  - nombre de batailles gagnées par chaque joueur,
  - résultat global du duel (victoire / défaite / nul).

---

## 6. MVP et évolutions

### 6.1. MVP immédiat

Pour le MVP demandé :
- Gérer :
  - création d’une room en mode duel,
  - pool de 10 cartes,
  - phases :
    - coin flip,
    - pick / ban / pick,
    - line-up + swaps,
    - 4 batailles,
  - une seule manche de duel (pas de série de duels).
- Pas de tie-break complexe :
  - 2–2 = match nul **ou** départage simple à la somme des vues des 4 cartes (au choix, à fixer dans la logique métier).

### 6.2. Idées pour plus tard (optionnel, à noter seulement)

- Tie-break avec une dernière carte unique si 2–2.
- Ajout de “tiers” (S/A/B/C) affichés sur les cartes durant le draft.
- Effets visuels plus poussés pendant les batailles (crits, “victoire écrasante”, etc.).
- Classement global des joueurs basé sur leurs victoires en mode duel.
- Filtre de qualité pour améliorer la sélection du pool (éviter des pages peu intéressantes).

---

## 7. Utilisation avec Cursor

Exemples de prompts pour Cursor :
- “Implémente la gestion des états `state` de la room en suivant la section 1.1 de `roadmap_duel_mode.md`.”
- “Ajoute les structures de données décrites en 1.2 dans la couche backend pour le mode duel.”
- “Crée les endpoints nécessaires pour le mode duel comme listés en 4.1, sans toucher au mode solo existant.”
- “Mets en place l’UI de draft / ban en suivant la section 5.1 de `roadmap_duel_mode.md`.”
- “Ajoute les timers et la gestion AFK comme décrit en 3.1 et 3.2.”

L’idée est d’implémenter progressivement :
1) les états & modèles backend,  
2) les endpoints API,  
3) l’UI draft → lineup → bataille,  
4) les timers et fallback AFK,  
5) les petits raffinements de feedback visuel.

---

## 8. Plan technique d’implémentation

### 8.1. Phase 1 – Fondations data & modèle

- Étendre les migrations Laravel existantes (`game/laravel-app/database/migrations`) :
  - ajouter les colonnes `mode`, `state`, `phase_deadline`, `player_1_id`, `player_2_id` sur `games` (ou table dédiée `duels` si séparation souhaitée),
  - créer les tables décrites en 1.2 (`duel_pool_articles`, `duel_actions`, `duel_lineups`, `duel_battles`) avec clés étrangères (`game_id`, `player_id`, `article_id` venant du service data ou copie locale).
- Préparer les modèles Eloquent et les relations nécessaires (Game ↔ DuelPoolArticle, Game ↔ DuelAction, etc.) pour faciliter les sérialisations de l’API.
- Côté service data (`scraper/`), exposer une fonction utilitaire qui renvoie le top N d’un thème/période (réutilise la logique des questions actuelles) afin que Laravel puisse interroger la base `data` via son DAO ou via une requête SQL brute.
- Ajouter des factories/seeders minimaux pour simuler 10 cartes et deux joueurs, ce qui permettra d’écrire des tests de service sans dépendre du CLI Python.

### 8.2. Phase 2 – Services métier duel

- Créer un service `DuelService` (ou use-case) dans Laravel qui encapsule :
  - la création du pool (tirage aléatoire des 10 articles, copie des champs vitaux, persistés dans `duel_pool_articles`),
  - la machine à états (transition `waiting_players → coin_flip → … → finished`) avec validation métier,
  - les actions utilisateur (`pick`, `ban`, `swap`, `validate_lineup`) avec vérifications de disponibilité.
- Mettre en place des jobs/commands pour :
  - lancer automatiquement la transition `coin_flip` dès que deux joueurs sont connectés,
  - détecter la fin d’un round de draft (toutes les actions faites ou timer écoulé) et avancer de phase,
  - calculer les résultats de bataille et mettre à jour les compteurs de victoire.
- Rétro-documenter les règles de validation (ex. contrôle qu’un joueur ne possède jamais plus de 4 cartes, qu’un article ne peut être pick + ban, etc.) via tests unitaires/feature.

### 8.3. Phase 3 – Exposition API & messages temps réel

- Étendre les routes Laravel (fichier `game/laravel-app/routes/api.php`) avec les endpoints listés en 4.1. Chaque handler doit :
  - contrôler le `mode` du jeu pour éviter les collisions avec le mode solo,
  - vérifier l’ordre des joueurs/picks conformément à l’état courant,
  - renvoyer le payload `GET /duels/{id}/state` structuré (pool complet côté spectateur, cartes + stats côté joueur courant, progression des batailles).
- Ajouter des Events/Broadcasts Laravel pour pousser en temps réel :
  - changement de phase (draft → lineup → battles),
  - nouvelle action pick/ban,
  - validation de lineup,
  - résultats d’une bataille.
- Prévoir une rétrocompatibilité web-socket optionnelle : si Echo/websocket indisponible, le front peut fallback sur un polling court (ex. rafraîchissement via `GET /duels/{id}/state` toutes les 2 s).

### 8.4. Phase 4 – Intégration frontend

- Créer un layout "Duel" dans `game/laravel-app/resources/views` ou dans le front JS si un framework est branché. Points clés :
  - écran de lobby avec état `waiting_players`, affichage du compte à rebours `coin_flip`,
  - composant de draft : vue grille des 10 cartes, timeline des rounds, panneau d’action contextualisé (pick ou ban) et retour visuel sur les cartes prises,
  - composant de lineup : liste des 4 cartes (avec stats visibles) + boutons de swap 1↔2 et 3↔4 + validation,
  - composant bataille : animation vs, affichage stats, scoreboard cumulatif.
- Mutualiser les composants UI (cards, timers, indicateurs de tour) pour faciliter les évolutions ultérieures (ex : duel BO3).
- Relier chaque action UI à l’endpoint adéquat, gérer les erreurs métiers (ex. tentative de pick hors tour) et verrouiller les boutons tant que la réponse serveur n’est pas revenue.

### 8.5. Phase 5 – Timers, AFK & robustesse

- Implémenter dans Laravel un scheduler (ex. `php artisan schedule:work` dans le conteneur) ou un job en boucle relié au worker pour vérifier les `phase_deadline`.
- Pour chaque phase expirée :
  - déclencher l’action auto (pick/ban aléatoire, lineup figée, duel arrêté si joueur absent),
  - notifier les deux clients (broadcast + mise à jour d’état).
- Ajouter de la persistance des logs (table `duel_events` ou logs applicatifs) pour pouvoir rejouer/observer une partie en cas de bug.
- Couvrir les scénarios critiques par des tests :
  - `Feature`: enchaînement complet d’un duel avec deux joueurs simulés,
  - `Unit`: validation des transitions d’état, auto-pick AFK, calcul des batailles.
- Mettre en place une checklist de monitoring (métriques sur le nombre de duels actifs, taux d’abandon, durée moyenne d’une phase) pour vérifier la santé du mode duel après déploiement.

### 8.6. Phase 6 – Durcissement & évolutions

- Ajouter des garde-fous :
  - nettoyage périodique des duels `finished` ou `aborted`,
  - mécanisme de reprise (un joueur qui se reconnecte revoit son état exact).
- Préparer les hooks pour les idées de 6.2 (tiers, tie-break spécial, classement) en gardant des colonnes extensibles (`metadata` JSON sur les articles du pool, table `duel_stats` par joueur).
- Documenter le flux complet (diagramme de séquence) et ajouter un guide opérateur pour lancer un duel en local (commandes Make + endpoints à appeler) afin de faciliter les futures contributions.
