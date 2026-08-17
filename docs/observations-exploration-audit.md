# Audit ciblé — exploration, imports et observations

Date : 23 juillet 2026
Projet : `observations-api`
Nature : audit en lecture seule du code et des fichiers locaux

## 0. Périmètre et conclusions

Cet audit décrit l’état réel du dépôt au moment de sa rédaction. Il tient compte des développements déjà réalisés depuis les premiers audits, notamment :

- le référentiel canonique TAXREF v18 ;
- les correspondances taxonomiques Faune-France ;
- les 101 départements ;
- l’autocomplétion d’adresses dans les surveillances ;
- le support réel d’un point et d’un rayon par le bot Faune-France, convertis en polygone WKT.

Aucun code, aucune migration et aucune donnée n’ont été modifiés pour produire ce rapport.

Conclusions principales :

1. L’exploration ponctuelle et les surveillances passent déjà par `SearchDefinitionFactory` et `SearchDefinition`, ce qui constitue un bon noyau commun. Leurs interfaces, leurs validations d’entrée et surtout leurs mécanismes d’exécution restent toutefois différents.
2. Faune-France n’apparaît pas dans l’exploration parce que celle-ci appelle la factory avec `allowFauneFrance=false`, ne propose pas la source dans Nuxt et crée uniquement des `import_jobs`, que le job Laravel ne sait traiter que pour GBIF et iNaturalist.
3. Le bot Faune-France accepte aujourd’hui les départements métropolitains **et** un point/rayon métropolitain converti explicitement en `sp_Polygon`. Cette dernière capacité est couverte par les tests du bot et a été validée lors d’une recherche réelle. Elle n’était pas présente dans l’extension Firefox auditée initialement.
4. Une route API de détail existe déjà (`GET /api/observations/{id}`), mais aucune page Nuxt `/observations/{id}` n’existe. La réponse expose directement les modèles Eloquent, y compris le `raw_data` des provenances : elle ne doit pas être utilisée telle quelle pour une page publique.
5. Le schéma canonique conserve la date/heure, le point, l’incertitude, le nombre, un statut, un observateur, un lieu libre et des remarques. Il ne possède pas de colonnes structurées pour pays, région, département, commune, confidentialité, stade de vie, sexe ou comportement.
6. Les coordonnées publiques sont importées, mais les marqueurs de confidentialité ou de masquage ne sont pas normalisés. C’est le principal blocage fonctionnel et éthique avant l’affichage d’une page détail.
7. Les 101 départements ont un nom et une région, mais leurs géométries locales sont des rectangles d’emprise, pas des contours administratifs. Il n’existe pas de référentiel communal local.

## 1. Exploration ponctuelle actuelle

### 1.1 Frontend

La page est `front/app/pages/exploration.vue`. Elle utilise :

- `TaxonPicker.vue`, commun avec la création d’une surveillance ;
- `useApi()` pour les appels Laravel ;
- `GET /api/geographic-areas` pour les départements ;
- `POST /api/searches/estimate` pour l’estimation ;
- `POST /api/imports` pour l’import confirmé.

Elle n’utilise ni l’autocomplétion d’adresse ni le sélecteur filtrable de départements développés dans `surveillances/nouvelle.vue`.

Critères disponibles :

| Critère | État actuel |
|---|---|
| Taxon | facultatif ; vide signifie toutes les observations/`Animalia` selon le connecteur |
| `taxon_id` | identifiant canonique retourné par `TaxonPicker` |
| `taxon_scope` | `defaultScope` du taxon sélectionné (`exact` ou `subtree`) |
| Date de début/fin | dates absolues ; trente derniers jours par défaut |
| Type de zone | `radius` ou `departments` |
| Latitude/longitude | numériques, valeurs rennaises par défaut |
| Rayon | en kilomètres, `1..200` dans l’HTML |
| Départements | cases à cocher parmi les 101 entrées |
| Sources | GBIF et iNaturalist uniquement |
| Adresse | absente |
| Collection cible | non exposée, bien que l’API accepte `data_collection_id` |
| Limite d’import | non modifiable ; valeur serveur, 10 000 par source au maximum |

Le payload commun envoyé aux deux opérations ressemble à :

```json
{
  "taxon_id": 220457,
  "taxon_scope": "exact",
  "date_from": "2026-07-01",
  "date_to": "2026-07-22",
  "sources": ["gbif", "inaturalist"],
  "zone": {
    "type": "radius",
    "latitude": 48.1173,
    "longitude": -1.6778,
    "radius_km": 30
  }
}
```

Pour une zone départementale, `zone` contient `type: "departments"` et `department_codes`.

Limites de validation frontend :

- le composant taxon garantit une sélection locale lorsqu’un texte a été choisi, mais le taxon reste facultatif ;
- les contraintes HTML sur le rayon ne remplacent pas la validation serveur ;
- aucune vérification explicite n’empêche une liste de départements vide avant l’appel ;
- aucun message propre aux portails Faune ultramarins n’est présent ;
- une estimation ancienne n’est invalidée que lorsque le taxon change, pas lorsque les dates, la zone ou les sources changent. Le bouton d’import peut donc envoyer des `estimates` qui ne correspondent plus aux critères visibles.

### 1.2 Bouton `Estimer`

`runEstimate()` appelle `POST /api/searches/estimate`. `SearchEstimateController` :

1. transforme le payload via `SearchDefinitionFactory::make()` ;
2. recherche les observations locales avec `LocalObservationQuery` ;
3. construit une ou plusieurs `OccurrenceQuery` par source ;
4. appelle les compteurs GBIF et iNaturalist ;
5. estime le recouvrement iNaturalist présent dans GBIF ;
6. compare la période demandée aux `collection_coverages`.

