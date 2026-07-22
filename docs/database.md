# Base de données

La migration unique de la V0 active `postgis`, remplace la colonne temporaire `observations.geometry` par `geometry(Point, 4326)` et crée un index GiST. SQLite conserve une représentation GeoJSON texte uniquement pour les tests.

## Tables principales

- `taxa` et `taxon_source_mappings` : taxon canonique et identifiants GBIF/iNaturalist.
- `observations` : événement canonique, coordonnées WGS84, statut, dates d’import et de rétention.
- `observation_sources` : provenance brute, URL, licence, identifiant source unique et `origin_key` exact.
- `observation_deduplication_candidates` : rapprochements spatiaux/temporels proposés, jamais fusionnés automatiquement.
- `geographic_areas` : les dix départements V0, code INSEE, emprise simplifiée, GADM gid et iNaturalist place id.
- `data_collections`, `collection_observations`, `collection_coverages` : collections, rattachements et périodes réellement couvertes.
- `monitoring_rules`, `monitoring_rule_observations` : règles actives et observations détectées.
- `import_jobs`, `source_sync_states` : progression visible et emplacement de reprise réservé.
- `jobs`, `job_batches`, `failed_jobs` : queue Laravel sur base.

Une observation est supprimable après la durée de rétention seulement si elle n’est liée ni à une collection permanente ni à une surveillance active et si `retain_until` ne la protège pas. La commande `biodiversity:cleanup --dry-run` doit précéder une première suppression réelle.

Les géométries départementales du seeder sont volontairement des rectangles. Les identifiants natifs ont été vérifiés le 21 juillet 2026 sur les API publiques GBIF GADM et iNaturalist Places.
