# Audit des API de biodiversité

> Mise à jour V0 (21/07/2026) : les conclusions ci-dessous sont conservées. GBIF et iNaturalist alimentent désormais le flux fonctionnel limité; OBIS reste optionnel et désactivé par défaut. Voir [architecture.md](architecture.md) et [import-process.md](import-process.md).

Audit réalisé le **20 juillet 2026** depuis la France. Il combine documentation officielle, requêtes GET de très faible volume et tests Laravel à réponses simulées. Les chiffres ci-dessous sont des instantanés, pas des garanties de contenu futur.

## Cadre de l’essai

- Aucun média ni export n’a été téléchargé.
- Une page contient au plus trois occurrences dans les smoke tests ; les compteurs utilisent zéro ou une occurrence.
- Le client envoie un `User-Agent` configurable, espace les appels de 500 ms par défaut et retente au maximum trois fois les statuts 429, 500, 502, 503 et 504 avec 200/500/1 000 ms de délai.
- Les en-têtes dont le nom contient `rate`, `quota` ou `retry-after` sont journalisés sans leur attribuer une signification non documentée.
- Les tests automatisés emploient `Http::fake()` : ils ne dépendent pas du réseau.
- TAXREF est un référentiel taxonomique, pas une source d’occurrences. Les filtres géographiques et temporels ne s’y appliquent donc pas.

## Résultats des appels réels

| Essai minimal | Résultat observé |
|---|---|
| GBIF, `Tichodroma muraria`, France, `limit=0` | HTTP 200, `count=22936` |
| GBIF, même taxon, cinq ans, `limit=0` | HTTP 200, `count=2199` |
| GBIF, GADM Hérault `FRA.11.8_1` | HTTP 200, `count=923` |
| GBIF, GADM Occitanie `FRA.11_1` | HTTP 200, `count=3666` |
| iNaturalist, `Tichodroma muraria`, `place_id=6753` (France) | HTTP 200, `total_results=470` |
| iNaturalist, résolution du lieu `6753` | HTTP 200, nom `France` |
| iNaturalist, recherche de lieu Hérault | HTTP 200, `place_id=30185` trouvé |
| OBIS, `Tichodroma muraria` | HTTP 200, `total=0`, erreur fonctionnelle `NAME_NOT_FOUND` attendue pour un oiseau terrestre |
| OBIS, `Delphinus delphis`, polygone France | HTTP 200, `total=19615` et un résultat demandé |
| eBird sans clé | HTTP 403, corps vide |
| TAXREF historique `/api/taxa/search` | HTTP 302 vers `https://inpn.mnhn.fr` |
| TAXREF sous `/taxref-web/api/taxa/search` | HTTP 200 mais paramètres `scientificNames`, `page` et `size` ignorés ; réponse massive et hors sujet |
| TAXREF fuzzy match testé | HTTP 404 |
| Démo GeoNature, trois routes API plausibles | HTTP 404 pour les trois |

Une première requête iNaturalist par bounding box France large avait aussi inclus une observation suisse : une bbox rectangulaire n’est pas une frontière nationale. Le connecteur emploie donc le `place_id=6753` lorsqu’il reçoit `country=FR`.

## 1. GBIF

