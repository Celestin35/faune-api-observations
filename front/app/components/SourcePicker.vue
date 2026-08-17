<script setup lang="ts">
import type { GeographicArea, QueryZone, TaxonResult, TaxonSelection } from '~/types/observation-query'

const sources = defineModel<string[]>({ required: true })
const props = defineProps<{
  taxon: TaxonResult | null
  taxonScope: 'exact' | 'subtree'
  zone: QueryZone
  areas: GeographicArea[]
  allowBroadFauneFrance?: boolean
  taxa?: TaxonSelection[]
}>()

const taxonSelections = computed<TaxonSelection[]>(() => props.taxa ?? (props.taxon
  ? [{ taxon: props.taxon, scope: props.taxonScope }]
  : []))
const firstTaxonSelection = computed(() => taxonSelections.value[0])

const selectedAreas = computed(() => props.zone.departmentCodes
  .map(code => props.areas.find(area => area.code === code))
  .filter((area): area is GeographicArea => Boolean(area)))

const fauneReason = computed(() => {
  if (!taxonSelections.value.length) return 'Sélectionnez une espèce ou un groupe pour activer Faune-France.'
  for (const selection of taxonSelections.value) {
    if (!props.allowBroadFauneFrance && selection.taxon.rank.code !== 'species') return 'Faune-France accepte ici une espèce uniquement.'
    if (selection.taxon.rank.code === 'species' && selection.scope !== 'exact') return `L’espèce « ${selection.taxon.preferredFrenchName || selection.taxon.acceptedScientificName} » doit utiliser la portée exacte avec Faune-France.`
    if (selection.taxon.rank.code !== 'species' && selection.scope !== 'subtree') return `Le groupe « ${selection.taxon.preferredFrenchName || selection.taxon.acceptedScientificName} » doit inclure ses descendants.`
    if (!selection.taxon.sourceAvailability.fauneFrance) return `« ${selection.taxon.preferredFrenchName || selection.taxon.acceptedScientificName} » ne correspond pas à un taxon Faune-France disponible.`
  }
  if (props.zone.mode === 'departments') {
    if (!props.zone.departmentCodes.length) return 'Sélectionnez au moins un département.'
    const portals = [...new Set(selectedAreas.value.map(area => area.faune_portal))]
    if (portals.length > 1) return 'La sélection couvre plusieurs portails Faune.'
    if (portals[0] !== 'faune_france') return 'Le connecteur du portail ultramarin sélectionné n’est pas encore disponible.'
  } else if (props.zone.mode !== 'france') {
    if (props.zone.mode === 'address' && !props.zone.addressConfirmed) return 'Sélectionnez une adresse proposée.'
    if (props.zone.latitude < 41 || props.zone.latitude > 51.5 || props.zone.longitude < -5.5 || props.zone.longitude > 10) {
      return 'Le point/rayon Faune-France doit être situé en France métropolitaine.'
    }
  }
  return ''
})

watch(fauneReason, (reason) => {
  if (reason && sources.value.includes('faune-france')) {
    sources.value = sources.value.filter(source => source !== 'faune-france')
  }
})
</script>

<template>
  <fieldset class="form-section">
    <legend>Sources</legend>
    <div class="inline-checks">
      <label><input v-model="sources" type="checkbox" value="gbif"> GBIF</label>
      <label><input v-model="sources" type="checkbox" value="inaturalist"> iNaturalist</label>
      <label :class="{ 'source-disabled': fauneReason }">
        <input v-model="sources" type="checkbox" value="faune-france" :disabled="Boolean(fauneReason)">
        Faune-France
      </label>
    </div>
    <p v-if="fauneReason" class="field-help">{{ fauneReason }}</p>
    <p v-else class="success field-help">
      <template v-if="taxonSelections.length > 1">Faune-France lancera les recherches correspondant aux {{ taxonSelections.length }} sélections.</template>
      <template v-else-if="firstTaxonSelection?.taxon.rank.code !== 'species'">Faune-France recherchera toutes les espèces du groupe correspondant.</template>
      <template v-else>Faune-France recherchera cette espèce exacte.</template>
      Le volume sera connu pendant la récupération.
    </p>
  </fieldset>
</template>
