# Plan de création des taxons canoniques TAXREF v18

## Portée et garde-fous

Ce document prépare la canonicalisation de TAXREF v18, sans la réaliser. La commande d'analyse est strictement en lecture seule pour les données taxonomiques : elle compare les compteurs de `taxa`, `taxref_records`, `taxon_names`, `taxon_paths` et `taxon_source_mappings` avant et après son exécution. Elle ne crée ni taxon canonique, ni nom, ni chemin hiérarchique et ne modifie aucun rattachement existant.

Commande :

```bash
php artisan taxref:plan-canonicalization --version=18
```

Options :

```text
--output=/chemin       dossier de rapports (défaut : storage/app/taxref/reports/v18/)
--sample=20            nombre d'exemples détaillés dans le résumé JSON
--fail-on-ambiguity    code de sortie non nul si un taxon local est ambigu ou non résolu
```

Le dossier de rapports par défaut est ignoré par Git. Les CSV sont en UTF-8 avec BOM et restent lisibles dans un tableur.

## Résultat global

La version 18, actuellement en `staging`, contient 708 685 enregistrements :

- 300 377 concepts acceptés ;
- 408 308 enregistrements synonymes ;
- aucun enregistrement classé `other` ;
- 300 375 concepts avec un `CD_SUP` brut ;
- 298 185 concepts avec une autorité, soit 2 192 sans autorité ;
- 33 573 concepts acceptés dont au moins une ligne possède une cellule `NOM_VERN` non vide ;
- 20 049 concepts dont le rang TAXREF n'est pas encore relié à un `rank_code` applicatif.

Les rangs applicatifs reconnus couvrent notamment 212 012 espèces, 49 254 genres, 10 288 sous-espèces, 7 118 familles, 1 258 ordres, 291 classes, 99 embranchements et 8 règnes. Le détail exhaustif des 45 codes `RANG` bruts figure dans `canonical-concepts-summary.json`. Les rangs intermédiaires non encore mappés — sous-genres, tribus, sous-familles, variétés, formes, clades, etc. — doivent néanmoins être conservés dans la hiérarchie canonique.

## Homonymes et contrainte d'unicité

La comparaison applique d'abord `trim`, compacte les espaces internes et ignore la casse. Une comparaison sans accents est produite à titre informatif, mais ne sert pas à fusionner des concepts.

Résultat sur les noms acceptés :

- 1 295 groupes homonymes ;
- 2 607 concepts concernés ;
- 1 312 concepts supplémentaires entreraient en collision avec une unicité sur le seul nom ;
- les 1 295 groupes ont une graphie strictement identique après normalisation ;
- 437 groupes ont des autorités différentes ;
- 959 ont des rangs TAXREF différents ;
- 375 ont des lignées différentes ;
- aucun groupe supplémentaire n'apparaît uniquement après suppression des accents.

Conclusion : la contrainte actuelle `UNIQUE (taxa.scientific_name)` bloque la canonicalisation et doit être supprimée avant le chargement. Un nom scientifique n'identifie pas à lui seul un concept. L'identité canonique doit être `(taxref_version_id, taxref_cd_ref)`. Les recherches textuelles continueront à utiliser `taxon_names.normalized_name`, sans imposer d'unicité globale entre taxons.

Les auteurs, rangs et lignées doivent être conservés ; ils servent à expliquer ou désambiguïser les homonymes, mais ne justifient jamais une fusion automatique. Le fichier `scientific-name-homonyms.csv` contient une ligne par concept avec ces éléments.

## Rapprochement des 23 taxons locaux

Le plan privilégie, dans l'ordre : une correspondance de source officiellement vérifiable, un nom accepté exact avec rang, un nom accepté désambiguïsé par rang et lignée, puis un synonyme. Un résultat douteux reste explicitement non résolu.

Répartition réelle :

| Statut | Nombre |
| --- | ---: |
| exact | 6 |
| synonym | 0 |
| probable | 0 |
| ambiguous | 0 |
| unresolved | 17 |

Correspondances exactes :

| Taxon local | CD_REF TAXREF v18 | Motif |
| --- | ---: | --- |
| Tichodroma muraria | 3780 | mapping GBIF officiel `2484918`, nom et rang concordants |
| Animalia | 183716 | nom accepté et rang `kingdom` |
| Delphinus delphis | 60878 | nom accepté et rang `species` |
| Vulpes vulpes | 60585 | nom accepté et rang `species` |
| Papilio machaon | 54468 | nom accepté et rang `species` |
| Lepidodermella | 212791 | nom accepté et rang `genus` |

