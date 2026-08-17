<script setup lang="ts">
import type { DateRange, GeographicArea, QueryZone, TaxonResult } from '~/types/observation-query'

const api = useApi()
const searchName = ref('')
const today = new Date().toISOString().slice(0, 10)
const monthAgo = new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10)
const selectedTaxon = ref<TaxonResult | null>(null)
const taxonScope = ref<'exact' | 'subtree'>('subtree')
const dates = reactive<DateRange>({ dateFrom: monthAgo, dateTo: today })
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
const estimate = ref<any>()
const busy = ref(false)
const message = ref('')
const error = ref('')
const loadingAreas = ref(true)
const areas = ref<GeographicArea[]>([])

watch(selectedTaxon, (taxon) => {
  taxonScope.value = taxon?.defaultScope ?? 'subtree'
})
watch([selectedTaxon, taxonScope, dates, zone, sources], () => {
  estimate.value = undefined
  message.value = ''
}, { deep: true })

function backendZone() {
  if (zone.mode === 'france') {
    return { type: 'france' }
  }
  if (zone.mode === 'departments') {
    return { type: 'departments', department_codes: zone.departmentCodes }
  }
  return {
    type: 'radius',
    ...(zone.mode === 'address' ? { address: zone.address.trim() } : {}),
    latitude: zone.latitude,
    longitude: zone.longitude,
    radius_km: zone.radiusKm,
  }
}

function body() {
  return {
    taxon_id: selectedTaxon.value?.id,
    taxon_scope: taxonScope.value,
    date_from: dates.dateFrom,
    date_to: dates.dateTo,
    sources: sources.value,
    zone: backendZone(),
  }
}

function validate(): string | null {
  if (!sources.value.length) return 'Sélectionnez au moins une source.'
  if (zone.mode === 'departments' && !zone.departmentCodes.length) return 'Sélectionnez au moins un département.'
  if (zone.mode === 'address' && !zone.addressConfirmed) return 'Sélectionnez une adresse proposée afin de valider ses coordonnées.'
  return null
}

function validateImport(): string | null {
  if (!searchName.value.trim()) return 'Donnez un nom à cette recherche pour pouvoir la retrouver.'
  return validate()
}

function apiError(exception: any): string {
  const errors = exception.data?.errors
  return errors ? Object.values(errors).flat().join('\n') : (exception.data?.message || exception.message || 'Erreur inconnue.')
}

async function runEstimate() {
  error.value = validate() ?? ''
  if (error.value) return
  busy.value = true
  try {
    estimate.value = await api('/searches/estimate', { method: 'POST', body: body() })
  } catch (exception: any) {
    error.value = apiError(exception)
  } finally {
    busy.value = false
  }
}

async function importNow() {
  if (!estimate.value || !confirm(`Confirmer l’import (plafond ${estimate.value.import_limit_per_source} par source) ?`)) return
  error.value = validateImport() ?? ''
  if (error.value) return
  busy.value = true
  let collectionId: number | null = null
  try {
    const collection = await api<{ data: { id: number } }>('/collections', {
      method: 'POST',
      body: { ...body(), name: searchName.value.trim(), is_permanent: true },
    })
    collectionId = collection.data.id
    const result = await api<{ data: unknown[] }>('/imports', {
      method: 'POST',
      body: { ...body(), data_collection_id: collectionId, confirmed: true, estimates: estimate.value.external },
    })
    message.value = `${result.data.length} import(s) placé(s) dans la file de traitement.`
    await navigateTo(`/recherches/${collectionId}`)
  } catch (exception: any) {
    error.value = apiError(exception)
    if (collectionId !== null) {
      try {
        await api(`/collections/${collectionId}`, { method: 'DELETE' })
      } catch {
        error.value += `\nLa recherche #${collectionId} a été créée : ouvrez l’onglet Recherches pour vérifier son état.`
      }
    }
  } finally {
    busy.value = false
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
    <h1>Explorer les observations</h1>
    <div class="card monitoring-form">
      <label>
        Nom de la recherche
        <input v-model="searchName" maxlength="255" placeholder="Ex. Milan noir — été dernier — France" required>
      </label>
      <div class="form-grid">
        <label class="taxon-field">
          Taxon ou tous les animaux
          <TaxonPicker v-model="selectedTaxon" placeholder="Vide = toutes les observations" />
        </label>
        <label>
          Portée taxonomique
          <select v-model="taxonScope">
            <option value="exact">Taxon exact</option>
            <option value="subtree">Taxon et descendants</option>
          </select>
        </label>
        <DateRangePicker v-model="dates" />
      </div>

      <ZonePicker v-model="zone" :areas="areas" :loading-areas="loadingAreas" />
      <SourcePicker v-model="sources" :taxon="selectedTaxon" :taxon-scope="taxonScope" :zone="zone" :areas="areas" />

      <div class="actions">
        <button :disabled="busy" @click="runEstimate">{{ busy ? 'Traitement…' : 'Estimer' }}</button>
        <button class="secondary" :disabled="busy || !estimate" @click="importNow">Enregistrer et importer</button>
      </div>
      <p v-if="error" class="error">{{ error }}</p>
      <p v-if="message" class="success">{{ message }}</p>
    </div>

    <div v-if="estimate" class="grid estimate-grid">
      <article class="card">
        <div class="stat">{{ estimate.local.count }}</div>
        déjà en local
        <br><small>{{ estimate.local.covered_from || 'aucune couverture' }} → {{ estimate.local.covered_to || '—' }}</small>
      </article>
      <article v-for="(result, source) in estimate.external" :key="source" class="card">
        <template v-if="typeof result === 'number'">
          <div class="stat">{{ result }}</div>{{ source }}
        </template>
        <template v-else-if="result.estimable === false">
          <div class="stat">—</div>{{ source }}
          <p class="muted">{{ result.message }}</p>
        </template>
        <template v-else>
          <div class="stat">—</div>{{ source }}
          <p class="error">{{ result.error }}</p>
        </template>
      </article>
      <article class="card">
        <strong>Recouvrement estimé</strong>
        <p>iNaturalist dans GBIF : {{ estimate.overlap.inaturalist_in_gbif ?? 'inconnu' }}</p>
        <p>Manquantes : {{ estimate.overlap.estimated_inaturalist_missing_from_gbif ?? 'inconnu' }}</p>
      </article>
    </div>
    <p v-if="estimate" class="muted">{{ estimate.warning }} Couverture complète : {{ estimate.coverage_complete ? 'oui' : 'non' }}.</p>
  </section>
</template>
