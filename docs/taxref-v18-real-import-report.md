# Rapport d’import réel de TAXREF v18

Import terminé le 22 juillet 2026 à 22:56:37 UTC. Durée mesurée par l’importeur : 38,815 secondes.

La version TAXREF v18 est importée uniquement dans `taxref_records` et reste strictement `staging`. Aucun taxon canonique, nom indexé ou chemin hiérarchique n’a été créé.

## Sauvegarde préalable

Une sauvegarde PostgreSQL au format custom a été créée avant toute écriture :

```text
storage/app/backups/observations-before-taxref-v18-20260722-225523.dump
taille : 156811 octets
SHA-256 : e87989e47ef44977e96018554ccf415dd57a7459d88c47f56f772787ad8c7f2f
```

Le fichier est non vide, sa table des matières a été relue avec `pg_restore --list` et son répertoire est ignoré par Git. Aucun mot de passe n’a été placé dans la commande ou dans ce rapport.

## Source vérifiée

| Élément | Valeur |
|---|---|
| Archive | `storage/app/taxref/source/TAXREF_v18_2025.zip` |
| URL officielle | `https://assets.patrinat.fr/files/referentiel/TAXREF_v18_2025.zip` |
| Taille archive | 60 582 042 octets |
| SHA-256 archive | `a6963ea1a3baec3220f0bf76b43eaa9b49d0c0eecd5ab72294b760adf78897a7` |
| Fichier extrait | `storage/app/taxref/extracted/v18/TAXREFv18.txt` |
| Taille fichier | 317 126 709 octets |
| SHA-256 fichier | `97a79024b3c9723467cf0a978b02c02b0f734bafb47136a94d1ff67a49155c0a` |
| Format | UTF-8, TSV, guillemets doubles, sans BOM, 44 colonnes |

Seul `TAXREFv18.txt` a été extrait dans le nouveau répertoire. L’archive et le fichier extrait sont ignorés par Git.

Aucune date n’a été passée avec `--published-on` : l’audit n’a pas établi de date de publication officielle suffisamment explicite. `published_on` reste donc `null`.

## Commande exécutée

```bash
php artisan taxref:import storage/app/taxref/extracted/v18/TAXREFv18.txt \
  --version=18 \
  --source-uri="https://assets.patrinat.fr/files/referentiel/TAXREF_v18_2025.zip" \
  --archive=storage/app/taxref/source/TAXREF_v18_2025.zip \
  --sha256="a6963ea1a3baec3220f0bf76b43eaa9b49d0c0eecd5ab72294b760adf78897a7" \
  --file-sha256="97a79024b3c9723467cf0a978b02c02b0f734bafb47136a94d1ff67a49155c0a"
```

`--sha256` vérifie l’archive officielle lorsque `--archive` est fourni. `--file-sha256` vérifie séparément le fichier extrait. Sans `--archive`, le comportement historique reste compatible et `--sha256` vérifie directement le fichier donné.

## Résultat de l’import

```text
Lignes lues : 708685
Noms acceptés : 300377
Synonymes : 408308
Rangs reconnus : 643681
Rangs inconnus : 65004
Lignes invalides : 0
Enregistrements importés : 708685
Lots écrits : 2835
Durée : 38.815 s
Mémoire maximale : 77594624 octets (74 MB)
```

La commande fonctionne en streaming, par lots de 250 et dans une transaction. Les signaux contrôlés `SIGINT` et `SIGTERM` déclenchent une erreur, un rollback des records et le passage de la version à `failed`. La contrainte unique `(provider, version)` empêche une relance de v18 de créer une seconde version ou des doublons.

## Version et métadonnées

Une seule version correspond à `(taxref, 18)` :

```text
provider     : taxref
version      : 18
status       : staging
published_on : null
records      : 708685
```

Les métadonnées JSON enregistrent : noms et tailles de l’archive et du fichier, URL officielle, SHA-256 de l’archive et du fichier, format TSV UTF-8, absence de BOM, 44 colonnes, taille de lot, nombre de lignes, statistiques, durée et pic mémoire. `imported_at` est renseigné. La version n’a jamais été activée.

## Contrôles de cohérence

| Contrôle | Résultat |
|---|---:|
| Records v18 | 708 685 |
| `accepted` / `CD_NOM = CD_REF` | 300 377 |
| `synonym` / `CD_NOM != CD_REF` | 408 308 |
| `cd_nom` nuls | 0 |
| `cd_ref` nuls | 0 |
| Noms scientifiques obligatoires vides | 0 |
| Groupes de doublons `cd_nom` | 0 |
| Rangs internes renseignés | 643 681 |
| Rangs non mappés avec `rank_code = null` | 65 004 |
| Rangs non mappés sans `raw_data.RANG` | 0 |
| `cd_ref` distincts sans nom accepté correspondant | 0 |
| Lignes pointant vers un `cd_ref` absent | 0 |
| Parents `CD_SUP` absents du fichier diffusé | 8 |
| Noms acceptés sans parent | 2 |