Pour le Tichodrome échelette, les mappings locaux GBIF `2484918`, iNaturalist `14840` et Faune-France `383` restent tous attachés au même taxon local. Seul le lien GBIF → `CD_NOM/CD_REF 3780` est directement vérifiable dans `TAXREF_LIENS.txt`; les identifiants iNaturalist et Faune-France doivent rester des correspondances par source, sans être interprétés comme des identifiants TAXREF.

Les 17 taxons non résolus comprennent des données GBIF manifestement extérieures ou absentes du périmètre TAXREF, ainsi que des noms provisoires. Tous les genres locaux ont été contrôlés : seul `Lepidodermella` est exact ; `Brahemyia`, `Seticotasteromimus`, `TP-CH-4`, `Lacordairius`, `Schaumius`, `Draconectes`, `Hyaenodon` et `Megistotherium` restent non résolus. Les espèces `Megarthrus tic`, `Bulbophyllum blaoense`, `Cleisostoma lecongkietii`, `Cleisostoma phitamii`, `Cheirostylis phamhoangii`, `Oia imadatei`, `Acomys sp.Usnm`, `Myotis sp.Msb` et `Myotis sp.Mvz` restent également non résolues. Le classement incohérent de `Cheirostylis phamhoangii` — dont la classification locale mentionne une autre espèce — interdit tout rapprochement automatique.

Les détails et tous les candidats sont dans :

- `existing-taxa-matches.csv` ;
- `existing-taxa-ambiguous.csv` ;
- `existing-taxa-unresolved.csv`.

## Estimation de `taxon_names`

L'estimation réelle emploie `TaxrefVernacularNameExtractor` et `TaxonNameNormalizer`. Les cellules vernaculaires sont séparées sur virgule et point-virgule, normalisées, dédupliquées par concept et par type, et les valeurs identiques au nom scientifique accepté sont exclues.

| Type ou anomalie | Nombre |
| --- | ---: |
| noms scientifiques acceptés | 300 377 |
| lignes synonymes brutes | 408 308 |
| synonymes uniques par concept | 402 988 |
| doublons de synonymes éliminables | 5 320 |
| noms synonymes partagés par plusieurs concepts | 6 433 |
| parties vernaculaires brutes | 311 112 |
| noms vernaculaires uniques par concept | 49 522 |
| graphies vernaculaires globalement distinctes | 44 550 |
| noms vernaculaires partagés par plusieurs concepts | 4 023 |
| parties vernaculaires identiques au scientifique et exclues | 2 057 |
| cellules multi-valeurs rencontrées dans les enregistrements | 78 578 |
| lignes finales estimées dans `taxon_names` | 752 887 |

La répétition d'un même nom entre concepts n'est pas une erreur : elle doit rester autorisée. L'unicité cible doit être limitée à `(taxon_id, taxonomic_reference_version_id, name_type, normalized_name)`.

## Hiérarchie et estimation de `taxon_paths`

Le parent canonique est déterminé par `CD_SUP`. Lorsque `CD_SUP` désigne une ligne synonyme, son `CD_REF` devient le parent canonique : 449 relations sont ainsi résolues. Il existe :

- 2 racines véritables ;
- 8 références de parent absentes du fichier importé ;
- aucun cycle détecté ;
- une profondeur maximale de 35 ;
- une profondeur moyenne de 17,241 ;
- 5 479 172 lignes estimées dans la table de fermeture `taxon_paths`, en incluant la relation de profondeur 0 de chaque taxon vers lui-même.

Les huit concepts dont le parent brut est absent sont `Lepadoidea` (1043260), `Scalpelloidea` (1043348), `Nemostira martirei` (780270), `Beloniella genistae` (870977), `Utrechtiana arundinacea` (1043896), `Bursaria truncatella` (1036025), `Valdensia heterodoxa` (1045113) et `Dermosporidium granulosum` (1078375). Ils doivent être signalés comme racines techniques orphelines, pas silencieusement reliés à une autre lignée.

En prenant 150 à 210 octets par ligne pour la table et ses index PostgreSQL, le volume de `taxon_paths` est estimé entre 821 875 800 et 1 150 626 120 octets, avec une médiane de 964 334 272 octets, soit environ 784 Mio à 1,07 Gio (médiane 920 Mio). Cette estimation devra être confirmée par `pg_total_relation_size` après un essai hors production.

## Ordre exact d'une future migration

