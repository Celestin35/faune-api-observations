# Déduplication V0

La V0 distingue l’observation canonique (`observations`) de ses provenances (`observation_sources`). La contrainte `(source, source_occurrence_id)` garantit l’idempotence au sein d’une source.

La fusion entre sources n’utilise que des preuves exactes : URL iNaturalist canonique, identifiant global explicitement présent dans les références ou autre identifiant canonique normalisé. Une occurrence GBIF dont `references` vise `https://www.inaturalist.org/observations/123` reçoit la même `origin_key` que l’observation iNaturalist `123`. Une seule observation porte alors deux badges de provenance.

Les ressemblances taxon + temps proche + position proche créent seulement une ligne `observation_deduplication_candidates`. Elles ne fusionnent jamais automatiquement les observations. Il n’y a ni modèle probabiliste, ni fusion basée sur le seul nom d’observateur, ni média téléchargé.

Cette règle privilégie les faux négatifs aux faux positifs : deux doublons sans identifiant commun peuvent rester visibles séparément, mais deux observations biologiques distinctes ne doivent pas être réunies sur une simple proximité.
