# Prototype Playwright Faune-France

Ce dossier réutilise une session Faune-France persistante pour exécuter une recherche décrite dans un fichier de tâche JSON ou reçue depuis le worker Laravel. Il sait renouveler automatiquement une session expirée à partir de secrets locaux.

## Prérequis et installation

- Node.js 20 ou plus récent ;
- npm ;
- une session graphique sous Ubuntu pour la première connexion manuelle.

Depuis la racine du projet :

```bash
cd bot
npm install
npm run install-browser
```

La dernière commande installe uniquement le Firefox géré par Playwright. Le profil utilisé par ce navigateur est distinct de votre profil Firefox personnel et se trouve dans :

```text
bot/data/browser-profile/
```

Ce dossier contient des cookies de session sensibles. Il est ignoré par Git : ne le partagez pas et ne le copiez pas dans le dépôt.

## Recherche à partir d’une tâche JSON

Un exemple prêt à l’emploi se trouve dans `bot/jobs/test-001.json` :

```json
{
  "jobId": "test-001",
  "taxon": {
    "fauneFranceId": "383",
    "scientificName": "Tichodroma muraria",
    "vernacularName": "Tichodrome échelette",
    "rank": "species"
  },
  "dateFrom": "2026-06-22",
  "dateTo": "2026-07-22",
  "departments": ["09"],
  "maxPages": 100,
  "pagePauseMs": 1500
}
```

- `jobId` identifie la tâche et son dossier de sortie. Il accepte uniquement lettres, chiffres, `.`, `_` et `-`, sans séparateur de chemin ;
- `taxon.fauneFranceId` est obligatoire et doit contenir l’identifiant numérique Faune-France ;
- les noms scientifique et vernaculaire sont descriptifs : ils ne servent jamais à rechercher ou deviner l’identifiant ;
- `taxon.rank` doit temporairement être `species`, comme `sp_SChoice=species` dans le formulaire ;
- `dateFrom` et `dateTo` utilisent le format `YYYY-MM-DD` ;
- `departments` accepte un ou plusieurs codes métropolitains, dont `2A` et `2B` ;
- à la place de `departments`, une tâche peut fournir `zone` avec `type: "radius"`, `latitude`, `longitude`, `radiusKm` et éventuellement `address` ; le bot transforme alors le cercle en WKT `sp_Polygon` pour Faune-France ;
- `pagePauseMs` est la pause entre deux pages, entre 500 et 60 000 ms ;
- `maxPages` est la limite de sécurité, entre 1 et 1 000.

Le fichier est validé strictement : les champs absents, inconnus, mal typés ou invalides sont refusés avant le démarrage du navigateur.

## 1. Connexion manuelle

```bash
npm run login
```

Une fenêtre Firefox Playwright s’ouvre sur Faune-France. Saisissez l’e-mail et le mot de passe uniquement dans le site, terminez la connexion, puis revenez dans le terminal et appuyez sur Entrée. Le navigateur est fermé proprement et ses cookies restent dans le profil dédié.

La commande ne confirme la connexion que si le marqueur de déconnexion utilisé par l’extension est visible dans la page. Appuyer sur Entrée sans s’être connecté produit donc une erreur au lieu de créer une fausse validation.

Cette commande reste la solution de secours si Faune-France demande un CAPTCHA, une validation par e-mail, une double authentification ou une étape inconnue. Ne lancez jamais deux commandes du bot simultanément : un profil persistant ne peut être ouvert que par un processus à la fois.

## 2. Exécution d’une tâche

Depuis `bot/` :

```bash
npm run search -- --job=./jobs/test-001.json
```

Le chemin de la tâche est résolu depuis le dossier courant. La commande exige exactement un argument `--job=...`.

Le script :

