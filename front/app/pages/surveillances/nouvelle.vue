<script setup lang="ts">
import type { GeographicArea, QueryZone, TaxonResult } from '~/types/observation-query'

const api = useApi()
const name = ref('Tichodrome — Rennes')
const selectedTaxon = ref<TaxonResult | null>(null)
const taxonScope = ref<'exact' | 'subtree'>('exact')
const frequency = ref(30)
const windowMinutes = ref(10080)
const zone = reactive<QueryZone>({
  mode: 'address',
  address: 'Rennes, France',
  addressConfirmed: false,
  latitude: 48.1173,
  longitude: -1.6778,
  radiusKm: 30,
  departmentCodes: [],
})
const sources = ref(['gbif', 'inaturalist'])
const areas = ref<GeographicArea[]>([])
const loadingAreas = ref(true)
const saving = ref(false)
const message = ref('')
const error = ref('')

watch(selectedTaxon, (taxon) => {
  taxonScope.value = taxon?.defaultScope ?? 'exact'
})

function backendZone() {
  return zone.mode === 'france'
    ? { type: 'france' }
    : zone.mode === 'departments'
    ? { type: 'departments', department_codes: zone.departmentCodes }
    : {
        type: 'radius',
        ...(zone.mode === 'address' ? { address: zone.address.trim() } : {}),
        latitude: zone.latitude,
        longitude: zone.longitude,
        radius_km: zone.radiusKm,
      }
}

function apiError(exception: any): string {
  const errors = exception.data?.errors
  return errors ? Object.values(errors).flat().join('\n') : (exception.data?.message || exception.message || 'Erreur inconnue.')
}

async function save() {
  message.value = ''
  error.value = ''
  if (!selectedTaxon.value) error.value = 'Cherchez puis sélectionnez un taxon dans la liste.'
  else if (!sources.value.length) error.value = 'Sélectionnez au moins une source.'
  else if (zone.mode === 'address' && !zone.addressConfirmed) error.value = 'Sélectionnez une adresse proposée.'
  else if (zone.mode === 'departments' && !zone.departmentCodes.length) error.value = 'Sélectionnez au moins un département.'
  if (error.value) return

  saving.value = true
  try {
    const response = await api<{ data: { id: number } }>('/monitoring', {
      method: 'POST',
      body: {
        name: name.value,
        taxon_id: selectedTaxon.value!.id,
        taxon_scope: taxonScope.value,
        zone: backendZone(),
        sources: sources.value,
        frequency_minutes: frequency.value,
        window_minutes: windowMinutes.value,
        is_active: true,
      },
    })
    message.value = `Surveillance #${response.data.id} créée.`
    await navigateTo(`/surveillances/${response.data.id}`)
  } catch (exception: any) {
    error.value = apiError(exception)
  } finally {
    saving.value = false
  }
}

try {
  areas.value = (await api<{ data: GeographicArea[] }>('/geographic-areas')).data
} catch (exception: any) {
  error.value = apiError(exception)
} finally {
  loadingAreas.value = false
}
</script>

<template>
  <section>
    <h1>Nouvelle surveillance</h1>
    <form class="card monitoring-form" @submit.prevent="save">
      <div class="form-grid">
        <label>Nom<input v-model="name" required maxlength="255"></label>
        <label class="taxon-field">Taxon<TaxonPicker v-model="selectedTaxon" required /></label>
        <label>
          Portée taxonomique
          <select v-model="taxonScope">
            <option value="exact">Taxon exact</option>
            <option value="subtree">Taxon et descendants</option>
          </select>
        </label>
        <label>Fréquence (min)<input v-model.number="frequency" type="number" min="5" required></label>
        <label>Fenêtre glissante (min)<input v-model.number="windowMinutes" type="number" min="5" required></label>
      </div>

      <ZonePicker v-model="zone" :areas="areas" :loading-areas="loadingAreas" />
      <SourcePicker v-model="sources" :taxon="selectedTaxon" :taxon-scope="taxonScope" :zone="zone" :areas="areas" />

      <button type="submit" :disabled="saving">{{ saving ? 'Création…' : 'Créer' }}</button>
      <p v-if="message" class="success">{{ message }}</p>
      <p v-if="error" class="error">{{ error }}</p>
    </form>
  </section>
</template>
