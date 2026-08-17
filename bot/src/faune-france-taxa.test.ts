import assert from "node:assert/strict";
import test from "node:test";
import { normalizeCatalogEntries } from "./faune-france-taxa.js";

test("le catalogue IndexedDB est converti sans perdre les identifiants Faune-France", () => {
  const entries = normalizeCatalogEntries([
    { id: 358, lat: " Corvus corone ", sp: " Corneille noire ", idtg: 1, v: true, order: 282, cat: 0 }
  ]);
  assert.deepEqual(entries[0], {
    fauneFranceId: "358",
    scientificName: "Corvus corone",
    vernacularName: "Corneille noire",
    taxonomicGroupId: 1,
    selectable: true,
    order: 282,
    category: 0
  });
});

test("un identifiant dupliqué ou une entrée incomplète est refusé", () => {
  assert.throws(() => normalizeCatalogEntries([{ id: 0, lat: "", idtg: 1 }]), /invalide/);
  assert.throws(() => normalizeCatalogEntries([
    { id: 358, lat: "Corvus corone", idtg: 1 },
    { id: 358, lat: "Corvus corone", idtg: 1 }
  ]), /dupliqué/);
});