1. valide intégralement le fichier JSON ;
2. ouvre le profil persistant ;
3. charge `https://www.faune-france.org/`, vérifie la session et la renouvelle si nécessaire ;
4. initialise la recherche avec le taxon, les dates et soit les départements, soit le polygone point/rayon du fichier ;
5. récupère les pages jusqu’à `data_is_finished`, une page vide, une page répétée ou `maxPages` ;
6. attend `pagePauseMs` entre chaque page et ferme proprement le navigateur.

`sp_S` reçoit exactement `taxon.fauneFranceId`. `sp_SChoice` reste fixé à `species` pour ce prototype.

## 3. Commande de test historique

```bash
npm run test-search
```

Cette commande est conservée. Elle utilise encore `bot/config.json` et construit en interne une tâche Tichodrome compatible avec le nouveau moteur. Elle est utile pour les essais historiques ; les nouvelles recherches doivent utiliser `npm run search`.

Le moteur commun :

1. exécute depuis la page un `POST` vers `m_id=94` pour initialiser les critères ;
2. exécute depuis la page les `POST` successifs vers `m_id=1351&content=observations_by_page` ;
3. conserve la pagination actuelle basée sur `data_is_finished`.

Après `m_id=94`, le bot attend au minimum 1,5 seconde avant la première page. Si Faune-France renvoie malgré tout un corps vide pendant la préparation de la recherche, il attend puis réessaie cette page une seule fois.

## 4. Worker Laravel permanent

Créer `bot/.env` à partir de l’exemple :

```bash
cp .env.example .env
```

Configurer le même token secret dans le `.env` Laravel à la racine et dans `bot/.env` :

```env
FAUNE_FRANCE_BOT_TOKEN=
FAUNE_FRANCE_EMAIL=
FAUNE_FRANCE_PASSWORD=
LARAVEL_API_URL=http://localhost:8000
BOT_POLL_INTERVAL_MS=30000
```

`FAUNE_FRANCE_EMAIL` et `FAUNE_FRANCE_PASSWORD` servent exclusivement à la reconnexion automatique. Ils restent vides dans `.env.example` et doivent être renseignés uniquement dans `bot/.env`. Les deux fichiers `.env` réels sont ignorés par Git. Générer le token avec un gestionnaire de secrets ou, en local, avec `openssl rand -hex 32`. Ne jamais committer ni afficher ces secrets.

Après `php artisan migrate`, démarrer le worker depuis `bot/` :

```bash
npm run worker
```

En développement local, la commande `./dev` exécutée depuis la racine lance
également ce worker sous forme de service utilisateur systemd, avec redémarrage
automatique en cas de crash. Son état et ses logs sont disponibles avec
`./dev status` et `./dev logs`. Il ne faut pas lancer simultanément un second
`npm run worker`, car les deux processus tenteraient d’utiliser le même profil
Firefox persistant.

Toutes les 30 secondes, le worker :

1. demande la prochaine tâche Faune-France à Laravel ;
2. la réserve atomiquement ;
3. envoie un heartbeat et la passe à `running` ;
4. lance la recherche Playwright avec le profil persistant ;
5. envoie les observations brutes par lots de 100 au maximum ;
6. marque la tâche `completed`, ou appelle `fail` en cas d’erreur ;
7. continue ensuite sa boucle au lieu de s’arrêter.

Le worker doit rester seul à utiliser `data/browser-profile/`. Avant chaque recherche, il vérifie le marqueur de déconnexion. Si la session a expiré, il tente une seule reconnexion pour la tâche. Si l’expiration survient pendant `m_id=94` ou `m_id=1351`, il revient à `m_id=94` et recommence la recherche complète. Les lots ne sont envoyés à Laravel qu’après la réussite complète de la recherche, donc la reprise ne les duplique pas.

Une tâche échoue avec un code explicite si les identifiants manquent, si le formulaire a changé, si Faune-France refuse la connexion ou si une intervention interactive est requise. Le worker signale cet échec à Laravel puis continue son polling. Dans ce dernier cas, utiliser `npm run login`.

Laravel remet simplement en attente les tâches `claimed` ou `running` sans heartbeat depuis plus de cinq minutes lors du prochain appel à `/api/bot/jobs/next`.

