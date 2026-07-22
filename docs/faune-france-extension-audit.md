# Audit de l’extension Firefox Faune-France

Date de l’audit : 22 juillet 2026  
Extension auditée : `/home/c-lestin/Projets personnels/faune-france-extension`

## Périmètre

Cet audit décrit le comportement actuel de l’extension, sans le modifier. Il repose sur la lecture de `manifest.json`, `content.js`, `page-network.js`, `core.js`, `popup.js` et `tests/core.test.js`, puis sur l’exécution des tests unitaires existants (`node --test tests/core.test.js`, 7 tests réussis sur 7).

Aucun bot Playwright n’a été développé et aucun code Laravel n’a été modifié.

## Résumé du flux

1. `content.js` reçoit du popup le message `SEARCH_TICHODROME`.
2. Il vérifie que l’onglet courant est exactement sur `https://www.faune-france.org` et que la page semble connectée.
3. Il valide les dates et les départements, puis construit un formulaire Faune-France pour le taxon fixe `Tichodroma muraria` (`sp_S=383`).
4. Il envoie une première requête `POST` vers la page de recherche `m_id=94`. La réponse attendue à cette étape est du HTML ; elle sert à initialiser les critères de recherche côté Faune-France.
5. Il envoie ensuite des `POST` paginés vers `m_id=1351&content=observations_by_page` et attend un objet JSON contenant un tableau `data`.
6. Chaque entrée est réduite à un schéma normalisé, puis l’ensemble est enregistré dans `browser.storage.local` sous la clé `tichodromeSearch`.
7. Si le `fetch` du content script échoue dans certains cas, la même séquence est rejouée dans le contexte JavaScript principal de la page par `page-network.js`.

## Fichiers responsables

### Requêtes et orchestration : `content.js`

Le fichier principal qui réalise et orchestre les requêtes Faune-France est **`content.js`** :

- URL et constantes : lignes 11 à 16 ;
- construction des requêtes : lignes 72 à 83 ;
- `fetch` direct : lignes 86 à 113 ;
- relais vers le contexte principal de la page : lignes 115 à 161 ;
- contrôle des réponses et de la session : lignes 163 à 213 ;
- boucle de recherche et pagination : lignes 215 à 244 ;
- stratégie de repli et stockage : lignes 246 à 285.

### Relais réseau dans la page : `page-network.js`

**`page-network.js`** exécute le `fetch` de repli dans le monde JavaScript principal (`MAIN`) de la page Faune-France. Il est injecté dès `document_start` par `manifest.json`.

Il ne construit pas les paramètres métier. Il reçoit de `content.js` une description de requête, vérifie que la cible est autorisée, exécute le `POST`, puis renvoie le statut, l’URL finale, le type de contenu et le corps sous forme de texte.

### Paramètres, pagination et normalisation : `core.js`

**`core.js`** contient la logique pure :

- taxon fixe : lignes 4 à 8 ;
- validation des filtres et masque des départements : lignes 10 à 99 ;
- paramètres du formulaire : lignes 100 à 123 ;
- normalisation d’une observation : lignes 126 à 232 ;
- interprétation des métadonnées de pagination : lignes 234 à 267.

## Endpoints, méthodes, paramètres et headers

### 1. Initialisation de la recherche

| Élément | Valeur |
|---|---|
| URL | `https://www.faune-france.org/index.php?m_id=94` |
| Méthode | `POST` |
| Corps | formulaire encodé avec `URLSearchParams` |
| Credentials | `include` |
| Redirections | `follow` |
| Réponse attendue | HTML ; seul le statut et l’état de session sont contrôlés |

Headers explicitement définis par l’extension :

```http
Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8
Content-Type: application/x-www-form-urlencoded; charset=UTF-8
```

### 2. Récupération d’une page de résultats

| Élément | Valeur |
|---|---|
| URL | `https://www.faune-france.org/index.php?m_id=1351&content=observations_by_page` |
| Méthode | `POST` |
| Corps | même formulaire encodé, avec `mp_current_page` correspondant à la page demandée |
| Credentials | `include` |
| Redirections | `follow` |
| Réponse attendue | JSON contenant au minimum une propriété `data` de type tableau |

