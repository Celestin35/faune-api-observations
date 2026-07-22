# Prototype Playwright Faune-France

Ce dossier valide un seul scénario : réutiliser une session Faune-France persistante pour reproduire la recherche du Tichodrome échelette réalisée par l’extension Firefox.

Le prototype est autonome. Il ne communique pas avec Laravel ou Nuxt, n’utilise pas OpenClaw, ne normalise pas les observations et ne lit jamais les identifiants Faune-France.

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

## Configuration de la recherche

Modifier `bot/config.json` :

```json
{
  "dateFrom": "2026-06-22",
  "dateTo": "2026-07-22",
  "departments": ["09"],
  "pagePauseMs": 1500,
  "headless": true
}
```

- `dateFrom` et `dateTo` utilisent le format `YYYY-MM-DD` ;
- `departments` accepte un ou plusieurs codes métropolitains, dont `2A` et `2B` ;
- `pagePauseMs` est la pause entre deux pages, entre 500 et 60 000 ms ;
- `headless` peut être mis à `false` pour voir le navigateur pendant la recherche.

Le taxon ne se configure pas : le script impose le Tichodrome échelette avec `sp_S=383`. Le nombre de pages est également imposé à deux au maximum.

## 1. Connexion manuelle

```bash
npm run login
```

Une fenêtre Firefox Playwright s’ouvre sur Faune-France. Saisissez l’e-mail et le mot de passe uniquement dans le site, terminez la connexion, puis revenez dans le terminal et appuyez sur Entrée. Le navigateur est fermé proprement et ses cookies restent dans le profil dédié.

La commande ne confirme la connexion que si le marqueur de déconnexion utilisé par l’extension est visible dans la page. Appuyer sur Entrée sans s’être connecté produit donc une erreur au lieu de créer une fausse validation.

Il faut relancer cette commande lorsque Faune-France déconnecte la session. Ne lancez jamais `login` et `test-search` simultanément : un profil persistant ne peut être ouvert que par un processus à la fois.

## 2. Test de recherche

```bash
npm run test-search
```

Le script :

1. ouvre le profil persistant ;
2. charge `https://www.faune-france.org/` et exige un marqueur de session connectée ;
3. exécute depuis la page un `POST` vers `m_id=94` pour initialiser les critères ;
4. exécute depuis la page jusqu’à deux `POST` vers `m_id=1351&content=observations_by_page` ;
5. attend `pagePauseMs` avant une éventuelle deuxième page ;
6. ferme proprement le navigateur pour conserver la session.

Tous les appels utilisent `credentials: "include"`. Le navigateur joint donc les cookies du profil sans que le script lise ou construise un header `Cookie`.

Si la page ou une réponse correspond à la connexion Faune-France, le script s’arrête avec un message demandant d’exécuter à nouveau `npm run login`.

## Fichiers produits

Chaque lancement crée un sous-dossier horodaté dans `bot/data/output/`, par exemple :

```text
data/output/2026-07-22T10-15-30-000Z/
├── page-1.raw.json
├── page-2.raw.json       # seulement si une seconde page est annoncée
├── combined-data.json
└── run-summary.json
```

- `page-N.raw.json` est le corps JSON brut reçu pour la page ;
- `combined-data.json` est un tableau contenant toutes les entrées de `data`, sans normalisation ni déduplication ;
- `run-summary.json` consigne les statuts, le nombre de pages et d’entrées, la structure typée de la première entrée et les chemins ressemblant à des coordonnées.

Les sorties sont ignorées par Git. Elles peuvent contenir des données non publiques provenant de la session Faune-France : ne les partagez pas sans les examiner.

## Vérifications locales

```bash
npm test
npm run typecheck
```

Ces commandes vérifient les paramètres, le masque des départements, la pagination et la détection de connexion sans appeler Faune-France.

## Dépannage

- **Session expirée ou absente** : exécuter `npm run login`, terminer la connexion dans la fenêtre, puis appuyer sur Entrée dans le terminal.
- **Firefox ne démarre pas** : exécuter `npm run install-browser`. Sur une installation Ubuntu minimale, Playwright peut signaler des bibliothèques système manquantes ; installer alors uniquement celles listées dans son message.
- **Profil déjà utilisé** : fermer toute fenêtre ouverte par une précédente commande du prototype, puis recommencer.
- **Réponse HTML à la place du JSON** : vérifier d’abord la session avec `npm run login`. Cela peut aussi indiquer que Faune-France a modifié son endpoint interne.
- **Aucune deuxième page** : c’est normal si la première réponse ne contient pas d’indicateur explicite de pagination reconnu ou si `data` est vide.
