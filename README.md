# Observations — V0 personnelle

V0 Laravel 12 + Nuxt 4 pour chercher un taxon animal, estimer puis importer un volume raisonnable d’observations GBIF/iNaturalist, les conserver dans PostgreSQL/PostGIS et les afficher sur une carte MapLibre. L’audit initial reste disponible dans [docs/api-audit.md](docs/api-audit.md).

Le dépôt conserve Laravel à la racine et place Nuxt dans `front/`. Ce choix limite le déplacement du prototype audité tout en séparant clairement les deux chaînes de build.

## Démarrage Docker

Prérequis : Docker avec Compose.

Après la première installation, le backend de développement se lance avec :

```bash
./dev
```

Cette commande démarre PostgreSQL, Laravel, deux workers de queue en parallèle, le scheduler et le
worker Faune-France local. Le worker reste actif en arrière-plan et empêche les
imports Faune-France de rester indéfiniment en `pending`.

```bash
./dev status       # état des conteneurs et du worker Faune-France
./dev logs         # logs systemd du worker Faune-France
./dev stop         # arrêt propre du worker et du backend
npm --prefix front run dev
```

```bash
cp .env.example .env
# Renseigner APP_KEY, FAUNE_FRANCE_TOKEN et FAUNE_FRANCE_BOT_TOKEN dans .env
docker compose build app
docker compose up -d postgres
docker compose run --rm app php artisan migrate --seed
docker compose up -d app queue scheduler front
```

Ouvrir `http://localhost:3000`. L’API est sur `http://localhost:8000`, PostgreSQL 17/PostGIS 3.5 dans le réseau Compose. Vérifier les services avec :

Le navigateur appelle l’API via le proxy Nuxt de même origine (`/api`) afin
d’éviter les contraintes CORS. Dans Compose, ce proxy joint Laravel sur
`http://app:8000/api`; la cible peut être surchargée avec `NUXT_API_BASE`.

```bash
docker compose ps
docker compose logs -f app queue scheduler front
```

## Démarrage sans Docker

Prérequis : PHP 8.4+ avec DOM/XML et PDO PostgreSQL, Composer, Node 22, npm et PostgreSQL avec PostGIS. Sur Ubuntu : `sudo apt install php-xml` (ou le paquet versionné correspondant, par exemple `php8.5-xml`).

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
php artisan queue:work --sleep=2 --tries=2 --timeout=900
php artisan schedule:work
cd front
npm install
npm run dev
```

Les quatre processus Laravel, queue, scheduler et Nuxt doivent tourner dans des terminaux distincts. Aucune instance Redis n’est requise : la queue utilise PostgreSQL.

## Tests

Les tests automatisés utilisent SQLite en mémoire et uniquement des réponses HTTP simulées :

```bash
composer test
cd front
npm run typecheck
npm run build
```

Les smoke tests réseau restent manuels, très limités et ne doivent pas être intégrés à la CI :

```bash
php artisan biodiversity:test-source gbif
php artisan biodiversity:test-source inaturalist
php artisan biodiversity:test-all
php artisan biodiversity:count --source=gbif --taxon="Tichodroma muraria" --from=2026-01-01 --to=2026-07-01 --country=FR
```

## Premier parcours

1. Ouvrir `/exploration`, saisir `Tichodroma muraria` et choisir le résultat.
2. Choisir une période, un point/rayon ou plusieurs des 101 départements, puis GBIF/iNaturalist.
3. Cliquer sur **Estimer**. Les totaux local, GBIF, iNaturalist et l’approximation du recouvrement s’affichent.
4. Confirmer le petit import. Suivre sa progression dans `/imports`, puis ouvrir la recherche enregistrée dans `/recherches` pour consulter sa liste et sa carte.
   Chaque popup propose ensuite « Voir le détail de l’observation ».
5. Dans `/surveillances/nouvelle`, créer une règle. La synchronisation manuelle est disponible sur `/surveillances`; le scheduler traite ensuite les échéances.

Créer d’abord une collection permanente par API si les résultats doivent survivre au nettoyage :

```bash
curl -X POST http://localhost:8000/api/collections -H 'Content-Type: application/json' -d '{
  "name":"Tichodromes Rennes","taxon_id":1,"date_from":"2026-01-01","date_to":"2026-07-21",
  "zone":{"type":"radius","latitude":48.1173,"longitude":-1.6778,"radius_km":30},
  "sources":["gbif","inaturalist"],"is_permanent":true
}'
```

## Entrée et worker Faune-France

Un outil externe authentifié peut envoyer directement un objet ou un tableau. L’identifiant source rend l’opération idempotente.

```bash
curl -X POST http://localhost:8000/api/biodiversity/faune-france/occurrences \
  -H 'Authorization: Bearer replace-with-a-local-secret' \
  -H 'Content-Type: application/json' \
  -d '{"source_occurrence_id":"external-123","scientific_name":"Tichodroma muraria","observed_at":"2026-07-20T08:00:00Z","latitude":48.1,"longitude":-1.7}'
```

La réponse `202` indique les nombres `created`, `updated`, `unchanged` et `failed`.

Le bot Playwright dans `bot/` peut également récupérer les tâches de la table `external_fetch_jobs`, interroger Faune-France avec son profil persistant puis importer les réponses brutes par lots idempotents :

```bash
cd bot
cp .env.example .env
npm install
npm run worker
```

`FAUNE_FRANCE_BOT_TOKEN` doit avoir exactement la même valeur dans le `.env` Laravel et dans `bot/.env`. Les commandes de connexion manuelle et de recherche ponctuelle restent décrites dans [bot/README.md](bot/README.md).

L’exploration exige désormais une espèce ou un groupe taxonomique précis ; la recherche globale « Tous les animaux » n’est plus proposée. Les surveillances peuvent réunir librement plusieurs espèces ou groupes, sans limite fixe, mais refusent le groupe global et les sélections qui se recouvrent. Elles démarrent le jour de leur activation puis reprennent depuis leur dernière synchronisation, avec un léger chevauchement, au lieu de recharger toute leur fenêtre. La zone peut être la France métropolitaine entière, un point/rayon métropolitain ou des départements utilisant tous le portail `faune_france`. L’estimation Faune-France reste indisponible et l’avancement est visible dans `/imports`.

Les départements ultramarins restent utilisables avec GBIF et iNaturalist, mais leurs portails Faune dédiés ne disposent pas encore de connecteur Playwright.

## Exploitation

```bash
php artisan biodiversity:sync-due-monitoring
php artisan biodiversity:cleanup --dry-run
php artisan biodiversity:cleanup
php artisan queue:failed
php artisan schedule:list
```

Les imports sont plafonnés par défaut à 10 000 lignes par source. GBIF est paginé par 300 sans dépasser sa fenêtre d’offset de 100 000 ; iNaturalist par 200 avec `id_above`, sans pause ajoutée au délai commun entre appels. Faune-France utilise ses pages JSON natives, jusqu’à trois en parallèle, avec un plafond global commun à toute la période. OBIS demeure dans l’audit mais est désactivé par défaut et absent du flux d’import V0.

## Documentation

- [Architecture](docs/architecture.md)
- [Base de données](docs/database.md)
- [Processus d’import](docs/import-process.md)
- [Déduplication](docs/deduplication.md)
- [Exploration et observations : implémentation](docs/observations-exploration-implementation.md)
- [Audit initial des API](docs/api-audit.md)