Tous les appels utilisent `credentials: "include"`. Le navigateur joint donc les cookies du profil sans que le script lise ou construise un header `Cookie`.

Les logs d’authentification se limitent à l’état de la session. L’e-mail, le mot de passe, les cookies et le contenu du `.env` ne sont jamais écrits dans les logs ni dans `run-summary.json`.

## Fichiers produits

Chaque lancement crée un dossier portant le `jobId`, puis un sous-dossier horodaté :

```text
data/output/test-001/
└── 2026-07-22T10-15-30-000Z/
    ├── page-1.raw.json
    ├── page-2.raw.json
    ├── combined-data.json
    └── run-summary.json
```

- `page-N.raw.json` est le corps JSON brut reçu pour la page ;
- `combined-data.json` est un tableau contenant toutes les entrées de `data`, sans normalisation ni déduplication ;
- `run-summary.json` consigne le `jobId`, le taxon et les filtres reçus, les statuts, le nombre de pages et d’entrées, la valeur et le type de `data_is_finished` pour chaque page, l’éventuelle troncature par `maxPages`, la structure typée de la première entrée et les chemins ressemblant à des coordonnées.

Les sorties sont ignorées par Git. Elles peuvent contenir des données non publiques provenant de la session Faune-France : ne les partagez pas sans les examiner.

## Vérifications locales

```bash
npm test
npm run typecheck
```

Ces commandes vérifient le schéma des tâches, le taxon dynamique, les paramètres, le masque des départements, la pagination et la détection de connexion sans appeler Faune-France.

## Mise à jour du catalogue taxonomique Faune-France

Le sélecteur Faune-France conserve son catalogue officiel dans l’IndexedDB du navigateur. Pour mettre à jour les correspondances locales après une évolution de Faune-France ou de TAXREF, arrêter d’abord le worker afin qu’il libère le profil, puis exécuter :

```bash
cd bot
npm run export-taxa
cd ..
docker compose exec -T app php artisan faune-france:import-taxa \
  /app/bot/data/output/faune-france-taxa.json --dry-run
docker compose exec -T app php artisan faune-france:import-taxa \
  /app/bot/data/output/faune-france-taxa.json
```

L’import n’invente jamais une correspondance par ressemblance. Il valide uniquement un nom scientifique accepté exact, un synonyme scientifique TAXREF exact ou, en dernier recours, un nom vernaculaire français exact et unique pour une espèce. Les entrées absentes ou ambiguës restent dans le rapport JSON pour révision et ne rendent pas Faune-France disponible dans l’interface.

## Dépannage

- **`AUTH_CREDENTIALS_MISSING`** : renseigner les deux variables Faune-France dans `bot/.env`, ou utiliser `npm run login`.
- **`AUTH_FORM_NOT_FOUND`** : le formulaire a probablement changé ; utiliser `npm run login` et vérifier les sélecteurs.
- **`AUTH_LOGIN_FAILED`** : vérifier les identifiants dans `bot/.env`, sans les copier dans les logs.
- **`AUTH_MANUAL_INTERVENTION_REQUIRED`** : terminer manuellement le CAPTCHA, la validation ou la double authentification avec `npm run login`.
- **Firefox ne démarre pas** : exécuter `npm run install-browser`. Sur une installation Ubuntu minimale, Playwright peut signaler des bibliothèques système manquantes ; installer alors uniquement celles listées dans son message.
- **Profil déjà utilisé** : fermer toute fenêtre ouverte par une précédente commande du prototype, puis recommencer.
- **Réponse HTML à la place du JSON** : vérifier d’abord la session avec `npm run login`. Cela peut aussi indiquer que Faune-France a modifié son endpoint interne.
- **Aucune page suivante** : c’est normal si `data_is_finished` indique la fin ou si `data` est vide. Une valeur de `data_is_finished` inconnue produit une erreur explicite afin d’éviter une boucle incontrôlée.