- **Documentation :** [API générale](https://techdocs.gbif.org/en/openapi/), [Occurrence API](https://techdocs.gbif.org/en/openapi/v1/occurrence), [Species API](https://techdocs.gbif.org/en/openapi/v1/species).
- **Authentification :** aucune pour la recherche publique. Compte GBIF requis pour les téléchargements asynchrones.
- **Clé :** non.
- **Quota :** aucun débit fixe garanti. GBIF documente un possible HTTP 429 selon la charge et recommande un `User-Agent` permettant de contacter le client. Les en-têtes `x-varnish-rli` et `x-varnish-rlg` ont été vus mais ne sont pas interprétés, faute de sémantique officielle relevée.
- **Fréquence choisie :** au maximum 2 requêtes/s dans ce prototype ; réduire en cas de 429. Pour un travail de plus de quelques minutes ou volumineux, utiliser le téléchargement asynchrone et son DOI.
- **Pagination :** `limit`/`offset`, 300 résultats maximum par page, et `offset + limit` limité à 100 000. Les téléchargements sont nécessaires au-delà.
- **Géographie :** `geometry` WKT pour bbox/polygone. Pas de rayon natif validé : le connecteur fabrique un polygone de 24 sommets autour du point. `gadmGid` a fonctionné pour un département et une région. `country=FR` fonctionne.
- **Taxonomie :** le connecteur appelle `/species/match`, puis recherche par `taxonKey`, ce qui inclut les descendants. C’est nécessaire : `scientificName=Animalia` testé directement donnait zéro, alors qu’un nom littéral n’est pas un filtre de clade fiable. Espèce, genre, famille et règne sont ainsi couverts.
- **Temps :** `eventDate=debut,fin`. Les fenêtres 24 h, 7 j, 30 j, personnalisée et cinq ans utilisent toutes le même filtre inclusif.
- **Compteur :** oui, `occurrence/search?limit=0` retourne `count` sans occurrence.
- **Données utiles :** `key` GBIF, `occurrenceID`, `datasetKey`, taxonomie/classification, `eventDate`, `created` lorsqu’il existe, `modified`, `lastCrawled`, coordonnées et incertitude, effectif, statut, observateur, licence, `references`, métadonnées des médias.
- **Licence :** par occurrence/dataset (`CC0`, `CC BY`, `CC BY-NC` typiquement). Les médias peuvent avoir une licence distincte ; seuls leurs liens sont gardés.
- **Problèmes :** données agrégées donc doublons inter-datasets possibles ; noms vernaculaires et dates de création inégalement présents ; dates de crawl GBIF distinctes des dates de publication d’origine.
- **Verdict :** **utilisable et recommandé** comme agrégateur principal.

## 2. iNaturalist

- **Documentation :** [API v1](https://api.inaturalist.org/v1/docs/), [pratiques recommandées](https://www.inaturalist.org/pages/api+recommended+practices), [développeurs](https://www.inaturalist.org/pages/developers).
- **Authentification :** lecture des observations publiques sans authentification. JWT/OAuth pour les actions ou données privées ; inutile ici.
- **Clé :** non pour la lecture publique.
- **Quota :** recommandation officielle d’environ 1 requête/s et 10 000 requêtes/jour ; maximum annoncé de 100/minute. Dépassement possible en HTTP 429.
- **Fréquence choisie :** 500 ms dans la configuration commune, mais **1 000 ms conseillé en production pour cette source**.
- **Pagination :** `page`/`per_page`, jusqu’à 200 observations par page ; accès paginé généralement limité aux 10 000 premiers résultats. Pour davantage : export, `id_above`, ou archive iNaturalist publiée dans GBIF.
- **Géographie :** rayon natif `lat`/`lng`/`radius`, bbox `swlat`/`swlng`/`nelat`/`nelng`, zones par `place_id`. France `6753` et Hérault `30185` ont été vérifiés. Une table fiable codes INSEE → `place_id` reste à construire.
- **Taxonomie :** `taxon_name` sélectionne le taxon et ses descendants ; `taxon_id` serait préférable après résolution pour traiter les homonymes. Espèce, genre, famille et `Animalia` sont exprimables.
- **Temps :** `d1`/`d2`, ce qui couvre les cinq fenêtres demandées.
- **Compteur :** oui, `total_results` avec `per_page=1`. Une occurrence est tout de même renvoyée : il n’existe pas de mode zéro résultat validé.
- **Données utiles :** ID et UUID, taxon et ancêtres, noms, `time_observed_at`, `created_at`, `updated_at`, point public, précision, qualité (`casual`, `needs_id`, `research`), utilisateur, licence, URI, liens photos/sons.
- **Licence :** propre à l’observation ; elle peut être absente (`license_code=null`). Les médias ont leur licence propre et ne sont pas téléchargés.
- **Problèmes :** coordonnées sensibles obscurcies ; effectif individuel absent du modèle principal ; dataset source absent ; noms communs localisés selon le contexte ; bbox ≠ frontière nationale.
- **Verdict :** **utilisable et recommandé** en source directe complémentaire, notamment pour les observations très récentes et leur état de validation.

## 3. TAXREF

- **Documentation :** [documentation TAXREF-Web](https://taxref.mnhn.fr/taxref-web/api/doc), [présentation du référentiel](https://inpn.mnhn.fr/programme/referentiel-taxonomique-taxref).
- **Authentification / clé :** historiquement aucune pour la lecture ; le comportement courant n’a pas permis de confirmer un service exploitable.
- **Quota / fréquence :** non documentés de manière vérifiable pendant cet audit ; aucune valeur inventée.
- **Pagination annoncée par les clients existants :** `page`/`size`, ancien maximum annoncé 5 000, mais ces paramètres ont été ignorés sur la route répondant en 2026.
- **Filtres :** noms scientifiques/vernaculaires, rangs, territoires et habitats sont annoncés. Le filtrage réel n’a pas fonctionné pendant l’essai.
- **Occurrences, géographie, temps, compteur :** non applicables : TAXREF décrit les taxons, leur nomenclature, leurs statuts et leur répartition, pas les observations unitaires.
- **Données attendues :** `cdNom`, `cdRef`, nom, auteur, rang, validité, habitat, présence par territoire et liens taxonomiques.
- **Licence :** à vérifier à nouveau dans les conditions de diffusion de la version TAXREF effectivement consommée.
- **Problèmes :** documentation Swagger pointe vers un schéma devenu 404 ; ancienne base `/api` redirigée ; route alternative silencieusement non filtrée. Une réponse HTTP 200 ne suffit donc pas à déclarer le service sain.
- **Verdict :** **non utilisable actuellement via l’API testée**. Aucun `TaxrefConnector.php` n’est créé conformément à la règle « seulement si réellement accessible ». Retester avec le MNHN ; prévoir en secours l’import versionné du jeu officiel, sans import massif durant cette phase.

## 4. eBird

- **Documentation :** [eBird API 2.0](https://documenter.getpostman.com/view/664302/S1ENwy59/), [accès aux données eBird](https://support.ebird.org/en/support/solutions/articles/48000838205-download-ebird-data).
- **Authentification / clé :** en-tête `x-ebirdapitoken` obligatoire. Sans clé, HTTP 403 confirmé.
- **Quota / fréquence :** aucun nombre officiel fiable n’a été trouvé dans les documents consultés ; ne pas en fabriquer. Adopter 1 requête/s puis suivre les erreurs et éventuels en-têtes avec une vraie clé.
- **Pagination :** les routes « recent observations » sont des sorties récentes et plafonnées par `maxResults`, pas une pagination historique générale.
- **Géographie :** recherches par région eBird et autour d’un point (`lat`, `lng`, `dist`). Pas de bbox arbitraire validée dans cette phase.
- **Taxonomie :** code espèce eBird, donc résolution préalable du nom nécessaire. Source limitée aux oiseaux.
- **Temps :** paramètre récent `back`, jusqu’à 30 jours sur les routes récentes. Les cinq dernières années nécessitent l’eBird Basic Dataset, pas l’API récente.
- **Compteur :** aucun compteur total séparé validé ; compter la liste plafonnée ne donne pas le total réel.
- **Données :** espèce, nom, date, lieu/coordonnées, nombre, validation et identifiant de checklist selon la route ; moins riche qu’un export EBD.
- **Licence :** conditions eBird spécifiques ; ne pas supposer une licence Creative Commons par observation.
- **Problèmes :** clé absente, portée récente, résultats résumés (souvent la plus récente observation par lieu), pas de test autorisé complet.
- **Verdict :** **partiellement utilisable**, intéressant pour les oiseaux récents après obtention d’une clé. Aucun connecteur n’est créé sans accès réel authentifié.

## 5. OBIS

- **Documentation :** [API v3 / OpenAPI](https://api.obis.org/), [manuel d’accès](https://manual.obis.org/access.html).
- **Authentification / clé :** aucune pour la lecture testée.
- **Quota / fréquence :** aucun quota chiffré n’est documenté dans les sources consultées. Le prototype applique sa limite commune et les retries ciblés.
- **Pagination :** `size` et curseur `after` (UUID). Le schéma consulté ne donne pas de maximum pour `size` ; le projet limite volontairement ses pages à 20.
- **Géographie :** `geometry` WKT/GeoHash, donc bbox et polygone de rayon. `areaid` existe pour les zones marines. L’OpenAPI occurrence ne déclare **pas** `country` : le connecteur refuse ce filtre au lieu de l’envoyer silencieusement.
- **Taxonomie :** `scientificname` ou `taxonid` AphiaID ; descendants et noms sont alignés par WoRMS. Le Tichodrome est hors périmètre ; l’essai fonctionnel emploie `Delphinus delphis`.
- **Temps :** `startdate`/`enddate`, utilisables pour les cinq fenêtres demandées.
- **Compteur :** oui, champ `total` avec `size=1`.
- **Données utiles :** UUID OBIS et `occurrenceID`, UUID/nom de dataset, noms et AphiaID, classification, date, modification, coordonnées/incertitude, effectif si fourni, statut, observateur si fourni, licence et nombreux contrôles qualité marins.
- **Licence :** par dataset/occurrence ; citations et licences doivent être conservées.
- **Problèmes :** périmètre marin uniquement ; absence fréquente de création/publication et de médias dans la réponse occurrence ; certaines occurrences portent des flags qualité (`ON_LAND`, profondeur absente, etc.).
- **Verdict :** **partiellement utilisable**, recommandé uniquement pour enrichir la composante marine.

## 6. GeoNature

- **Documentation :** [documentation GeoNature](https://docs.geonature.fr/), [références API](https://docs.geonature.fr/api-references.html), [démo](https://demo.geonature.fr/geonature).
- **Authentification / clé :** dépend de l’instance, de sa configuration et des permissions CRUVED. La démo documente une connexion, mais aucune authentification n’a été contournée.
- **Quota / pagination :** propres à chaque instance ; aucun chiffre générique vérifiable.
- **Filtres potentiels :** la Synthèse sait filtrer géométrie, taxon, période, observateur et dataset, mais les routes et droits exposés sont configurables.
- **Compteur et données :** la structure `gn_synthese` contient notamment identifiant SINP, taxon, date, géométrie, dénombrement, validation, observateurs, dataset et médias. Leur exposition publique doit être vérifiée instance par instance.
- **Problèmes :** GeoNature est un logiciel déployé localement, pas un fournisseur unique. Les routes candidates testées sur la démo ont retourné 404 et la documentation indique que la génération de documentation de routes a connu des indisponibilités.
- **Verdict :** **non validé**. Aucun `GeonatureConnector.php` n’est créé. Pour une future instance choisie, rendre l’URL et le mapping configurables plutôt que supposer un schéma universel.

## 7. Faune-France

Aucun scraping, login automatique ou contournement n’a été tenté. `InboundOccurrenceConnector` et `FauneFranceInboundConnector` acceptent un JSON déjà normalisé sur `POST /api/biodiversity/faune-france/occurrences`. Le payload est validé, forcé à `source=faune-france`, renvoyé avec HTTP 202, puis oublié. Il faudra ajouter une authentification machine et une persistance avant toute exposition réelle.

## Couverture des champs communs

`✓` généralement présent, `~` optionnel/indirect, `—` non fourni par le modèle principal.

| Champ | GBIF | iNaturalist | OBIS |
|---|:---:|:---:|:---:|
| Identifiant plateforme | ✓ `key` | ✓ `id`, UUID | ✓ UUID `id` |
| Identifiant d’origine | ✓ `occurrenceID` | ✓ `id`/URI | ✓ `occurrenceID` |
| Nom scientifique / vernaculaire | ✓ / ~ | ✓ / ✓ | ✓ / ~ |
| Identifiant taxonomique | ✓ GBIF | ✓ iNat | ✓ AphiaID |
| Classification | ✓ | ✓ ancêtres selon réponse | ✓ |
| Date/heure observée | ✓ | ✓ | ✓ |
| Création / publication / modification | ~ / ~ crawl / ✓ | ✓ / ✓ / ✓ | ~ / — / ✓ |
| Coordonnées / précision | ✓ / ~ | ✓ publiques / ~ | ✓ / ~ |
| Nombre d’individus | ~ | — | ~ |
| Validation | ~ | ✓ qualité | ~ statut + flags QC |
| Observateur | ~ | ✓ | ~ |
| Dataset | ✓ | — | ✓ |
| Licence | ✓ | ~ peut être nulle | ✓ |
| URL source | ✓ | ✓ | ~ reconstruite |
| Médias sans téléchargement | ✓ | ✓ | — dans l’essai |

## Déduplication proposée

Ne jamais fusionner sur le seul identifiant taxonomique : GBIF, TAXREF, iNaturalist et WoRMS ont des espaces d’identifiants différents, et plusieurs observations légitimes concernent le même taxon.

Ordre de confiance proposé :

1. Égalité d’un `occurrenceID` global et stable après canonicalisation.
2. URL d’origine identique. Cas vérifié : GBIF renvoie `https://www.inaturalist.org/observations/{id}` dans `occurrenceID`/`references`; `DeduplicationHints` produit alors la même clé `inaturalist:{id}` que le connecteur direct.
3. Identifiant externe présent dans `identifiers`, ou triplet `dataset/source + institution/collection + catalogNumber` lorsqu’il existe.
4. Identifiant SINP permanent pour les sources françaises qui le diffusent.
5. À défaut, produire seulement un **candidat** par taxon accepté/crosswalké + date/heure + coordonnées dans l’incertitude + observateur/effectif compatibles. Une revue ou une règle propre au dataset doit confirmer la fusion.

Conserver toutes les provenances dans une future table de liens plutôt que supprimer la ligne secondaire. Garder le JSON brut permet d’améliorer les règles sans reperdre l’information.

## Tableau comparatif et recommandation

| Source | Lecture réelle | Auth | Point/rayon | Bbox | Département/région | Taxon hiérarchique | 5 ans | Compteur | Verdict V1 |
|---|---|---|---|---|---|---|---|---|---|
| GBIF | oui | non | polygone approché | oui | GADM testé | oui via `taxonKey` | oui | oui, zéro ligne | **prioritaire** |
| iNaturalist | oui | non (public) | natif | oui | `place_id` testé | oui | oui | oui, une ligne | **prioritaire** |
| TAXREF | service défaillant | non confirmé | n/a | n/a | territoire taxon seulement | oui en théorie | n/a | non pertinent | retester / import versionné |
| eBird | 403 sans clé | clé | natif | non validé | régions eBird | oiseaux/code espèce | non via API récente | non validé | phase ultérieure |
| OBIS | oui | non | polygone approché | oui | `areaid` marin | oui/WoRMS | oui | oui, une ligne | complément marin |
| GeoNature | démo 404 | instance | configurable | configurable | configurable | configurable | configurable | configurable | choisir une instance d’abord |
| Faune-France | entrée locale seulement | outil externe | selon payload | n/a | n/a | selon payload | selon payload | n/a | interface prête, pas d’automatisation |

### Choix recommandé pour la première version

1. **GBIF** pour la couverture, les datasets, `occurrenceID` et les téléchargements citables futurs.
2. **iNaturalist direct** pour la fraîcheur, la qualité communautaire et les liens source, avec déduplication explicite contre son dataset GBIF.
3. **TAXREF** comme référentiel français dès qu’un mode de diffusion stable est confirmé ; ne pas le traiter comme une source d’observations.
4. **OBIS** seulement pour les taxons marins.

### Risques restants

- stabilité et mode de diffusion TAXREF à clarifier avec le MNHN ;
- clé eBird, conditions de réutilisation et couverture historique à évaluer ;
- table versionnée de correspondance codes INSEE ↔ GADM ↔ iNaturalist `place_id` ↔ zones marines ;
- règles de licences, sensibilité et coordonnées floutées à appliquer champ par champ ;
- stabilité réelle des `occurrenceID` selon les datasets GBIF ;
- pagination par curseur OBIS et stratégie `id_above` iNaturalist à éprouver avant un volume moyen ;
- instance GeoNature pertinente et contrat API à choisir ;
- authentification machine, idempotence et stockage pour l’entrée Faune-France ;
- définition d’une politique de fusion auditée avant tout import historique.