La réponse contient :

- `local.count`, `covered_from`, `covered_to` ;
- `external[source]`, nombre ou objet d’erreur ;
- l’approximation du recouvrement iNaturalist/GBIF ;
- `coverage_complete` et `missing_periods` ;
- `import_limit_per_source` ;
- un avertissement sur le caractère indicatif des compteurs.

Cette opération ne crée aucune ligne et aucun job.

### 1.3 Bouton `Importer après confirmation`

Le bouton exige d’abord une estimation dans l’état frontend et une confirmation native du navigateur. Il appelle ensuite :

```json
{
  "...critères": "...",
  "confirmed": true,
  "estimates": {
    "gbif": 123,
    "inaturalist": 45
  }
}
```

`ImportController::store()` valide `confirmed`, l’éventuelle collection et le tableau `estimates`, puis appelle `ImportCoordinator`.

Pour chaque source, `ImportCoordinator` :

1. crée un `import_jobs` avec taxon, version TAXREF, scope, libellé figé, dates, zone, hash de zone, limite et estimation ;
2. distribue un `ImportObservationsJob` dans la queue Laravel.

`ImportObservationsJob` :

- accepte uniquement les connecteurs GBIF et iNaturalist ;
- pagine GBIF par 300, dans la fenêtre maximale de 100 000 ;
- pagine iNaturalist par 200 avec `id_above` et une pause configurable ;
- plafonne chaque `import_job` à `BIODIVERSITY_IMPORT_LIMIT`, au plus 10 000 ;
- normalise puis persiste chaque occurrence ;
- met à jour les compteurs et crée une `collection_coverage` ;
- termine en `completed`, `partial` ou `failed`.

La page `/imports` interroge `GET /api/imports` toutes les trois secondes. Elle ne liste que les `import_jobs`, pas les `external_fetch_jobs`.

## 2. Exploration et surveillance : comparaison

Les deux parcours utilisent déjà `TaxonPicker`, `SearchDefinitionFactory`, `SearchDefinition`, `SearchQueryFactory` et `OccurrencePersister` pour la partie commune. La surveillance ajoute une couche de planification et un chemin particulier Faune-France.

| Notion | Exploration ponctuelle | Surveillance récurrente | Écart |
|---|---|---|---|
| `taxon_id` | facultatif | exigé par le frontend, techniquement nullable en base | validation différente |
| `taxon_scope` | valeur du résultat TAXREF | même valeur | commun |
| `taxonomic_reference_version_id` | dérivé et stocké dans `import_jobs` | dérivé et stocké dans `monitoring_rules`/jobs | commun, non envoyé explicitement |
| `taxon_label_snapshot` | dérivé dans l’import | dérivé dans la règle et recopié | commun |
| `sources` | GBIF, iNaturalist | GBIF, iNaturalist, Faune-France sous conditions | incohérent |
| `zone_type` | `radius` ou `departments` | mêmes valeurs persistées | commun |
| Adresse | absente | autocomplétion, puis stockée comme libellé dans une zone `radius` | surveillance seulement |
| Latitude/longitude | saisie directe | adresse géocodée ou saisie directe | même format backend |
| `radius_km` | oui | oui | commun |
| Départements | cases à cocher | sélecteur filtrable, noms/régions/portails | composants dupliqués |
| `date_from`, `date_to` | période absolue réelle | deux dates factices « aujourd’hui » sont envoyées à la création, puis non stockées dans la règle | dette d’interface |
| Fenêtre glissante | absente | `window_minutes`, convertie en dates à chaque synchronisation | surveillance seulement |
| Fréquence | absente | `frequency_minutes`, minimum 30 min avec GBIF ou Faune-France, sinon 5 | surveillance seulement |
| `max_pages` | non applicable aux deux API standards | Faune-France seulement, injecté depuis la configuration | exécution, pas critère métier |
| Pause entre pages | pause iNaturalist côté job ; non exposée | Faune-France depuis configuration, iNaturalist côté job | exécution, pas critère métier |
| Estimation | obligatoire dans l’UX avant import | absente avant synchronisation | incohérent |
| Nom/activation | absents | `name`, `is_active`, `next_sync_at` | planification seulement |
| Collection | API possible, UI absente | les imports de surveillance ne ciblent pas une collection | contexte d’exécution |

Incohérences supplémentaires :

- `SearchDefinitionFactory` valide les mêmes zones, mais le frontend des surveillances offre trois modes visuels (`address`, `coordinates`, `departments`) qui deviennent seulement deux modes backend ;
- l’exploration ne réutilise pas l’autocomplétion d’adresse, le sélecteur filtrable ni les explications de disponibilité des sources ;
- `allowFauneFrance` est un booléen dépendant du contrôleur au lieu d’une capacité dérivée du contexte d’exécution ;
- la factory mélange validation de critères communs et règles propres au portail Faune-France ;
- la surveillance envoie des dates uniquement pour satisfaire une factory conçue d’abord pour une recherche absolue ;
- `maxPages`, pauses et limites sont des réglages d’exécution qui ne devraient pas être confondus avec les critères reproductibles d’une recherche.

### 2.1 Objet commun proposé

Sans l’implémenter, un objet commun pourrait être :

```text
ObservationQueryCriteria
├── taxon
│   ├── canonicalTaxonId?
│   ├── scope: exact | subtree
│   ├── taxonomicReferenceVersionId?
│   └── labelSnapshot?
├── period
│   ├── type: absolute
│   │   ├── dateFrom
│   │   └── dateTo
│   └── type: sliding
│       └── windowMinutes
├── zone
│   ├── type: radius
│   │   ├── latitude
│   │   ├── longitude
│   │   ├── radiusKm
│   │   └── addressLabel?
│   └── type: departments
│       └── departmentCodes[]
└── sources[]
```

