# Évolution de l’exploration et des observations

## Architecture commune

L’exploration et les surveillances partagent désormais les mêmes critères métier et les mêmes composants principaux.

- `ObservationQueryCriteria` décrit le taxon canonique, le scope (`exact` ou `subtree`), la version taxonomique, le libellé figé, la période, la zone et les sources.
- Une exploration utilise une période `absolute`.
- Une surveillance conserve une période `sliding` en minutes. Elle n’est convertie en dates absolues par `MonitoringSynchronizer` qu’au moment de son exécution.
- `ObservationQueryExecution` porte le contexte d’exécution : finalité, collection, surveillance, plafond d’import et réglages du bot.
- `SearchDefinitionFactory` valide le noyau commun puis produit la `SearchDefinition` déjà utilisée par les requêtes.
- `SourceCapabilityService` centralise les règles serveur de disponibilité des sources.

Nuxt utilise les composants communs `TaxonPicker`, `DateRangePicker`, `ZonePicker`, `DepartmentPicker` et `SourcePicker`. Les trois modes visuels adresse, coordonnées et départements produisent respectivement une zone backend `radius`, `radius` et `departments`. Une adresse n’est valide qu’après sélection d’une proposition de l’autocomplétion. Toute modification des critères invalide l’estimation affichée.

## Chemins d’import

`ImportCoordinator` crée un `import_job` par source et par sélection taxonomique, ou un par groupe Faune-France lorsque le taxon sélectionné correspond à plusieurs groupes du portail.

```text
GBIF         -> ImportObservationsJob -> connecteur HTTP -> normalisation -> persistance
iNaturalist  -> ImportObservationsJob -> connecteur HTTP -> normalisation -> persistance
Faune-France -> external_fetch_job lié -> worker Playwright -> lots de 100 -> normalisation -> persistance
```

Pour Faune-France, l’import ponctuel exige une espèce exacte avec un mapping `faune_france` préféré et validé, ou un groupe TAXREF pris en charge. La recherche globale sans taxon est refusée afin d’éviter 26 recherches lourdes. Chaque résultat de groupe conserve sa propre espèce grâce à `species_array`. La période doit être valide et la zone peut être la France métropolitaine entière, des départements du portail métropolitain ou un point/rayon métropolitain. Le rayon est transmis au bot, qui construit le polygone WKT natif ; il n’est pas converti en départements.

Une surveillance possède une liste ordonnée de taxons dans `monitoring_rule_taxa`. Elle peut contenir autant d’espèces ou de groupes que nécessaire, mais pas `Animalia`, et deux sélections ancêtre/descendant ne peuvent pas se recouvrir lorsque l’ancêtre inclut ses descendants. La première exécution commence le jour de la création. Les suivantes repartent de la dernière réussite avec dix minutes de chevauchement, dans la limite de la période maximale de rattrapage configurée.

Faune-France ne fournit pas d’estimation légère. L’API renvoie donc :

```json
{
  "available": true,
  "estimable": false,
  "count": null,
  "message": "Estimation indisponible pour Faune-France. Le nombre de résultats sera connu pendant la récupération."
}
```

L’import reste confirmable. Le total devient connu à la fin de la pagination.

### États et compteurs

`import_jobs` expose `pending`, `running`, `completed`, `partial`, `failed` et `cancelled`. Le claim du worker démarre l’import associé. Chaque lot idempotent incrémente les compteurs traité, créé, mis à jour et inchangé une seule fois. La fin, la troncature de sécurité et l’erreur du worker sont propagées à l’import visible. Les observations déjà persistées sont conservées en cas d’échec.

Un import Faune-France encore `pending` peut être annulé. La tâche externe est alors marquée `cancelled`, sans suppression. Une tâche déjà réservée n’est jamais supprimée brutalement. Les groupes ne contenant aucune observation produisent un lot vide valide et se terminent normalement à zéro.

## Contrat normalisé et confidentialité

La table `observations` porte la meilleure représentation canonique publiable. `observation_sources` conserve les valeurs propres à chaque provenance. `observation_source_media` conserve uniquement des URL publiques ; aucun fichier média n’est téléchargé.

Les principaux champs ajoutés couvrent :

