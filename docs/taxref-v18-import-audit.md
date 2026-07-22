# Audit de préparation de l’import TAXREF v18

Date de validation : 22 juillet 2026.

Cette étape analyse la diffusion officielle, adapte le lecteur et valide un parcours complet en `--dry-run`. Aucun enregistrement TAXREF v18 n’a été importé en base, aucune version v18 n’a été créée et aucun taxon canonique n’a été généré.

## Source officielle et intégrité

La source utilisée est le lien TAXREF v18 publié par PatriNat sur sa [page temporaire des référentiels INPN](https://www.patrinat.fr/fr/page-temporaire-de-telechargement-des-referentiels-de-donnees-lies-linpn-7353).

| Propriété | Valeur |
|---|---|
| URL | `https://assets.patrinat.fr/files/referentiel/TAXREF_v18_2025.zip` |
| Nom | `TAXREF_v18_2025.zip` |
| Taille | 60 582 042 octets |
| SHA-256 de l’archive | `a6963ea1a3baec3220f0bf76b43eaa9b49d0c0eecd5ab72294b760adf78897a7` |
| Intégrité ZIP | neuf membres testés, aucune erreur |
| Emplacement local | `storage/app/taxref/source/TAXREF_v18_2025.zip` |

Le répertoire `storage/app/taxref/source/` est explicitement ignoré par Git. L’archive et les fichiers décompressés ne doivent jamais être versionnés.

## Contenu intégral de l’archive

| Fichier | Taille décompressée | Lignes, en-tête inclus | Usage |
|---|---:|---:|---|
| `TAXREFv18.txt` | 317 126 709 | 708 686 | Référentiel taxonomique à lire par `taxref:import` |
| `TAXVERNv18.txt` | 9 753 256 | 82 967 | Noms vernaculaires multilingues, phase suivante |
| `TAXREF_LIENS.txt` | 353 212 934 | 2 016 748 | Correspondances avec les bases sources |
| `TAXREF_CHANGES.txt` | 5 032 513 | 68 762 | Changements entre versions |
| `CDNOM_DISPARUS.txt` | 1 284 620 | 12 892 | Identifiants retirés ou remplacés |
| `habitats_note.csv` | 1 027 | 9 | Dictionnaire des huit habitats |
| `rangs_note.csv` | 1 563 | 55 | Dictionnaire des rangs |
| `statuts_note.csv` | 4 973 | 17 | Dictionnaire des statuts biogéographiques |
| `TAXREFv18.pdf` | 2 542 859 | 50 pages | Documentation méthodologique et structure de diffusion |

Les fichiers `.txt` sont tabulés, entourent les valeurs textuelles de guillemets doubles, n’ont pas de BOM et utilisent des fins de ligne CRLF. `TAXREFv18.txt`, `TAXVERNv18.txt`, `TAXREF_CHANGES.txt` et `CDNOM_DISPARUS.txt` sont bien en UTF-8 ; `TAXREF_LIENS.txt` ne contient que de l’ASCII.

Les trois fichiers de notes sont séparés par des points-virgules, sans BOM, en CRLF. Leurs octets sont Windows-1252, et non UTF-8 comme l’indique le PDF. Ils doivent donc être transcodés avant une éventuelle lecture automatisée.

L’archive ne contient pas de fichier de licence séparé. Le PDF indique que TAXREF est publié intégralement « en libre accès », sans nommer de licence précise. Le rapport ne déduit donc pas arbitrairement une licence. La documentation à citer est : *TAXREF, référentiel taxonomique pour la France : méthodologie, mise en œuvre et diffusion*, Olivier Gargominy et al.

## Format réel de `TAXREFv18.txt`

Le fichier contient 44 colonnes :

```text
REGNE, PHYLUM, CLASSE, ORDRE, FAMILLE, SOUS_FAMILLE, TRIBU,
GROUP1_INPN, GROUP2_INPN, GROUP3_INPN,
CD_NOM, CD_TAXSUP, CD_SUP, CD_REF, CD_BA, RANG,
LB_NOM, LB_AUTEUR, NOMENCLATURAL_COMMENT,
NOM_COMPLET, NOM_COMPLET_HTML, NOM_VALIDE,
NOM_VERN, NOM_VERN_ENG, HABITAT,
FR, GF, MAR, GUA, SM, SB, SPM, MAY, EPA, REU, SA, TA, TAAF,
PF, NC, WF, CLI, URL, URL_INPN
```

Deux différences avec les noms anticipés sont importantes : la colonne est `SPM`, pas `STM`, et l’archive contient à la fois `TA` et le statut calculé `TAAF`.

Les 708 685 lignes de données ont exactement 44 champs. Les observations de types sont :

- `CD_NOM` et `CD_REF` : entiers obligatoires, 0 valeur vide ou non numérique ;
- `CD_TAXSUP` et `CD_SUP` : entiers facultatifs, 300 375 valeurs renseignées ;
- `CD_BA` : entier facultatif, 451 071 valeurs renseignées ;
- `HABITAT` : entier facultatif compris entre 1 et 8 ;
- `RANG` et statuts territoriaux : codes texte courts ;
- noms, autorités, commentaires et URL : texte UTF-8.

Longueurs maximales observées : `LB_NOM` 87 caractères, `LB_AUTEUR` 304, `NOM_COMPLET` 313, `NOM_COMPLET_HTML` 313, `NOM_VALIDE` 313 et `NOM_VERN` 614. Les limites de 512 caractères de `taxref_records.scientific_name` et `authorship` conviennent au fichier v18.

Le SHA-256 du fichier réellement fourni à la commande est :

```text
97a79024b3c9723467cf0a978b02c02b0f734bafb47136a94d1ff67a49155c0a  TAXREFv18.txt
```

Il est distinct du SHA-256 de l’archive et c’est lui que `--sha256` doit recevoir quand la commande lit le fichier décompressé.

## Mapping retenu par l’importeur

| Cible `taxref_records` | Source v18 | Décision |
|---|---|---|
| `cd_nom` | `CD_NOM` | Identifiant unique du nom |
| `cd_ref` | `CD_REF` | Identifiant du nom retenu |
| `parent_cd_ref` | `CD_SUP` | Parent taxonomique direct officiel |
| `scientific_name` | `LB_NOM` | Nom sans autorité |
| `authorship` | `LB_AUTEUR` | Auteur et année |
| `rank_code` | `RANG` | Mapping explicite, sinon `null` |
| `name_status` | comparaison des identifiants | accepté si `CD_NOM = CD_REF`, synonyme sinon |
| `raw_data` | les 44 colonnes | Ligne complète conservée en JSON |

`CD_TAXSUP` n’est pas utilisé comme parent : la documentation le décrit comme un parent calculé dans une classification supérieure simplifiée. `CD_SUP` est explicitement le taxon directement supérieur et correspond donc à la sémantique attendue par `parent_cd_ref`.

La documentation v18 confirme également que `CD_REF = CD_NOM` pour un nom de référence et que tout synonyme pointe par `CD_REF` vers ce nom de référence. Le dry-run retrouve 300 377 noms acceptés et 408 308 synonymes.

## Rangs TAXREF observés

Le mapping interne explicite couvre :

| Code TAXREF | Rang interne | Effectif v18 |
|---|---|---:|
| `KD` | `kingdom` | 10 |
| `PH` | `phylum` | 124 |
| `CL` | `class` | 324 |
| `OR` | `order` | 1 452 |
| `FM` | `family` | 7 882 |
| `GN` | `genus` | 57 827 |
| `ES` | `species` | 542 384 |
| `SSES` | `subspecies` | 33 678 |

Ces codes représentent 643 681 lignes. Les 65 004 lignes restantes ne sont pas converties arbitrairement ; leur `rank_code` reste `null` et leur code original reste dans `raw_data` :

| Code | Effectif | Code | Effectif | Code | Effectif |
|---|---:|---|---:|---|---:|
| `AB` | 48 | `AGES` | 22 | `CAR` | 180 |
| `CLAD` | 39 | `COH` | 1 | `Dumm` | 2 |
| `DV` | 3 | `FO` | 5 884 | `FOES` | 21 |
| `IFCL` | 34 | `IFOR` | 148 | `IFPH` | 13 |
| `IFRG` | 8 | `LEG` | 2 | `MO` | 4 |
| `NAT` | 70 | `PVCL` | 8 | `PVOR` | 46 |
| `RACE` | 1 592 | `SBCL` | 195 | `SBDV` | 5 |
| `SBFM` | 3 713 | `SBOR` | 462 | `SBPH` | 49 |
| `SBSC` | 123 | `SC` | 1 573 | `SCO` | 6 |
| `SER` | 150 | `SMES` | 1 | `SPCL` | 12 |
| `SPFM` | 1 126 | `SPOR` | 108 | `SPRG` | 1 |
| `SPTR` | 21 | `SSCO` | 3 | `SSER` | 19 |
| `SSFO` | 7 | `SSGN` | 3 598 | `SSRG` | 17 |
| `SSTR` | 822 | `SVAR` | 159 | `TR` | 3 529 |
| `VAR` | 41 180 |  |  |  |  |

## Noms vernaculaires

Dans `TAXREFv18.txt`, `NOM_VERN` est vide sur 539 267 lignes et renseigné sur 169 418. La documentation indique une séparation par virgule et considère le premier nom comme le nom de référence. Les données montrent :

- 78 574 cellules contenant une virgule ;
- 7 cellules utilisant un point-virgule, parfois avec des virgules dans la même cellule ;
- 352 cellules contenant au moins un doublon exact insensible à la casse ;
- 644 cellules contenant une valeur équivalente au nom scientifique ;
- 127 622 lignes avec `NOM_VERN_ENG` renseigné.

`TAXVERNv18.txt` contient 82 966 lignes non vides, 54 libellés de langues représentant 52 codes ISO 639-3, dont 43 642 lignes françaises et 25 600 anglaises. Il constitue la source multilingue complète pour la prochaine phase ; `NOM_VERN` reste un agrégat français pratique dans le fichier principal.

`TaxrefVernacularNameExtractor` prépare cette prochaine phase sans remplir `taxon_names`. Cette fonction pure :

- découpe sur la virgule et sur le point-virgule anormal réellement observé ;
- retire les valeurs vides ;
- conserve l’ordre source ;
- déduplique avec la normalisation sans accents ;
- écarte une valeur équivalente au nom scientifique.

## Territoires et statuts biogéographiques

Les colonnes correspondent à : `FR` France hexagonale et Corse, `GF` Guyane, `MAR` Martinique, `GUA` Guadeloupe, `SM` Saint-Martin, `SB` Saint-Barthélemy, `SPM` Saint-Pierre-et-Miquelon, `MAY` Mayotte, `EPA` Îles Éparses, `REU` La Réunion, `SA` îles subantarctiques, `TA` Terre Adélie, `TAAF` synthèse calculée de SA et TA, `PF` Polynésie française, `NC` Nouvelle-Calédonie, `WF` Wallis-et-Futuna et `CLI` Clipperton.

Valeurs réellement présentes par colonne, hors la valeur vide également observée partout :

| Colonne | Valeurs distinctes |
|---|---|
| `FR` | `A B C D E I J M P Q S W X Y Z` |
| `GF` | `A B C D E I J M P Q S W Y` |
| `MAR`, `GUA`, `REU` | `A B C D E I J M P Q S W X Y Z` |
| `SM`, `SB` | `A B C D E I J M P Q S W X Y` |
| `SPM` | `A B C D I J M P Q` |
| `MAY` | `A B C D E I J M P Q S W Y Z` |
| `EPA` | `A B C D E I J M P Q S W Y` |
| `SA`, `TAAF` | `A B D E I J M P Q S W Y Z` |
| `TA` | `A B D E M P` |
| `PF` | `A B C D E G I J M P Q S W X Y Z` |
| `NC` | `A B C D E I J M P Q S W X Y Z` |
| `WF` | `A B C D E I J M P Q S Y` |
| `CLI` | `A B C D E I P Q S Y` |

Interprétation issue de `statuts_note.csv` :

- présence : `P` présent, `B` occasionnel ;
- endémisme : `E` endémique, `S` subendémique ;
- introduction : `I` introduit, `J` introduit envahissant, `M` introduit non établi ;
- incertitude ou erreur : `D` douteux, `G` ADNe uniquement, `Q` mentionné par erreur ;
- absence : `A` absent ;
- disparition : `W` disparu localement, `X` éteint globalement, `Z` endémique éteint, `Y` introduit éteint ;
- origine indéterminée : `C` cryptogène ;
- vide : statut non renseigné.

Aucun filtre territorial n’est appliqué par l’import brut : les 44 champs restent disponibles dans `raw_data` pour les décisions ultérieures.

## Dry-run officiel validé

Commande exécutée :

```bash
php artisan taxref:import storage/app/taxref/source/TAXREF_v18_2025/TAXREFv18.txt \
  --version=18 \
  --published-on=2025-01-01 \
  --source-uri="https://assets.patrinat.fr/files/referentiel/TAXREF_v18_2025.zip" \
  --sha256="97a79024b3c9723467cf0a978b02c02b0f734bafb47136a94d1ff67a49155c0a" \
  --dry-run
```

Résultat :

```text
Lignes lues : 708685
Noms acceptés : 300377
Synonymes : 408308
Rangs reconnus : 643681
Rangs inconnus : 65004
Lignes invalides : 0
Enregistrements importés : 0
Lots écrits : 0
Durée : 12.032 s
```

Contrôle avant/après : `taxonomic_reference_versions` est restée à 1 ligne et `taxref_records` à 9 lignes, celles de la fixture synthétique précédente. Aucune version `18` n’existe en base.

## Éléments volontairement différés

- import réel des 708 685 lignes ;
- création et activation d’une version v18 en base ;
- création des taxons canoniques ;
- alimentation de `taxon_names` depuis `NOM_VERN` et `TAXVERNv18.txt` ;
- construction de `taxon_paths` ;
- utilisation des statuts territoriaux pour filtrer ;
- rapprochement des taxons existants et mappings externes ;
- modification de la recherche, des connecteurs, de Nuxt ou du bot.