Cette séquence est une proposition ; aucune de ces étapes n'est implémentée ici.

1. Sauvegarder la base, geler les imports, vérifier que TAXREF v18 est complet en `staging` et archiver les rapports de planification.
2. Faire valider manuellement les 17 taxons non résolus. Produire un fichier de décisions versionné contenant `local_taxon_id`, `taxref_cd_ref` éventuel, décision et justification. Ne jamais rapprocher par simple ressemblance.
3. Modifier le schéma avant les données : supprimer `taxa_scientific_name_unique`, conserver un index non unique sur le nom, ajouter l'unicité de `taxon_names` limitée au taxon/version/type/nom normalisé et ajouter une unicité partielle garantissant une seule ligne acceptée par `(version, cd_ref)` dans `taxref_records`.
4. Préparer une table temporaire de correspondance `local_taxon_id → CD_REF` issue des six exacts et des décisions manuelles. Elle ne doit pas remplacer `taxon_source_mappings`.
5. Mettre à jour **en place** les taxons locaux rapprochés : conserver leur `taxa.id`, renseigner `taxref_version_id`, `taxref_cd_ref`, `current_taxref_record_id`, nom accepté, autorité et rang. Les observations, surveillances, collections historiques et mappings de source conservent ainsi leurs clés étrangères.
6. Insérer les autres concepts acceptés dans `taxa`, identifiés par `(taxref_version_id, taxref_cd_ref)`. Utiliser un traitement par lots et un `upsert` idempotent. Ne pas renseigner encore `parent_id`.
7. Relier chaque enregistrement TAXREF à son taxon canonique via `taxref_records.taxon_id`, puis vérifier une couverture de 300 377 concepts acceptés et l'absence de double lien.
8. Renseigner `parent_id` en second passage avec le parent canonique résolu via `CD_SUP → enregistrement parent → CD_REF`. Laisser les huit parents absents à `NULL` avec anomalie enregistrée.
9. Insérer environ 752 887 lignes dans `taxon_names` par lots, selon les règles de l'extracteur existant. Autoriser un même nom sur plusieurs concepts.
10. Construire `taxon_paths` dans une table de travail ou une nouvelle partition, vérifier les 5 479 172 relations attendues, les profondeurs, les deux racines, les huit orphelins et l'absence de cycles, puis basculer atomiquement.
11. Conserver les 17 taxons sans décision comme taxons locaux non canoniques ou les marquer `retired/merged` uniquement après revue. Leurs observations ne doivent jamais être déplacées automatiquement.
12. Exécuter les contrôles d'intégrité : IDs locaux inchangés, nombre d'observations inchangé, mappings source inchangés, aucun taxon fusionné dans lui-même, parents appartenant à la même version, chemins réflexifs présents et aucun doublon de nom par concept/type.
13. Activer TAXREF v18 dans une transaction courte seulement après validation fonctionnelle. Archiver l'ancienne version active dans la même transaction afin de respecter l'unicité partielle d'une seule version active par fournisseur.
14. Prévoir un retour arrière par restauration des colonnes de liaison et du statut de version, sans supprimer les anciens taxons ni réattribuer leurs IDs. Les tables volumineuses de noms et chemins peuvent être reconstruites depuis le staging.

## Décision de sécurité

Le chargement technique de 300 377 concepts, 752 887 noms et environ 5,48 millions de chemins est réalisable. En revanche, la migration complète n'est **pas sûre sans revue manuelle** : 17 des 23 taxons historiques ne sont pas résolus, huit parents TAXREF sont absents et la contrainte d'unicité actuelle bloque 1 312 concepts valides. Le drapeau `--fail-on-ambiguity` doit rester activé dans toute procédure de préparation à une migration réelle.

## Rapports produits

Le dossier `storage/app/taxref/reports/v18/` contient :

- `canonical-concepts-summary.json` : volumes, rangs, homonymes et bilan des rapprochements ;
- `scientific-name-homonyms.csv` : les 2 607 concepts homonymes, auteurs, rangs et lignées ;
- `existing-taxa-matches.csv` : les 23 décisions ;
- `existing-taxa-ambiguous.csv` : ambiguïtés (vide hors en-tête lors de l'analyse actuelle) ;
- `existing-taxa-unresolved.csv` : les 17 taxons à revoir ;
- `taxon-names-estimate.json` : estimation obtenue avec les normaliseurs existants ;
- `hierarchy-estimate.json` : racines, parents, cycles, profondeurs, chemins et volume PostgreSQL.