Headers explicitement définis par l’extension :

```http
Accept: application/json, text/javascript, */*; q=0.01
X-Requested-With: XMLHttpRequest
Content-Type: application/x-www-form-urlencoded; charset=UTF-8
```

Le navigateur peut ajouter ses propres headers automatiques (`Cookie`, `Origin`, `Referer`, `Sec-Fetch-*`, etc.). Ils ne sont ni construits ni garantis explicitement par le code de l’extension. Aucun header `Authorization`, `Cookie` ou jeton CSRF n’est lu ou injecté manuellement.

### Corps du formulaire

Le formulaire envoyé aux deux endpoints contient exactement les clés suivantes :

| Paramètre | Valeur ou origine |
|---|---|
| `backlink` | `skip` |
| `p_c` | `duration` |
| `p_cc` | `-` |
| `sp_tg` | `1` |
| `sp_DChoice` | `range` |
| `sp_DFrom` | date de début, convertie de `YYYY-MM-DD` vers `DD.MM.YYYY` |
| `sp_DTo` | date de fin, convertie vers `DD.MM.YYYY` |
| `sp_DCa` | `0` |
| `sp_SChoice` | `species` |
| `sp_S` | `383` |
| `sp_PChoice` | `canton` |
| `sp_cC` | masque binaire de 100 caractères représentant les départements choisis |
| `sp_project` | `0` |
| `sp_FChoice` | `list` |
| `sp_FDisplay` | `DATE_PLACE_SPECIES` |
| `sp_DFormat` | `DESC` |
| `sp_FMapFormat` | `none` |
| `sp_FExportFormat` | `XLS` |
| `mp_current_page` | numéro de page courant, à partir de `1` |
| `txid` | `1` |

Le taxon est donc actuellement codé en dur : identifiant `383`, nom français `Tichodrome échelette`, nom scientifique `Tichodroma muraria`.

Pour `sp_cC`, les départements métropolitains sont traduits en positions dans un masque de 100 caractères. Les codes à un chiffre sont complétés par un zéro. `2A` occupe l’index 19 et `2B` l’index 20 ; les codes `01` à `19` occupent l’index `code - 1`, puis `21` à `95` l’index numérique correspondant. Le code `20`, les codes supérieurs à `95` et les valeurs inconnues sont refusés.

## Réutilisation de la session Firefox

L’extension ne copie jamais les cookies et n’accède pas à l’API Firefox des cookies.

La session déjà ouverte dans Firefox est réutilisée de deux façons :

1. **Tentative directe depuis le content script.** `content.js` appelle `fetch` vers le même domaine avec `credentials: "include"`. Firefox joint automatiquement les cookies applicables à `www.faune-france.org`.
2. **Repli dans le contexte principal de la page.** En cas d’erreur éligible, `content.js` transmet la description de la requête à `page-network.js` avec `window.postMessage`. Celui-ci utilise une référence à `window.fetch` capturée dans la page et appelle également `fetch` avec `credentials: "include"`.

Le canal de communication interne est `faune-france-tichodrome-network-v1`. Les messages sont limités à la même origine. Avant tout appel, `page-network.js` refuse une cible dont l’origine n’est pas `https://www.faune-france.org`, dont le chemin n’est pas `/index.php`, ou dont `m_id` n’est pas `94` ou `1351`.

Le repli dans la page est tenté après une erreur réseau, un délai dépassé, une session considérée comme expirée, une réponse non JSON, ou un statut HTTP `401`/`403`. Chaque requête a un délai maximal de 45 secondes ; l’attente initiale de disponibilité du relais `MAIN` est limitée à 5 secondes.

## Pagination

Après l’initialisation, l’extension demande les pages à partir de `1`, dans l’ordre, avec une limite absolue de **20 pages** (`MAX_PAGES`). Les observations de chaque page sont concaténées sans déduplication.

La boucle s’arrête dès que :

