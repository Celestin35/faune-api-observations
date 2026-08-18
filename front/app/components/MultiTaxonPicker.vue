<script setup lang="ts">
import type { TaxonResult, TaxonSelection } from '~/types/observation-query'

const selections = defineModel<TaxonSelection[]>({ required: true })
const candidate = ref<TaxonResult | null>(null)
const pickerKey = ref(0)
const error = ref('')

watch(candidate, (taxon) => {
  if (!taxon) return
  error.value = ''
  if (taxon.acceptedScientificName.toLowerCase() === 'animalia') {
    error.value = '« Tous les animaux » n’est pas disponible pour une surveillance.'
  } else if (selections.value.some(selection => selection.taxon.id === taxon.id)) {
    error.value = 'Ce taxon est déjà sélectionné.'
  } else {
    selections.value = [...selections.value, { taxon, scope: taxon.defaultScope }]
  }
  candidate.value = null
  pickerKey.value++
})

function remove(taxonId: number) {
  selections.value = selections.value.filter(selection => selection.taxon.id !== taxonId)
}

function label(selection: TaxonSelection): string {
  return selection.taxon.preferredFrenchName || selection.taxon.acceptedScientificName
}
</script>

<template>
  <fieldset class="form-section multi-taxon-picker">
    <legend>Espèces et groupes surveillés</legend>
    <TaxonPicker
      :key="pickerKey"
      v-model="candidate"
      placeholder="Ajouter une espèce ou un groupe"
    />
    <p class="field-help">Ajoutez autant de groupes ou d’espèces que nécessaire. « Tous les animaux » n’est pas proposé.</p>
    <div v-if="selections.length" class="taxon-selections">
      <article v-for="selection in selections" :key="selection.taxon.id" class="taxon-selection">
        <div>
          <strong>{{ label(selection) }}</strong>
          <small v-if="selection.taxon.preferredFrenchName"><i>{{ selection.taxon.acceptedScientificName }}</i></small>
        </div>
        <label>
          Portée
          <select v-model="selection.scope">
            <option value="exact">{{ taxonScopeLabel(selection.taxon, 'exact') }}</option>
            <option value="subtree">{{ taxonScopeLabel(selection.taxon, 'subtree') }}</option>
          </select>
        </label>
        <button type="button" class="secondary" @click="remove(selection.taxon.id)">Retirer</button>
      </article>
    </div>
    <p v-else class="field-help">Aucun taxon sélectionné.</p>
    <p v-if="error" class="error">{{ error }}</p>
  </fieldset>
</template>

<style scoped>
.multi-taxon-picker { display: grid; gap: .75rem; }
.taxon-selections { display: grid; gap: .6rem; }
.taxon-selection { display: grid; grid-template-columns: minmax(12rem, 1fr) minmax(11rem, auto) auto; align-items: end; gap: .75rem; padding: .7rem; background: #f4f7f3; border: 1px solid #dce4dc; border-radius: .6rem; }
.taxon-selection > div, .taxon-selection label { display: grid; gap: .2rem; }
.taxon-selection small { font-weight: 400; }
@media (max-width: 760px) { .taxon-selection { grid-template-columns: 1fr; } }
</style>