Un second objet séparé porterait le contexte :

```text
ObservationQueryExecution
├── purpose: estimate | one_off_import | monitoring
├── collectionId?
├── monitoringRuleId?
├── frequencyMinutes?
└── sourceOptions
    ├── importLimit
    ├── maxPages
    └── pagePauseMs
```

Cette séparation permettrait :

- un validateur commun des critères ;
- une résolution unique des capacités par source ;
- des composants Nuxt communs `TaxonCriteria`, `ZoneCriteria` et `SourceCriteria` ;
- une conversion explicite d’une période glissante en période absolue au moment de l’exécution ;
- le maintien des réglages techniques côté serveur.

## 3. Compatibilité Faune-France

### 3.1 Flux actuel

Le seul flux automatique actuel est :

```text
monitoring_rule
→ MonitoringSynchronizer
→ external_fetch_jobs (pending)
→ GET next / POST claim
→ worker Playwright
→ m_id=94 puis m_id=1351 paginé
→ lots bruts de 100 maximum vers Laravel
→ FauneFranceRawObservationNormalizer
→ OccurrencePersister
→ complete ou fail
```

Le worker :

- réserve atomiquement une tâche ;
- garde un heartbeat ;
- réutilise une session persistante et sait tenter une reconnexion ;
- n’envoie les lots qu’après la réussite complète de la recherche ;
- rend chaque numéro de lot idempotent ;
- continue à interroger Laravel après un échec.

### 3.2 Ce qui est et n’est pas supporté

| Capacité | État réel |
|---|---|
| Demande ponctuelle depuis l’exploration | non |
| Création d’un `external_fetch_job` sans surveillance | possible en base, mais aucun service utilisateur ne le fait |
| `external_fetch_job.monitoring_rule_id` nullable | oui |
| Taxon dynamique | oui, via `taxon.fauneFranceId` |
| Rang supérieur | non ; `rank` doit être `species` |
| Scope descendant | non ; Faune-France exige `exact` |
| Mapping taxonomique | mapping `faune_france`, `validated`, `preferred` requis |
| Dates absolues | oui, `YYYY-MM-DD`, converties en dates françaises |
| Plusieurs départements | oui, métropolitains et même portail `faune_france` |
| Outre-mer | non pour Faune ; GBIF/iNaturalist restent possibles |
| Point/rayon métropolitain | oui, converti en polygone WKT de 64 sommets |
| Adresse | oui indirectement, après géocodage en point/rayon |
| Commune/lieu-dit/maille | le formulaire du portail les propose, mais le bot ne les implémente pas |
| Polygone natif | oui, utilisé par le bot pour le cercle |
| Pagination | `data_is_finished`, page vide/répétée et limite de sécurité |
| Estimation avant recherche | non |

Le formulaire interne Faune-France possède donc bien des filtres plus précis que le masque départemental : commune, lieu-dit, maille et polygone ont été constatés dans l’interface. Cela ne signifie pas que leurs identifiants et paramètres sont déjà compris ou stables. Seul le polygone utilisé par le bot a été implémenté et réellement validé.

### 3.3 Pourquoi Faune-France est absent de l’exploration

Trois barrières indépendantes existent :

1. Nuxt ne propose que `gbif` et `inaturalist`.
2. `SearchEstimateController` et `ImportController` appellent `SearchDefinitionFactory::make()` sans `allowFauneFrance`; la validation refuserait donc la source.
3. `ImportCoordinator` crée un `import_jobs` par source, puis `ImportObservationsJob` traite tout ce qui n’est pas GBIF comme iNaturalist. Ajouter simplement une case Faune-France provoquerait donc un mauvais chemin d’exécution.

### 3.4 Comportement UX recommandé

Faune-France peut être rendu disponible immédiatement dans l’exploration sous les mêmes conditions que dans les surveillances :

- taxon canonique de rang espèce ;
- mapping Faune-France validé et préféré ;
- scope exact ;
- départements tous métropolitains et portail `faune_france`, **ou** point/rayon en France métropolitaine ;
- période absolue valide.

L’interface doit afficher :

- « estimation indisponible pour Faune-France » au lieu d’un faux compteur ;
- la limite de pages et le fait que le volume final n’est connu qu’après exécution ;
- le mode réellement transmis : masque de départements ou cercle converti en polygone ;
- l’indisponibilité explicite des portails ultramarins.

Il ne faut pas convertir silencieusement un rayon en liste de départements : le bot possède désormais une conversion plus fidèle en polygone.

### 3.5 Évolution backend minimale

Le chemin le plus cohérent est de conserver un suivi d’import unique :

1. créer un `import_job` de façade pour Faune-France, afin qu’il apparaisse dans `/imports` ;
2. relier un `external_fetch_job` à cet import par un `import_job_id` nullable et unique ;
3. faire évoluer le coordinateur pour distribuer :
   - GBIF/iNaturalist vers la queue Laravel ;
   - Faune-France vers `external_fetch_jobs` ;
4. reporter dans l’`import_job` les états et compteurs du worker ;
5. ajouter au job externe les contextes facultatifs `data_collection_id` et/ou utiliser ceux du `import_job` ;
6. permettre à `ExternalFetchJobResultController` d’attacher les observations à la collection ponctuelle ;
7. adapter l’annulation aux tâches Faune-France encore `pending`.

Une simple création directe d’`external_fetch_jobs` fonctionnerait techniquement, mais laisserait l’import invisible dans la page `/imports`, sans plafond homogène, sans collection et sans contrat d’annulation.

## 4. Modèle `Observation`

### 4.1 Table `observations`