- `data` est vide ;
- aucune indication explicite de page suivante n’est reconnue ;
- ou la vingtième page a été traitée.

Les indicateurs suivants sont reconnus :

- booléens : `has_next`, `hasNext`, `has_more`, `hasMore`, `pagination.has_next`, `pagination.hasNext` ;
- nombre total de pages : `total_pages`, `totalPages`, `nb_pages`, `number_pages`, `pagination.total_pages`, `pagination.totalPages`, `pager.total_pages` ;
- page suivante : `next_page`, `nextPage`, `pagination.next_page`, `pagination.nextPage`.

Les indicateurs booléens acceptent les booléens JavaScript ainsi que `0`, `1`, `"0"` et `"1"`. Les chaînes `"true"` et `"false"` ne sont pas interprétées spécialement. Un total de pages provoque la continuation si la page courante lui est inférieure. Un champ de page suivante numérique doit être supérieur à la page courante ; une valeur non vide non numérique est également considérée comme une indication de continuation.

Une page non vide ne suffit volontairement pas à continuer. Si Faune-France change ses métadonnées de pagination, l’extension peut donc s’arrêter après la première page. Inversement, si plus de 20 pages existent, les résultats sont tronqués sans indicateur `truncated` dans la réponse ou le stockage.

## Format des réponses

### Réponse de l’initialisation

Le premier endpoint renvoie du HTML. L’extension ne l’analyse que pour détecter une page de connexion ; elle ne récupère aucun résultat dans cette réponse.

### Réponse des résultats

Le second endpoint doit renvoyer un document JSON dont la forme minimale est :

```json
{
  "data": []
}
```

Le corps est toujours lu initialement avec `response.text()`. Pour les résultats, l’extension :

1. refuse un corps vide ou commençant par `<` avec l’erreur `NON_JSON_RESPONSE` ;
2. applique `JSON.parse` ;
3. exige un objet dont `data` est un tableau, sinon retourne `UNEXPECTED_RESPONSE`.

Le `Content-Type` reçu est conservé dans la description interne de la réponse, mais il n’est pas utilisé pour décider si le corps est du JSON. Les autres propriétés du JSON ne sont utilisées que si elles correspondent à un indicateur de pagination reconnu.

La structure brute exacte de chaque élément n’est pas formellement validée. Le normaliseur reconnaît plusieurs chemins possibles décrits ci-dessous et tolère les champs absents.

## Détection d’une session expirée

La détection intervient avant et après les requêtes.

### Avant la recherche

Sur la page courante, la session est considérée comme connectée si un lien ou un élément de déconnexion est trouvé. Elle est considérée comme expirée si :

- l’URL contient `m_id=30494` ;
- ou aucun marqueur de déconnexion n’existe alors que la page contient à la fois un champ mot de passe et un champ e-mail.

Les sélecteurs exacts sont :

```css
/* Marqueur de session connectée */
a[href*="logout=1"], a[href*="logout"], [data-action="logout"]

/* Champs utilisés pour reconnaître un formulaire de connexion */
input[type="password"], input[name="password"]
input[type="email"], input[name="email"]
```

### Après chaque requête

Une réponse est reconnue comme page de connexion si :

- son URL finale, après redirections, contient `m_id=30494` ;
- ou son HTML contient simultanément un formulaire d’identifiants (mot de passe et e-mail) et un marqueur de page dédiée : texte indiquant « pour accéder à la page demandée », variante avec entités HTML, ou titre HTML `login`.

La présence d’un marqueur de déconnexion dans le corps prend le dessus et fait considérer la réponse comme authentifiée.

Après ce contrôle, seul le statut HTTP `200` est accepté. Les redirections sont suivies, ce qui permet de contrôler leur URL finale et leur contenu. La détection reste heuristique : une modification du HTML, des noms de champs ou des textes de connexion par Faune-France peut produire un faux négatif.

## Normalisation des observations

Chaque entrée de `data` est transformée en objet contenant uniquement les champs suivants :

