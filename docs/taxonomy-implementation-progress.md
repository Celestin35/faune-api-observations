# Progression de l’implémentation taxonomique TAXREF v18

Dernière mise à jour : 22 juillet 2026.

## Phase 1 — Sauvegarde et garde-fous — terminée

- Base PostgreSQL vérifiée avec les conteneurs `app`, `postgres`, `queue` et `scheduler` actifs.
- Sauvegarde custom créée : `storage/app/backups/observations-before-taxonomy-canonicalization-20260722.dump`.
- Taille : 52 158 018 octets.
- SHA-256 : `0901681c20b781fdf100dd8f07e1bef0507d38415013bec6a6a5b7b99dd01344`.
- `pg_restore --list` validé : 273 entrées dans la table des matières.
- Espace disponible avant migration : 86 Gio sur 124 Gio ; estimation noms + chemins inférieure à 2 Gio, marge suffisante.
- Taille initiale de la base : 948 898 963 octets.

### Compteurs initiaux

| Table | Nombre |
| --- | ---: |
| `taxa` | 23 |
| `observations` | 11 |
| `observation_sources` | 17 |
| `monitoring_rules` | 1 |
| `data_collections` | 0 |
| `import_jobs` | 22 |
| `taxon_source_mappings` | 21 |
| `taxref_records` | 708 694, dont 708 685 pour TAXREF v18 |

Anomalies bloquantes : aucune. Les huit parents absents et les dix-sept taxons locaux non résolus sont connus et seront conservés explicitement.

Prochaine phase : décisions versionnées pour les 23 taxons historiques.

## Phase 2 — Décisions historiques — terminée

- `database/data/taxref/v18/local-taxa-decisions.csv` contient les 23 taxons.
- 6 décisions `map_taxref`, 11 `keep_local_outside_taxref`, 4 `keep_local_provisional`, 2 `keep_local_unresolved`.
- Aucun taxon supprimé ou fusionné.
- La commande `taxref:validate-local-decisions --version=18` valide le fichier sans écriture.

## Phase 3 — Schéma — terminée

- Migration `2026_07_22_000007_prepare_taxref_canonicalization` exécutée.
- Homonymes autorisés ; identité TAXREF partielle unique ajoutée.
- Mappings enrichis et code `faune_france` normalisé avec compatibilité applicative.
- Version, scope et libellé historique ajoutés aux surveillances, collections, couvertures et imports.
- Les 66 tests Laravel existants passaient après migration du schéma.

## Phase 4 — Taxons canoniques — terminée

- `taxref:canonicalize --version=18` exécutée en 36,22 secondes.
- 300 377 concepts canoniques uniques créés.
- 708 685 enregistrements acceptés ou synonymes reliés.
- 6 IDs historiques mis à jour en place ; 17 taxons locaux conservés explicitement.
- 8 parents officiels absents laissés à `NULL`.
- Observations et mappings historiques inchangés.

## Phase 5 — Noms — terminée

- 300 377 noms scientifiques acceptés.
- 402 988 synonymes scientifiques uniques.
- 49 522 noms vernaculaires français uniques.
- 752 887 lignes au total, soit exactement l’estimation.
- 33 015 taxons disposent d’un nom français préféré.
- Normalisation et extraction réalisées avec les services PHP existants.

## Phase 6 — Hiérarchie — terminée

- Construction récursive PostgreSQL en 87,52 secondes.
- 5 479 172 chemins, dont 300 377 réflexifs.
- 2 racines, 8 orphelins techniques, profondeur maximale 35, aucun cycle.
- Table : 330 047 488 octets ; index : 650 149 888 octets ; total : 980 295 680 octets.

## Phase 7 — Activation — terminée

- `taxref:health-check --version=18 --allow-staging` validé avant activation.
- Activation transactionnelle effectuée ; TAXREF v18 est `active`.
- `taxref:health-check --version=18` valide tous les contrôles, y compris les échantillons Animalia, Chordata, Aves, Mammalia, Tichodrome, Renard roux et Machaon.

## Phase 8 — Recherche locale et contrat API — terminée

- `GET /api/taxa/search` interroge exclusivement `taxon_names` et n'effectue plus aucun appel externe ni aucune écriture.
- Recherche validée sur les noms français, scientifiques, synonymes, sans accents et avec tolérance trigramme aux petites fautes.
- Le DTO stable expose le nom français prioritaire, le nom scientifique accepté, la correspondance, le rang, la lignée, TAXREF v18, le scope par défaut et la disponibilité des sources, sans `raw_data`.
- Cas réels vérifiés : Animaux/Animalia, Oiseaux/Aves, Mammifères/Mammalia, Amphibiens/Amphibia, Reptiles/Sauropsida et Tichodrome échelette/Tichodroma muraria.
- L'alias utilisateur « Reptiles » cible le clade TAXREF `Sauropsida` (CD_REF 838096), avec le scope `subtree`.
- Le nombre de taxons reste strictement identique avant et après les recherches : 300 394.

## Phase 9 — Scopes, connecteurs et interface Nuxt — terminée

- Les espèces utilisent `exact`; les rangs supérieurs et le clade des Sauropsides utilisent `subtree`.
- La recherche locale d'observations suit `taxon_paths` pour inclure tous les descendants d'un rang supérieur ; ce parcours est couvert par un test d'intégration.
- Les versions, scopes et libellés historiques sont conservés sur les surveillances, collections, couvertures et imports.
- GBIF et iNaturalist reçoivent leur identifiant source lorsqu'un mapping préféré validé existe ; les identifiants historiques du Tichodrome (`2484918` et `14840`) sont couverts par les tests.
- Faune-France n'est proposé que pour une espèce exacte possédant un mapping `faune_france` validé ; aucun rang supérieur n'est transmis au bot.
- L'import rattache d'abord une observation grâce au mapping source, puis par un nom canonique non ambigu ; les cas sans correspondance sûre restent locaux ou non résolus et ne sont pas fusionnés arbitrairement.
- Le composant Nuxt partagé `TaxonPicker` affiche en priorité le nom français, puis le nom scientifique, le rang, la lignée et le scope.
- L'exploration et la création de surveillance utilisent le nouveau contrat et transmettent `taxon_scope`.

## Validation finale — terminée

### Compteurs finaux

| Élément | Nombre |
| --- | ---: |
| Taxons totaux | 300 394 |
| Taxons canoniques TAXREF v18 | 300 377 |
| Taxons locaux hors TAXREF | 17 |
| Noms indexés | 752 887 |
| Chemins hiérarchiques | 5 479 172 |
| Observations | 11 |
| Sources d'observation | 17 |
| Mappings source | 21 |

- Base finale : 3 506 007 187 octets ; 84 Gio encore disponibles sur le volume local.
- `taxref:health-check --version=18` : tous les contrôles `OK`.
- Laravel : 67 tests réussis, 519 assertions.
- Bot Node/Playwright : 33 tests réussis ; TypeScript sans erreur.
- Nuxt : typecheck et build de production réussis. Le build signale seulement un chunk client supérieur à 500 ko, avertissement de performance préexistant et non bloquant.
- `vendor/bin/pint --test` et `git diff --check` réussis.
- `GET /api/dashboard` et `GET /api/taxa/search` répondent depuis le backend local sur le port 8000.

Anomalies bloquantes : aucune. Les huit orphelins techniques TAXREF et les dix-sept taxons locaux conservés sont explicites et contrôlés.