Champs réellement présents :

| Champ | Rôle |
|---|---|
| `id` | identifiant canonique interne |
| `taxon_id` | taxon canonique nullable |
| `observed_at` | date/heure avec fuseau, nullable, indexée |
| `latitude`, `longitude` | coordonnées canoniques décimales |
| `geometry` | point PostGIS SRID 4326, caché par le modèle |
| `coordinate_uncertainty_m` | incertitude en mètres |
| `individual_count` | nombre d’individus |
| `validation_status` | statut/qualité générique |
| `observer_name` | observateur canonique |
| `location_name` | libellé de lieu libre |
| `remarks` | remarques canoniques |
| `first_imported_at`, `last_seen_at` | suivi d’import |
| `retain_until` | rétention |
| `created_at`, `updated_at` | timestamps Laravel |

Le modèle caste les dates et coordonnées, masque `geometry`, et expose les relations taxon, sources, collections et surveillances.

### 4.2 Table `observation_sources`

| Champ | Rôle |
|---|---|
| `id`, `observation_id` | provenance rattachée à l’observation canonique |
| `source` | `gbif`, `inaturalist`, `faune-france`, etc. |
| `source_occurrence_id` | identifiant source ; unique avec `source` |
| `source_dataset_id` | jeu de données source |
| `source_taxon_id` | identifiant taxonomique source |
| `origin_key` | clé de rapprochement multisource, indexée |
| `source_url` | page originale |
| `license` | licence de la provenance |
| `source_created_at`, `source_updated_at`, `published_at` | dates source |
| `canonical_identifiers` | indices d’identité conservés en JSON |
| `raw_data` | réponse source complète en JSON |
| `created_at`, `updated_at` | timestamps Laravel |

Il n’existe pas d’API Resource ni de DTO de sortie. `ObservationController` sérialise directement les modèles.

### 4.2.1 Correspondance exhaustive des champs demandés

| Champ fonctionnel demandé | Stockage actuel |
|---|---|
| `taxon_id` | `observations.taxon_id`, explicite et nullable |
| `observed_at` | explicite dans `observations` |
| `date`, `time` | aucune colonne séparée ; dérivables de `observed_at`, mais la précision originale est perdue |
| `latitude`, `longitude` | explicites dans `observations` |
| `coordinate_uncertainty` | `observations.coordinate_uncertainty_m` |
| `location_precision` | aucune colonne ; seulement certains champs bruts, notamment `precision` chez Faune-France |
| `country` | aucune colonne explicite |
| `region` | aucune colonne explicite |
| `department`, `department_code` | aucune colonne explicite |
| `commune`, `city` | aucune colonne explicite |
| `locality`, `place_name` | seulement le générique `observations.location_name` |
| `observer_name` | explicite dans `observations` |
| `observation_count` | nommé `observations.individual_count` |
| `life_stage`, `sex`, `behavior` | aucune colonne ; seulement données brutes éventuelles |
| `quality_grade` | générique `observations.validation_status`, avec sémantiques source différentes |
| `license` | `observation_sources.license`, donc propre à chaque provenance |
| `source_url` | `observation_sources.source_url` |
| `source_taxon_id` | `observation_sources.source_taxon_id` |
| `source_occurrence_id` | `observation_sources.source_occurrence_id` |
| `raw_data` | `observation_sources.raw_data` |
| `created_at`, `updated_at` | présents dans les deux tables ; ce sont des dates Laravel, distinctes des dates propres à la source |

### 4.3 API

`GET /api/observations` accepte :

- `taxon_id`, `taxon_scope` ;
- `source` ;
- `date_from`, `date_to` ;
- `validation_status` ;
- `limit` de 1 à 1 000.

La liste charge le taxon et une projection limitée des sources. Elle n’offre ni pagination par curseur/page ni filtre spatial.

`GET /api/observations/{id}` existe et charge le taxon ainsi que les sources complètes. Cela inclut actuellement `raw_data` et `canonical_identifiers`. Les routes API ne sont pas protégées par un middleware utilisateur dans `routes/api.php`.

### 4.4 DTO normalisé actuel

`NormalizedOccurrence` fournit déjà une bonne base :

- identité source/dataset/taxon ;
- noms et classification ;
- dates source et date observée ;
- coordonnées et incertitude ;
- nombre, statut, observateur ;
- licence, URL, médias ;
- lieu libre, remarques et brut.

Ses lacunes principales :

- aucune précision temporelle (`date seule`, `heure connue`, fuseau source) ;
- aucun statut de confidentialité/localisation ;
- aucun pays/région/département/commune structuré ;
- aucun stade de vie, sexe ou comportement ;
- aucune distinction entre coordonnée publique, masquée et privée ;
- `media` est normalisé mais n’est pas persisté hors du `raw_data` source.

## 5. Disponibilité des champs par source

Légende :

- **explicite** : colonne normalisée et persistée ;
- **brut** : susceptible d’être conservé dans `raw_data`, mais non harmonisé ;
- **dérivable** : calcul possible à partir d’une valeur persistée ;
- **absent** : non produit par le normaliseur actuel.

