<script setup lang="ts">
type MonitoringDetail = {
  id: number
  name: string
  taxon?: { scientific_name?: string | null; vernacular_name?: string | null; preferred_french_name?: string | null } | null
  taxa?: Array<{
    scientific_name?: string | null
    pivot: { taxon_label_snapshot?: string | null; taxon_scope: 'exact' | 'subtree' }
  }>
  zone_type: 'france' | 'departments' | 'radius'
  zone_data: { address?: string; latitude?: number; longitude?: number; radius_km?: number; department_codes?: string[] }
  sources: string[]
  frequency_minutes: number
  window_minutes: number
  is_active: boolean
  observations_count: number
  last_synced_at?: string | null
  next_sync_at?: string | null
  last_error?: string | null
}

const route = useRoute()
const api = useApi()
const monitoring = ref<MonitoringDetail | null>(null)
const loading = ref(true)
const notFound = ref(false)
const error = ref('')
const syncing = ref(false)
const message = ref('')
const returnTo = computed(() => `/surveillances/${route.params.id}`)
const taxaLabel = computed(() => {
  if (!monitoring.value) return ''
  if (monitoring.value.taxa?.length) {
    return monitoring.value.taxa
      .map(taxon => taxon.pivot.taxon_label_snapshot || taxon.scientific_name)
      .filter(Boolean)
      .join(', ')
  }
  return monitoring.value.taxon?.preferred_french_name
    || monitoring.value.taxon?.vernacular_name
    || monitoring.value.taxon?.scientific_name
    || 'Taxon non renseigné'
})

function formatDate(value?: string | null): string {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('fr-FR')
}

function zoneLabel(): string {
  if (!monitoring.value) return ''
  if (monitoring.value.zone_type === 'france') return 'France entière'
  if (monitoring.value.zone_type === 'departments') return `Départements : ${(monitoring.value.zone_data.department_codes || []).join(', ')}`
  const zone = monitoring.value.zone_data
  return `${zone.address || `${zone.latitude}, ${zone.longitude}`} · rayon ${zone.radius_km} km`
}

async function load(silent = false) {
  if (!silent) loading.value = true
  try {
    monitoring.value = (await api<{ data: MonitoringDetail }>(`/monitoring/${route.params.id}`)).data
    error.value = ''
  } catch (exception: any) {
    if (exception?.statusCode === 404 || exception?.response?.status === 404) notFound.value = true
    else error.value = exception.data?.message || exception.message || 'Impossible de charger cette surveillance.'
  } finally {
    loading.value = false
  }
}

async function syncNow() {
  if (!monitoring.value) return
  syncing.value = true
  message.value = ''
  error.value = ''
  try {
    await api(`/monitoring/${monitoring.value.id}/sync`, { method: 'POST' })
    message.value = 'Synchronisation planifiée.'
    await load(true)
  } catch (exception: any) {
    error.value = exception.data?.message || exception.message || 'Impossible de planifier la synchronisation.'
  } finally {
    syncing.value = false
  }
}

await load()
let interval: number | undefined
onMounted(() => interval = window.setInterval(() => void load(true), 10000))
onBeforeUnmount(() => clearInterval(interval))
useHead(() => ({ title: monitoring.value ? `${monitoring.value.name} — Surveillance` : 'Surveillance' }))
</script>

<template>
  <section>
    <p><NuxtLink to="/surveillances">← Retour aux surveillances</NuxtLink></p>
    <p v-if="loading" class="card muted">Chargement de la surveillance…</p>
    <div v-else-if="notFound" class="card"><h1>Surveillance introuvable</h1></div>
    <p v-else-if="error && !monitoring" class="card error">{{ error }}</p>
    <template v-else-if="monitoring">
      <div class="page-heading">
        <div>
          <h1>{{ monitoring.name }}</h1>
          <p><strong>{{ taxaLabel }}</strong></p>
          <p>{{ zoneLabel() }} · toutes les {{ monitoring.frequency_minutes }} min · rattrapage maximal {{ monitoring.window_minutes }} min</p>
          <div class="badges"><span v-for="source in monitoring.sources" :key="source" class="badge">{{ source }}</span></div>
          <p :class="monitoring.is_active ? 'success' : 'muted'">{{ monitoring.is_active ? 'Surveillance active' : 'Surveillance désactivée' }}</p>
          <small class="muted">Dernière synchronisation : {{ formatDate(monitoring.last_synced_at) }} · prochaine : {{ formatDate(monitoring.next_sync_at) }}</small>
        </div>
        <button :disabled="syncing" @click="syncNow">{{ syncing ? 'Planification…' : 'Synchroniser maintenant' }}</button>
      </div>
      <p v-if="message" class="success">{{ message }}</p>
      <p v-if="error" class="error">{{ error }}</p>
      <p v-if="monitoring.last_error" class="error">Dernière erreur : {{ monitoring.last_error }}</p>
      <p class="retention-notice">Cet historique conserve uniquement les détections des deux derniers mois. Les plus anciennes sont supprimées automatiquement.</p>

      <ObservationBrowser
        :observations-path="`/monitoring/${monitoring.id}/observations`"
        :map-path="`/monitoring/${monitoring.id}/observations/map`"
        :return-to="returnTo"
        :refresh-ms="10000"
        empty-text="Aucune observation détectée par cette surveillance pour le moment."
      />
    </template>
  </section>
</template>