| Champ normalisé | Sources brutes essayées, dans l’ordre | Traitement |
|---|---|---|
| `id` | `opt_observers[*].opt_observer_info[0].id_sighting`, `id_sighting`, `id` | chaîne nettoyée ou `null` |
| `speciesName` | `species_array.name`, sinon `Tichodrome échelette` | balises HTML retirées |
| `scientificName` | `species_array.latin_name`, sinon `Tichodroma muraria` | balises HTML retirées |
| `date` | `date_raw`, `date` | chaîne telle quelle, sans conversion ni validation |
| `time` | information observateur `timing`, `timing`, `time` | chaîne ou `null` |
| `count` | `birds_count`, puis `count` | entier tronqué ; chaîne acceptée seulement si composée de chiffres ; sinon `null` |
| `countLabel` | `birds_count_raw`, puis la valeur brute du comptage | chaîne ou `null` |
| `location` | `listSubmenu.title`, `location`, `place_name`, `locality` | balises HTML retirées |
| `departmentCode` | champs département directs, puis extraction depuis `location` | code validé ou `null` |
| `remark` | `remarks`, `remark` | balises HTML retirées |
| `hidden` | `is_hidden`, `is_admin_hidden` | booléen normalisé |

Pour les informations d’observateur, le code parcourt `opt_observers` et utilise le premier objet trouvé dans `opt_observer_info[0]`.

Les chemins directs du département sont, dans l’ordre : `departmentCode`, `department_code`, `departement_code`, `canton_code`, `place.department_code`, `listSubmenu.department_code`. À défaut, le code tente de reconnaître un code dans le libellé du lieu, notamment sous les formes `(09)`, `[09]`, `département: 09`, `dép. 09` ou un suffixe ` - 09`.

Le nettoyage HTML utilise `DOMParser` dans Firefox, puis normalise les espaces. Une suppression par expression régulière sert de repli lorsque `DOMParser` n’est pas disponible.

Le normaliseur ne conserve volontairement ni les coordonnées, ni le nom de l’observateur, ni l’objet brut complet. Il ne déduplique pas les observations et ne valide pas le format de la date.

## Données stockées et réponse au popup

À la fin d’une recherche réussie, `content.js` enregistre dans `browser.storage.local`, sous `tichodromeSearch` :

```json
{
  "species": {
    "id": "383",
    "name": "Tichodrome échelette",
    "scientificName": "Tichodroma muraria"
  },
  "filters": {},
  "searchedAt": "date ISO",
  "count": 0,
  "observations": []
}
```

`filters` contient les filtres validés, `count` correspond à la longueur du tableau final et `observations` contient les objets normalisés. Le message de succès renvoyé au popup expose `count`, `observations` et `searchedAt`. En cas d’échec, il renvoie `ok: false`, un code interne et un message d’erreur.

## Limites et points d’attention constatés

- Le fonctionnement dépend d’endpoints internes et de structures HTML/JSON non versionnés par l’extension.
- La recherche est limitée au Tichodrome échelette ; elle n’est pas générique pour un taxon arbitraire.
- Le nombre d’éléments par page n’est pas imposé par un paramètre explicite.
- La pagination s’arrête sans métadonnée explicite reconnue et tronque silencieusement après 20 pages.
- Il n’existe ni reprise avec temporisation progressive, ni gestion de quota, ni déduplication.
- Le repli rejoue toute la séquence de recherche, pas seulement la requête qui a échoué.
- Le schéma brut d’une observation n’est pas validé ; les changements de noms de champs peuvent produire des valeurs `null` sans faire échouer la recherche.
- Les tests existants couvrent la logique pure de `core.js`, mais pas une session Firefox réelle, les endpoints distants, les redirections ni le relais `window.postMessage`.

## Conclusion

L’extension s’appuie exclusivement sur la session déjà connectée à `www.faune-france.org`. Elle ne connaît ni le mot de passe ni la valeur des cookies : Firefox les joint automatiquement aux `fetch` effectués avec `credentials: "include"`. La recherche est initialisée par un `POST` HTML, puis les pages JSON sont récupérées séquentiellement, au maximum 20 fois, avant normalisation et stockage local.