| Information | GBIF | iNaturalist | Faune-France |
|---|---|---|---|
| `taxon_id` canonique | mapping `taxonKey` ou nom | mapping identifiant ou nom | mapping `sp_S` validé |
| Nom taxonomique brut | `scientificName` dans brut | `taxon.name` dans brut | taxon du job + `species_array` dans brut |
| `observed_at` | `eventDate`, explicite | `time_observed_at` puis `observed_on`, explicite | `date_raw/date` + `timing`; minuit si heure absente |
| Date/heure séparées | dérivables, précision non stockée | dérivables, précision non stockée | dérivables, mais heure absente indiscernable de minuit |
| Latitude/longitude | `decimalLatitude/Longitude`, explicites | coordonnées publiques de `geojson`, explicites | info observateur puis racine, explicites |
| Incertitude | `coordinateUncertaintyInMeters` | `public_positional_accuracy`, sinon `positional_accuracy` | absente ; `precision` reste dans le brut |
| Pays | brut si fourni par GBIF | brut seulement si présent dans la réponse | absent du normaliseur |
| Région | brut si fourni | brut seulement | absent |
| Département/code | brut si fourni | brut seulement | non extrait par le normaliseur actuel |
| Commune/ville | brut si fourni | brut seulement | non structuré |
| Localité/lieu | brut uniquement | brut uniquement | `listSubmenu.title` → `location_name` |
| Observateur | `recordedBy`, explicite | `user.login/name`, explicite | volontairement `null`; auteur dans brut |
| Nombre | `individualCount` | absent | `observerInfo.count` ou `birds_count` |
| Stade de vie | brut éventuel | brut/annotations éventuels | détails bruts éventuels |
| Sexe | brut éventuel | brut/annotations éventuels | détails bruts éventuels |
| Comportement | brut éventuel | brut/champs d’observation éventuels | détails bruts éventuels |
| Qualité/statut | vérification, sinon `occurrenceStatus` | `quality_grade` | absent |
| Licence | explicite | explicite | absente |
| URL source | `references`, sinon URL GBIF | `uri` | lien `listSubmenu.href` si exploitable |
| Identifiant occurrence | `occurrenceID`, sinon clé GBIF | `id` | `id_sighting` |
| Identifiant taxon source | `taxonKey` | `taxon.id` | `fauneFranceId` du job |
| Médias | normalisés, mais seulement retrouvables dans brut après persistance | même limite | aucun média normalisé |
| Remarques | brut seulement | brut seulement | `remarks` nettoyées et explicites |
| Dates d’import | colonnes canoniques et Laravel | idem | idem |

Conséquences :

- `validation_status` ne porte pas la même sémantique selon GBIF et iNaturalist ;
- `observer_name`, `location_name`, `remarks` et les autres champs canoniques reflètent une provenance choisie implicitement, pas une fusion documentée ;
- lors d’une réimportation de la même provenance, les champs canoniques sont remplacés par ses nouvelles valeurs ;
- lors d’un rapprochement avec une autre provenance par `origin_key`, la nouvelle source est ajoutée mais les champs canoniques existants ne sont pas recomposés ;
- la géométrie PostGIS est créée à l’insertion, mais n’est pas recalculée dans la branche de mise à jour d’une provenance existante si latitude/longitude changent.

## 6. Géographie

### 6.1 État local

Le projet possède :

- PostGIS et un point `observations.geometry` en WGS84 ;
- 101 départements avec code, nom, région, portail Faune et identifiants GBIF/iNaturalist ;
- un `geometry_geojson` par département ;
- un service d’autocomplétion d’adresses GeoPlateforme, mis en cache un jour.

Mais :

- `geometry_geojson` est construit par le seeder à partir de `west/south/east/north` : c’est un rectangle d’emprise ;
- cette géométrie est stockée en JSON, pas dans une colonne PostGIS ;
- `LocalObservationQuery` utilise ces rectangles pour les départements ;
- aucune table de communes, aucun code INSEE communal et aucun contour communal/régional précis n’existent ;
- le service GeoPlateforme utilisé ne fait que la complétion directe d’adresse, pas le géocodage inverse.

Il est donc impossible aujourd’hui d’affirmer localement qu’un point appartient à une commune ou même précisément à un département à partir des seules géométries présentes.

### 6.2 Stratégie A — champs de la source

Avantages :

- aucun appel supplémentaire ;
- respecte en principe le niveau de précision publié par la source ;
- conserve les libellés d’origine.

Limites :

- champs hétérogènes et parfois absents ;
- nomenclatures variables ;
- le département peut ne pas être fourni ;
- une localité textuelle ne prouve pas que le point est précis ;
- Faune-France ne fournit actuellement qu’un libellé de lieu normalisé par le bot.

Usage recommandé : première valeur de provenance, conservée séparément et affichée comme « Localité indiquée par la source ».

### 6.3 Stratégie B — résolution locale PostGIS

Flux cible :

```text
point public
→ ST_Intersects avec commune officielle
→ département
→ région
```

Avantages :

- déterministe, rapide après indexation ;
- sans quota ni fuite de coordonnées vers un service tiers ;
- recalculable lors d’une mise à jour du référentiel ;
- cohérence des codes INSEE.

Limites actuelles :

- les contours nécessaires n’existent pas ;
- les rectangles départementaux sont insuffisants ;
- il faut gérer versions, simplification, outre-mer et points frontaliers.

C’est la stratégie principale recommandée à terme, après un import séparé et versionné de géométries administratives officielles. Aucun téléchargement ne doit être mêlé à la migration applicative.

### 6.4 Stratégie C — géocodage inverse externe

Avantages :

- mise en œuvre initiale plus rapide ;
- peut retourner commune, code postal et contexte administratif.

Limites :

- disponibilité, quotas, latence et changements de contrat ;
- nécessité d’un cache ;
- résultats variables ou ambigus aux frontières ;
- transmission de coordonnées potentiellement sensibles à un tiers.

Usage recommandé : fallback asynchrone uniquement pour des coordonnées déclarées publiques, avec cache par coordonnée arrondie/geohash, délai, reprise et provenance du résultat. Ne jamais envoyer une coordonnée privée ou sensible.

### 6.5 Recommandation

Ordre recommandé :

