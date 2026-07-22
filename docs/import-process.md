# Processus d’import

1. `POST /api/searches/estimate` compte les données locales et appelle les compteurs simulables GBIF/iNaturalist. Le sous-total GBIF limité au jeu iNaturalist sert à estimer le recouvrement.
2. L’utilisateur confirme explicitement avec `confirmed: true` sur `POST /api/imports`. Un job indépendant est créé pour chaque source.
3. Le worker passe le job à `running`, puis traite GBIF par pages de 300 ou iNaturalist par pages de 200 avec `id_above`.
4. Chaque ligne est normalisée, persistée de façon idempotente et éventuellement rattachée à une collection ou une surveillance.
5. Les compteurs du job sont actualisés après chaque zone. L’état final est `completed`, `partial` ou `failed`; un jeu vide correctement interrogé est `completed` avec zéro ligne.
6. Une couverture `completed` seulement peut supprimer une période des prochains calculs de manque. Une couverture partielle ne prétend pas couvrir tout l’intervalle.

Le plafond absolu est 10 000 par source, configurable à la baisse. Une erreur de ligne incrémente `failed_count`; une erreur de requête termine le job en échec avec un message visible. Un import `pending` peut être annulé avant son exécution via `PATCH /api/imports/{id}/cancel`.

iNaturalist est limité à une requête par seconde dans le worker. GBIF ne dépasse jamais l’offset 100 000. L’estimation n’est pas une promesse : les sources peuvent évoluer entre le compteur et l’import.
