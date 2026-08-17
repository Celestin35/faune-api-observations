<script setup lang="ts">
import type { GeographicArea, QueryZone, TaxonResult } from '~/types/observation-query'

const sources = defineModel<string[]>({ required: true })
const props = defineProps<{
  taxon: TaxonResult | null
  taxonScope: 'exact' | 'subtree'
  zone: QueryZone
  areas: GeographicArea[]
  allowBroadFauneFrance?: boolean
}>()

const selectedAreas = computed(() => props.zone.departmentCodes
  .map(code => props.areas.find(area => area.code === code))
  .filter((area): area is GeographicArea => Boolean(area)))

const fauneReason = computed(() => {
  if (!props.allowBroadFauneFrance && !props.taxon) return 'Sélectionnez une espèce pour activer Faune-France dans une surveillance.'
  if (!props.allowBroadFauneFrance && props.taxon?.rank.code !== 'species') return 'Les surveillances Faune-France acceptent actuellement une espèce uniquement.'
  if (props.taxon?.rank.code === 'species' && props.taxonScope !== 'exact') return 'Une espèce Faune-France doit utiliser la portée exacte.'
  if (props.taxon && props.taxon.rank.code !== 'species' && props.taxonScope !== 'subtree') return 'Un groupe Faune-France doit inclure ses descendants.'
  if (props.taxon && !props.taxon.sourceAvailability.fauneFrance) return 'Ce taxon ne correspond pas à une espèce ou un groupe Faune-France disponible.'
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
      <template v-if="!taxon">Faune-France recherchera successivement les 26 groupes d’animaux.</template>
      <template v-else-if="taxon.rank.code !== 'species'">Faune-France recherchera toutes les espèces du groupe correspondant.</template>
      <template v-else>Faune-France recherchera cette espèce exacte.</template>
      Le volume sera connu pendant la récupération.
    </p>
  </fieldset>
</template>