1. conserver et afficher les libellés fournis par chaque source ;
2. ajouter les champs de provenance et le statut de confidentialité ;
3. importer ultérieurement des contours administratifs officiels et résoudre localement ;
4. utiliser le géocodage inverse externe seulement comme fallback caché ;
5. ne jamais déduire une commune depuis les rectangles actuels.

## 7. Confidentialité et précision

### 7.1 Comportement actuel

GBIF :

- le normaliseur prend les coordonnées publiées et `coordinateUncertaintyInMeters` ;
- il ne normalise aucun indicateur distinct de coordonnée masquée/sensible ;
- d’autres champs éventuels restent uniquement dans `raw_data`.

iNaturalist :

- le normaliseur utilise `geojson`, donc la position publique renvoyée ;
- il préfère `public_positional_accuracy` ;
- il n’enregistre pas séparément un éventuel `geoprivacy`, un masquage ou l’existence d’une position privée.

Faune-France :

- la réponse réelle contient notamment `is_hidden`, `is_admin_hidden`, `admin_hidden_type`, `show_transparent` et une `precision` observée avec des valeurs comme `place`, `precise` ou `garden` ;
- le normaliseur ignore actuellement ces marqueurs ;
- il persiste les coordonnées dès qu’elles sont numériques ;
- il ne renseigne aucune incertitude ;
- l’auteur reste volontairement absent du champ canonique, mais demeure dans le `raw_data`.

Toutes sources :

- la carte affiche tout point canonique sans avertissement ni zone d’incertitude ;
- le détail API renvoie le brut complet ;
- aucun champ ne distingue une position exacte, approximative, masquée ou indisponible.

### 7.2 Modèle cible minimal

Ajouter un vocabulaire stable, par exemple :

```text
location_status:
  exact
  approximate
  source_masked
  unavailable
```

Libellés :

| Valeur | Libellé |
|---|---|
| `exact` | Coordonnées publiées comme précises par la source |
| `approximate` | Coordonnées approximatives — incertitude indiquée |
| `source_masked` | Position masquée par la source |
| `unavailable` | Localisation indisponible |

Le mot « exact » ne doit pas signifier « nombre avec beaucoup de décimales ». Il exige une sémantique source compatible et l’absence de marqueur de masquage.

Champs recommandés par provenance :

- `public_latitude`, `public_longitude` ;
- `coordinate_uncertainty_m` ;
- `location_status` ;
- `source_location_precision` ;
- `source_location_name` ;
- `privacy_evidence` JSON limité aux indicateurs utiles ;
- `observer_is_public`.

L’observation canonique doit recevoir une localisation publique choisie selon une règle explicite. Les coordonnées privées ne doivent pas être copiées dans `raw_data` accessible à l’API utilisateur. Le brut complet doit rester interne, filtré ou chiffré selon le futur modèle d’accès.

Règles d’affichage :

- `exact` : marqueur et coordonnées, avec la précision annoncée ;
- `approximate` : marqueur public + cercle d’incertitude ;
- `source_masked` : zone ou point public approximatif si la source l’autorise, jamais de prétention d’exactitude ;
- `unavailable` : aucune carte ponctuelle et un message clair.

## 8. Future page détail

### 8.1 Existant

| Élément | État |
|---|---|
| Route Laravel détail | oui, `GET /api/observations/{observation}` |
| DTO/Resource de détail | non |
| Route Nuxt dynamique | non |
| Bibliothèque cartographique | MapLibre GL 5 |
| Composant carte | `MapView.client.vue`, conçu pour une collection et le clustering |
| Lien depuis la carte | non |
| Liste HTML d’observations | non |
| Popup carte | nom, nom scientifique, date, sources ; aucun lien |

### 8.2 Contrat API recommandé

Créer une ressource dédiée qui n’expose jamais le brut :

```text
ObservationDetailResource
├── id
├── taxon
│   ├── id, frenchName, scientificName, rank, lineage
├── observedAt
├── temporalPrecision
├── location
│   ├── status, latitude?, longitude?, uncertaintyM?
│   ├── locality?, commune?, department?, region?, country?
│   └── resolutionMethod?
├── individualCount
├── validationStatus
├── observerName?
├── lifeStage?, sex?, behavior?, remarks?
├── firstImportedAt, lastSeenAt
└── sources[]
    ├── source, occurrenceId, taxonId
    ├── url?, license?, datasetId?
    ├── observedAt?, locationStatus?
    └── importedAt
```

`raw_data` doit être exclu. Une éventuelle route d’administration distincte devra être protégée.

### 8.3 Structure Nuxt

Créer `front/app/pages/observations/[id].vue` :

1. **En-tête**
   - nom français ;
   - nom scientifique ;
   - date et heure, avec précision explicite ;
   - badges des provenances ;
   - statut/qualité.
2. **Carte**
   - composant dédié à un détail, sans clustering ;
   - marqueur public ;
   - cercle d’incertitude si pertinent ;
   - centrage adapté à l’incertitude ;
   - message si position masquée ou absente.
3. **Localisation**
   - localité source ;
   - commune, département, région, pays seulement si connus ;
   - coordonnées publiques ;
   - incertitude et méthode de résolution.
4. **Observation**
   - nombre ;
   - observateur uniquement s’il est publiable ;
   - stade, sexe, comportement et remarques disponibles.
5. **Taxonomie**
   - taxon canonique, rang et lignée ;
   - lien futur vers le détail du taxon.
6. **Sources**
   - toutes les provenances ;
   - identifiant, licence, URL originale et date d’import ;
   - valeurs propres à chaque provenance si elles divergent.

Ajouter ensuite :

- un lien dans le popup MapLibre vers `/observations/{id}` ;
- éventuellement une liste accessible sous la carte ;
- une gestion propre des erreurs 404 et des coordonnées absentes.

