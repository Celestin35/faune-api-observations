# Fondation TAXREF additive

Cette première phase prépare Laravel et PostgreSQL à recevoir un référentiel TAXREF versionné. Elle est strictement additive : les taxons, mappings, observations, surveillances et collections existants continuent d’utiliser leurs colonnes et leurs flux actuels.

La fixture `tests/Fixtures/Taxref/taxref-foundation.csv` est un jeu synthétique destiné aux tests. Elle ne constitue pas un extrait officiel ou complet de TAXREF.

## Tables ajoutées

### `taxonomic_reference_versions`

Une ligne décrit un fichier de référentiel : fournisseur, version, date, URI, SHA-256, métadonnées et état. Une version importée reste `staging`. Les autres états prévus sont `active`, `archived` et `failed`. L’index partiel autorise une seule version `active` par fournisseur.

### `taxon_ranks`

Référentiel local des rangs et de leurs codes TAXREF. Les rangs du règne à l’espèce sont sélectionnables. La sous-espèce est reconnue mais reste non sélectionnable. `TaxonRankSeeder` peut être relancé sans créer de doublon.

### `taxref_records`

Miroir versionné des lignes du fichier source. Cette table conserve `cd_nom`, `cd_ref`, le parent, le nom, l’auteur, le rang normalisé, le statut accepté/synonyme et toute la ligne brute en JSONB.

Dans cette phase, `taxref_records.taxon_id` reste vide : importer une ligne TAXREF ne crée pas encore de taxon canonique.

### `taxon_names`

Structure prête à recevoir les noms scientifiques acceptés, synonymes et noms vernaculaires. `normalized_name` recevra une forme en minuscules, sans accents ni ponctuation parasite. PostgreSQL possède un index GIN trigramme et un index B-tree de préfixe sur ce champ.

La table reste vide lors de l’import préparatoire.

### `taxon_paths`

Table de fermeture versionnée pour les liens ancêtre/descendant. Une profondeur `0` représente le taxon lui-même. Les index couvrent les recherches d’ancêtres, de descendants et par profondeur.

La commande préparatoire ne remplit pas cette table.

## Différence entre `taxa` et `taxref_records`

`taxa` reste la table canonique interne de l’application. Ses anciennes colonnes `scientific_name`, `vernacular_name`, `rank` et `classification` sont intactes et restent utilisées par l’API actuelle.

`taxref_records` représente fidèlement les lignes d’un fichier et d’une version TAXREF donnés. Plusieurs lignes, notamment les synonymes, pourront plus tard être reliées au même `taxa.id`.

Les nouvelles colonnes nullable de `taxa` préparent ce lien sans migrer les taxons existants : version et identifiant TAXREF, rang contrôlé, parent, nom accepté, auteur, nom français préféré, état, fusion et enregistrement TAXREF courant.

## Extensions PostgreSQL

La migration active de manière idempotente :

- `pg_trgm`, utilisé par l’index de similarité de `taxon_names.normalized_name` ;
- `unaccent`, disponible pour les traitements futurs.

Aucun index fonctionnel n’appelle directement `unaccent()`. Le normaliseur PHP est chargé de produire une valeur stable et indexable.

## Normalisation des noms

`TaxonNameNormalizer` transforme notamment :

```text
Tichodrome échelette       → tichodrome echelette
  Tichodroma   muraria     → tichodroma muraria
```

Il retire aussi la ponctuation parasite et normalise les espaces.

## Commande préparatoire

```bash
php artisan taxref:import chemin/vers/taxref.csv \
  --version="fixture-1" \
  --published-on=2026-07-22 \
  --source-uri="fixture://taxref" \
  --sha256=<somme-optionnelle>
```

Options :

- `--version=` : obligatoire ; identifie la version chez le fournisseur `taxref` ;
- `--published-on=` : date facultative `YYYY-MM-DD` ;
- `--source-uri=` : origine facultative ;
- `--archive=` : archive officielle facultative ; lorsqu’elle est fournie, `--sha256` vérifie cette archive ;
- `--sha256=` : somme attendue facultative de l’archive, ou du fichier en l’absence de `--archive` ;
- `--file-sha256=` : somme attendue facultative du fichier extrait ;
- `--dry-run` : lecture, validation et statistiques sans aucune écriture.

La somme réelle du fichier est enregistrée même lorsque `--sha256` n’est pas fourni. Une version réussie reste `staging` et reçoit `imported_at`. Une erreur transactionnelle annule les enregistrements et marque la version `failed`. La commande n’active jamais une version.

### Format accepté

CSV ou TSV, détecté depuis l’en-tête. Colonnes obligatoires et alias reconnus :

| Valeur | En-têtes acceptés |
|---|---|
| Identifiant du nom | `CD_NOM` |
| Identifiant accepté | `CD_REF` |
| Nom scientifique | `LB_NOM` ou `SCIENTIFIC_NAME` |
| Rang | `RANG`, `RANK` ou `RANK_CODE` |

Colonnes facultatives :

- parent : `CD_SUP` ou `PARENT_CD_REF` ;
- auteur : `LB_AUTEUR` ou `AUTHORSHIP` ;
- nom vernaculaire brut : `NOM_VERN` ou `VERNACULAR_NAME`.

`cd_nom = cd_ref` produit un nom `accepted`; une différence produit `synonym`. Un rang inconnu n’annule pas la ligne : `rank_code` reste vide et la valeur source demeure dans `raw_data`. Une ligne invalide est comptée puis ignorée. Le traitement utilise des lots de 250 lignes et ne charge pas le fichier complet en mémoire.

Le lecteur est validé avec le format officiel tabulé de TAXREF v18 tout en conservant le support de la fixture CSV. Il utilise `CD_SUP` comme parent direct et affiche la distribution de tous les codes `RANG`, dont celle des rangs non mappés. L’analyse complète de la diffusion v18 et le dry-run officiel sont consignés dans `docs/taxref-v18-import-audit.md`.

## Ce qui reste volontairement hors périmètre

- téléchargement ou import du fichier officiel complet ;
- activation d’une version ;
- création ou migration des taxons canoniques ;
- remplissage de `taxon_names` ;
- création des noms français et synonymes utilisables dans la recherche ;
- construction de `taxon_paths` ;
- rapprochement des taxons existants ;
- modification de `/api/taxa/search`, Nuxt ou des connecteurs ;
- utilisation des nouvelles colonnes par les observations et surveillances.

La prochaine étape devra importer une version officielle contrôlée, créer les concepts canoniques, produire noms et hiérarchie, puis rapprocher explicitement les taxons existants avec gestion des ambiguïtés.