- précision temporelle (`datetime`, `date`, `unknown`) ;
- statut géographique (`exact`, `approximate`, `source_masked`, `unavailable`) ;
- pays, région, département, commune et localité structurés lorsque la source les donne ;
- stade de vie, sexe, comportement, nombre, remarques et validation ;
- valeurs publiques, attribution et précision propres à chaque provenance.

Priorité de localisation canonique :

```text
unavailable < source_masked < approximate < exact
```

Une provenance moins fiable ne remplace jamais une localisation canonique plus fiable. À priorité égale, seule la mise à jour de l’unique provenance peut rafraîchir la valeur. La géométrie PostGIS est recalculée lorsque les coordonnées canoniques changent. La proximité ne déclenche jamais une fusion automatique : elle crée au plus un candidat de déduplication.

L’observateur canonique n’est alimenté que si son nom est publiable. Les données administratives sont copiées uniquement lorsqu’elles sont fournies par la source. Il n’y a ni déduction depuis les rectangles départementaux, ni géocodage inverse.

### Règles par source

- **GBIF** : `informationWithheld` ou `dataGeneralizations` produit `source_masked`. Sans masquage, seule une incertitude explicitement égale à zéro peut être `exact`; les autres coordonnées sont `approximate`.
- **iNaturalist** : seules `geojson` et `public_positional_accuracy` sont utilisées. Les coordonnées privées sont ignorées. `geoprivacy` obscured/private produit `source_masked`; une position ouverte n’est `exact` que si son incertitude publique vaut explicitement zéro.
- **Faune-France** : `is_hidden` ou `is_admin_hidden` produit `source_masked` et les coordonnées brutes ne sont pas copiées dans les colonnes publiques. Une précision explicitement `precise`, sans masquage, peut être `exact`; place, garden ou précision inconnue restent `approximate`. L’auteur n’est pas publié par défaut.

Les données historiques ayant des coordonnées mais aucune preuve de précision sont migrées en `approximate`. Une absence de coordonnées devient `unavailable`.

## API et page détail

`GET /api/observations` utilise `ObservationListResource` et une pagination Laravel (`per_page`, maximum 500 ; l’ancien paramètre `limit` reste accepté). `GET /api/observations/{id}` utilise `ObservationDetailResource` et renvoie le taxon, la lignée, la date, la localisation publique, les faits biologiques et toutes les provenances via `ObservationSourceResource`.

Sont exclus de ces deux contrats :

- `raw_data` ;
- `canonical_identifiers` internes ;
- cookies, tokens et coordonnées privées.

La page Nuxt `/observations/{id}` affiche l’en-tête taxonomique, les informations temporelles, une carte dédiée, la localisation, les données biologiques, la lignée TAXREF et toutes les provenances. La carte :

- place un marqueur pour `exact` ;
- place un marqueur et, si disponible, un cercle géodésique en mètres pour `approximate` ;
- n’affiche que le point public autorisé avec un avertissement pour `source_masked` ;
- n’affiche aucun point pour `unavailable`.

La carte générale relie chaque popup à cette page.

## Limites géographiques

Les libellés administratifs dépendent encore des champs fournis par les sources. Une commune, un département ou une région inconnu est présenté comme non renseigné. Une résolution PostGIS future pourra utiliser des contours administratifs officiels, mais aucun contour ni géocodeur inverse n’a été ajouté dans cette évolution.

Les portails Faune ultramarins restent séparés. GBIF et iNaturalist acceptent les 101 départements, mais aucune tâche Playwright n’est créée pour Faune-Antilles, Faune-Guyane, Faune-Réunion ou Faune-Mayotte.

## Migration et rollback

La migration `2026_07_23_000001_expand_observations_and_external_imports` est additive :

1. lien nullable et unique `external_fetch_jobs.import_job_id` ;
2. champs normalisés des observations et provenances ;
3. table `observation_source_media` ;
4. backfill prudent des données historiques.

Avant migration locale, une sauvegarde PostgreSQL a été créée dans `storage/app/backups/`, dossier ignoré par Git. Le rollback de schéma est disponible avec :

```bash
docker compose exec app php artisan migrate:rollback --step=1
```

Un rollback retire les nouveaux champs : il doit donc être précédé d’une sauvegarde et n’est pas à lancer après production de nouvelles données sans validation. Il ne supprime volontairement aucune observation ou provenance dans le chemin normal de migration.
œ