## 9. Architecture d’harmonisation

### 9.1 Données canoniques

À conserver dans `observations` :

- taxon canonique ;
- instant/précision temporelle retenus ;
- localisation publique canonique et son statut ;
- nombre canonique ;
- statut de validation harmonisé ;
- lieu et remarques synthétiques publiables ;
- dates d’import et de rétention.

Colonnes additives proposées :

- `temporal_precision` (`datetime`, `date`, `unknown`) ;
- `location_status` ;
- `country_code`, `country_name` ;
- `region_name` ;
- `department_code`, `department_name` ;
- `municipality_code`, `municipality_name` ;
- `locality_name` ;
- `geography_resolution_method` (`source`, `postgis`, `reverse_geocoder`, `none`) ;
- `geography_resolved_at`.

Conserver l’index GIST existant et ajouter des index B-tree sur `department_code`, `municipality_code`, `location_status` et éventuellement `(taxon_id, observed_at)`.

### 9.2 Données par source

À conserver dans `observation_sources` :

- identités et liens existants ;
- noms taxonomiques bruts ;
- date/heure et précision source ;
- coordonnées publiques et incertitude source ;
- confidentialité/précision source ;
- localité, observateur, nombre, qualité et remarques source ;
- stade, sexe, comportement ;
- licence et dates source ;
- brut interne.

Colonnes proposées :

- `source_scientific_name`, `source_vernacular_name` ;
- `source_observed_at`, `source_temporal_precision` ;
- `public_latitude`, `public_longitude`, `coordinate_uncertainty_m` ;
- `location_status`, `source_location_precision`, `source_location_name` ;
- `source_observer_name`, `observer_is_public` ;
- `source_individual_count`, `source_validation_status` ;
- `life_stage`, `sex`, `behavior`, `remarks`.

Pour les médias, une table `observation_source_media` est préférable à une colonne JSON si l’interface doit les afficher : type, URL, page source, licence, attribution et ordre. Elle évite de relire le brut.

### 9.3 Données dérivées

La résolution administrative doit enregistrer :

- valeur calculée ;
- méthode ;
- version du référentiel ;
- date de calcul ;
- éventuellement le niveau de confiance.

Une table `geographic_reference_versions` et des tables/colonnes PostGIS pour communes, départements et régions rendraient les résultats recalculables. Une solution plus simple peut d’abord mettre les champs dérivés dans `observations`, mais la version du référentiel doit rester traçable.

### 9.4 DTO cible

Faire évoluer ou remplacer `NormalizedOccurrence` par un DTO dont les sous-objets empêchent les confusions :

```text
NormalizedObservation
├── sourceIdentity
├── sourceTaxon
├── temporal
├── publicLocation
├── privacy
├── occurrenceFacts
├── attribution
├── media[]
└── rawData (interne uniquement)
```

Chaque connecteur doit produire le même contrat, avec `null` explicite lorsqu’une donnée manque et une preuve de confidentialité lorsqu’elle existe.

## 10. Déduplication multisource

### 10.1 Mécanisme actuel

Niveau 1 — idempotence source :

```text
UNIQUE(source, source_occurrence_id)
```

Une réimportation met à jour la provenance et son observation canonique.

Niveau 2 — fusion certaine par origine :

- `DeduplicationHints` extrait l’identifiant d’une URL iNaturalist ;
- GBIF et iNaturalist partagent alors `origin_key = inaturalist:{id}` ;
- une nouvelle provenance avec la même clé est rattachée automatiquement à l’observation existante.

Niveau 3 — candidats seulement :

- même `taxon_id` ;
- date/heure à ±1 minute ;
- latitude à ±0,002 degré ;
- longitude à ±0,002 degré ;
- cinq candidats maximum ;
- score fixe `0,75` et statut `pending`.

Ces candidats ne sont pas fusionnés automatiquement.

### 10.2 Limites

- aucun calcul de distance géodésique réel dans la détection ;
- la boîte de 0,002 degré varie en mètres selon la latitude ;
- aucun usage de l’incertitude des coordonnées ;
- aucun observateur, nombre, dataset ou similarité de lieu ;
- aucune règle particulière pour une position masquée ;
- aucune identité commune connue entre Faune-France et les autres sources ;
- `canonical_identifiers` est stocké mais n’est pas parcouru globalement pour toutes les correspondances ;
- aucun écran de validation des candidats ;
- les valeurs canoniques ne disposent pas d’une provenance champ par champ.

### 10.3 Affichage de plusieurs provenances

Une page détail doit afficher une seule observation canonique puis toutes ses `observation_sources`. Chaque provenance conserve :

- son identifiant ;
- son lien ;
- sa licence ;
- son nom taxonomique brut ;
- ses valeurs temporelles et géographiques publiables ;
- sa date d’import.

Les divergences doivent être affichables sans écraser les données originales. La sélection d’une valeur canonique doit suivre des règles documentées, pas « la dernière source réimportée ».

## 11. Plan d’implémentation en quatre lots

### Lot 1 — mutualiser les critères

**Migrations**

- aucune nécessaire si l’objet reste applicatif ;
- éventuellement supprimer plus tard la nécessité des dates factices à la création d’une surveillance, sans migration immédiate.

**Backend**

- introduire `ObservationQueryCriteria` et un validateur commun ;
- séparer période absolue et période glissante ;
- séparer capacités source et contexte d’exécution ;
- garder `SearchDefinition` comme adaptateur transitoire.

**Frontend**

- extraire les composants communs taxon, zone, départements et sources ;
- réutiliser l’autocomplétion d’adresse dans l’exploration ;
- invalider l’estimation dès qu’un critère change.

