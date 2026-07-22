# Architecture V0

Laravel reste à la racine afin de préserver les connecteurs et commandes de l’audit. Le client Nuxt se trouve dans `front/`. Les deux applications communiquent par l’API JSON `/api`; CORS n’autorise par défaut que `http://localhost:3000`.

Le backend est organisé autour de quatre étapes : une `SearchDefinition` portable, sa traduction en une ou plusieurs `OccurrenceQuery`, les connecteurs source, puis `OccurrencePersister`. Une liste de départements produit une requête par département et par source. Cela donne une sémantique OR sans supposer que GBIF et iNaturalist encodent de la même façon plusieurs identifiants administratifs.

Les importations sont des jobs Laravel dans la queue `database`. Le scheduler inspecte chaque minute les surveillances arrivées à échéance. PostgreSQL est donc l’unique service d’état de la V0.

Le frontend utilise Nuxt 4/Vue/TypeScript. MapLibre affiche un fond raster OpenStreetMap, un point par observation canonique, des grappes et un libellé de date à fort zoom. Il ne demande et n’affiche aucun média.

## Sources

- GBIF et iNaturalist : recherche, compteur et import actifs.
- Faune-France : endpoint entrant seulement, protégé par jeton.
- OBIS : connecteur d’audit conservé, désactivé par défaut, sans workflow d’import.
- TAXREF distant, eBird, GeoNature, OpenObs et SINP : hors V0 conformément à l’audit et au périmètre.

## Limites structurelles

Les dix départements utilisent une emprise rectangulaire simplifiée pour le filtrage local et les identifiants natifs vérifiés pour les requêtes externes. Les calculs locaux point/rayon sont exacts après un préfiltre rectangulaire, mais faits en PHP dans cette V0. Une prochaine version devra déplacer les deux formes de filtrage vers PostGIS et stocker les contours administratifs officiels complets.