Les chiffres accepté/synonyme et rangs reconnu/non mappé sont strictement identiques au dry-run.

Les deux racines acceptées sans parent sont `Biota` (`349525`) et `Virus` (`824277`). Les huit relations parentales absentes sont conservées sans correction :

| Taxon | `cd_nom` | Parent absent |
|---|---:|---:|
| Lepadoidea | 1043260 | 351102 |
| Scalpelloidea | 1043348 | 351102 |
| Nemostira martirei | 780270 | 780269 |
| Beloniella genistae | 870977 | 870529 |
| Utrechtiana arundinacea | 1043896 | 870740 |
| Bursaria truncatella | 1036025 | 1036024 |
| Valdensia heterodoxa | 1045113 | 1045112 |
| Dermosporidium granulosum | 1078375 | 1078373 |

## Échantillons en base

| Nom | `cd_nom` | `cd_ref` | `parent_cd_ref` | Rang brut | Rang interne | Statut |
|---|---:|---:|---:|---|---|---|
| Animalia | 183716 | 183716 | 349525 | `KD` | `kingdom` | accepted |
| Chordata | 185694 | 185694 | 838322 | `PH` | `phylum` | accepted |
| Aves | 185961 | 185961 | 838098 | `CL` | `class` | accepted |
| Mammalia | 186206 | 186206 | 838095 | `CL` | `class` | accepted |
| Tichodroma muraria | 3780 | 3780 | 198459 | `ES` | `species` | accepted |
| Vulpes vulpes | 60585 | 60585 | 198937 | `ES` | `species` | accepted |
| Papilio machaon | 54468 | 54468 | 670677 | `ES` | `species` | accepted |

`Reptilia` n’existe pas comme `LB_NOM` dans le fichier v18 diffusé et aucune ligne ne l’utilise dans la classification aplatie. Le concept accepté proche trouvé est `Sauropsida` (`cd_nom = cd_ref = 838096`, parent `838095`), avec le rang brut `CLAD`. Il reste volontairement sans rang interne plutôt que d’être transformé arbitrairement en `class`.

## Volumes PostgreSQL

Avant import, la base occupait 22 375 571 octets. Après import :

| Mesure | Octets | Taille lisible |
|---|---:|---:|
| Base complète | 948 898 963 | 905 MB |
| `taxref_records`, table et TOAST | 845 905 920 | 807 MB |
| Index de `taxref_records` | 80 691 200 | 77 MB |
| `taxref_records` total | 926 597 120 | 884 MB |
| Volume net ajouté à la base | 926 523 392 | 884 MB |

## Non-régression et exposition API

Les données historiques finales restent :

```text
taxa                    : 23
observations             : 11
taxon_source_mappings    : 21
records fixture existants: 9
```

`GET /api/dashboard` répond HTTP 200. `GET /api/taxa/search?q=Tichodroma` conserve son comportement historique et n’expose ni `taxref_records` ni la version v18. Ce GET a également reproduit son effet de bord connu en créant temporairement un taxon `Tichodroma` et deux mappings ; après vérification qu’ils n’avaient aucune référence, ces seules lignes produites par le contrôle ont été retirées pour restaurer exactement les compteurs historiques.

Les nouvelles colonnes et modèles TAXREF restent masqués dans les réponses API existantes. Nuxt et les connecteurs ne consomment pas cette version `staging`.

## Procédure de rollback

### Retrait ciblé de la version v18

Après vérification du statut et du nombre de records, une suppression explicite de la version supprime ses `taxref_records` par cascade :

```sql
BEGIN;
SELECT id, status FROM taxonomic_reference_versions
WHERE provider = 'taxref' AND version = '18'
FOR UPDATE;

DELETE FROM taxonomic_reference_versions
WHERE provider = 'taxref' AND version = '18'
  AND status IN ('staging', 'failed');
COMMIT;
```

Cette procédure est volontairement documentée mais n’a pas été exécutée.

### Restauration complète

En cas de besoin, arrêter les processus applicatifs qui écrivent en base puis restaurer la sauvegarde custom avec `pg_restore --clean --if-exists`, depuis `storage/app/backups/observations-before-taxref-v18-20260722-225523.dump`. La restauration complète n’a pas été exécutée.

Si une future tentative échoue et laisse une version `failed`, il faut d’abord inspecter son `metadata.error` et son nombre de records. La commande refusera une nouvelle version 18 tant que cette ligne existe ; elle ne doit jamais être supprimée silencieusement.

## Éléments toujours hors périmètre

- activation de v18 ;
- création ou rapprochement de taxons canoniques ;
- alimentation de `taxon_names` ;
- construction de `taxon_paths` ;
- exploitation des territoires ;
- modification des mappings source, de la recherche, des surveillances, de Nuxt ou des connecteurs.