**Tests**

- mêmes critères → même définition pour exploration et surveillance ;
- validations zone/taxon/source ;
- période absolue et glissante ;
- outre-mer et portails Faune.

**Risques**

- régression des payloads existants ;
- confusion entre mode visuel adresse et zone backend rayon.

**Compatibilité**

- additive avec adaptateurs aux payloads actuels.

### Lot 2 — Faune-France dans les imports ponctuels

**Migrations**

- `external_fetch_jobs.import_job_id` nullable/unique ;
- éventuellement contextes de collection si non portés par `import_jobs`.

**Backend**

- coordinateur multi-mécanismes ;
- création d’un import Faune de façade puis d’une tâche externe ;
- propagation états/compteurs/erreurs ;
- annulation d’une tâche non réservée ;
- estimation explicitement indisponible.

**Frontend**

- case Faune-France et raisons d’indisponibilité communes aux surveillances ;
- résultat d’estimation « indisponible » ;
- suivi du job dans `/imports`.

**Bot**

- pas de changement de recherche nécessaire pour départements ou point/rayon ;
- éventuellement prise en charge d’un contexte d’import uniquement si le payload métier évolue.

**Tests**

- import ponctuel départements ;
- import ponctuel point/rayon ;
- absence de mapping/rang invalide/scope descendant ;
- portail ultramarin ;
- lots, idempotence, annulation et échec.

**Risques**

- absence de compteur préalable ;
- volume inconnu jusqu’à la pagination ;
- concurrence avec les surveillances sur le même compte.

**Compatibilité**

- additive ; les `import_jobs` existants restent valides.

### Lot 3 — enrichissement et confidentialité

**Migrations**

- colonnes canoniques et par provenance décrites en section 9 ;
- table des médias ;
- contraintes sur `location_status` ;
- index ;
- migration de données prudente : statut `unavailable` sans coordonnées, sinon `approximate` tant que la précision n’est pas prouvée ;
- recalcul cohérent de `geometry` lors des mises à jour.

**Backend**

- DTO `NormalizedObservation` ;
- enrichissement des trois normaliseurs ;
- politique de sélection canonique ;
- protection du brut ;
- service de résolution géographique asynchrone.

**Frontend**

- libellés de confidentialité communs ;
- ne jamais afficher un point « exact » par défaut.

**Tests**

- coordonnées exactes/approximatives/masquées/absentes pour chaque source ;
- absence de secrets et de coordonnées privées dans l’API ;
- précision temporelle ;
- conflits multisources ;
- mise à jour de la géométrie PostGIS.

**Risques**

- exposition involontaire de données sensibles ;
- qualité hétérogène des sources ;
- rétro-remplissage impossible sans réexaminer certains bruts.

**Compatibilité**

- migrations additives et valeurs conservatrices pour l’historique.

### Lot 4 — API et page détail

**Migrations**

- celles du lot 3 doivent être terminées ;
- référentiel administratif PostGIS séparé si la résolution locale est incluse.

**Backend**

- `ObservationDetailResource` sans brut ;
- pagination/ressource de liste cohérente ;
- provenance multiple ;
- éventuellement endpoint GeoJSON public filtré.

**Frontend**

- `/observations/[id].vue` ;
- carte détail MapLibre et cercle d’incertitude ;
- liens depuis carte et future liste ;
- états masqué/absent et sections décrites plus haut.

**Tests**

- contrat API ;
- 404 ;
- données sensibles absentes ;
- détail avec plusieurs provenances ;
- carte avec point, cercle, masque ou absence.

**Risques**

- la carte rend crédible une précision qui n’existe pas ;
- tuiles OpenStreetMap externes et confidentialité des consultations ;
- gros `raw_data` si la ressource n’est pas maîtrisée.

**Compatibilité**

- la route Laravel existe déjà mais sa réponse devra être versionnée ou remplacée prudemment pour ne pas casser un éventuel consommateur.

## 12. Décisions requises avant l’implémentation

Les quatre lots ne devraient pas être confiés dans un unique prompt sans décision préalable. Les lots 1 et 2 peuvent être enchaînés. Avant les lots 3 et 4, il faut décider :

1. la politique exacte de confidentialité et quels marqueurs source interdisent l’affichage d’un point ;
2. si `raw_data` doit être réservé à une API d’administration authentifiée ;
3. si l’application accepte un import Faune-France sans estimation de volume ;
4. quel référentiel officiel et quelle version seront utilisés pour les communes/contours administratifs ;
5. si le géocodage inverse externe est autorisé, et uniquement pour quelles coordonnées.

La confidentialité est un blocage : une page détail avec carte ne doit pas précéder cette décision.

## 13. Résumé demandé

- **Pourquoi Faune-France n’apparaît pas dans l’exploration :** absence de case Nuxt, factory appelée sans autorisation Faune et mécanisme d’import limité aux `import_jobs` GBIF/iNaturalist.
- **À mutualiser :** taxon, scope, période, zone, départements, adresse, disponibilité des sources et validation ; séparer ces critères des réglages de planification/exécution.
- **Champs manquants pour le détail :** confidentialité/précision, géographie structurée, précision temporelle, faits biologiques, noms et valeurs par provenance, médias persistés.
- **Commune/département/région :** utiliser d’abord les libellés source, puis une résolution locale PostGIS sur de vrais contours officiels ; géocodage inverse externe seulement en fallback caché pour les points publics.
- **Carte :** créer une ressource API sûre et un composant de détail MapLibre avec marqueur, cercle d’incertitude ou message de masquage ; ajouter des liens depuis la carte actuelle.
- **Ordre recommandé :** critères communs → import ponctuel Faune-France → normalisation/confidentialité → API et page détail.
