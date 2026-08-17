<script setup lang="ts">
type ImportItem = {
  id: number; source: string; status: string; processed_count: number; estimated_count?: number | null
  created_count: number; updated_count: number; failed_count: number; error_message?: string | null
}
type SearchDetail = {
  id: number
  name: string
  taxon?: { scientific_name?: string | null; vernacular_name?: string | null; preferred_french_name?: string | null } | null
  date_from?: string | null
  date_to?: string | null
  zone_type: 'france' | 'departments' | 'radius'
  zone_data: { address?: string; latitude?: number; longitude?: number; radius_km?: number; department_codes?: string[] }
  sources: string[]
  observations_count: number
  imports: ImportItem[]
}

const route = useRoute()
const api = useApi()
const search = ref<SearchDetail | null>(null)
const loading = ref(true)
const notFound = ref(false)
const error = ref('')
const returnTo = computed(() => `/recherches/${route.params.id}`)

const statusLabels: Record<string, string> = {
  pending: 'En attente', running: 'En cours', completed: 'Terminé', partial: 'Partiel', failed: 'Échec', cancelled: 'Annulé',
}

function formatDate(value?: string | null): string {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString('fr-FR')
}

function zoneLabel(): string {
  if (!search.value) return ''
  if (search.value.zone_type === 'france') return 'France entière'
  if (search.value.zone_type === 'departments') return `Départements : ${(search.value.zone_data.department_codes || []).join(', ')}`
  const zone = search.value.zone_data
  return `${zone.address || `${zone.latitude}, ${zone.longitude}`} · rayon ${zone.radius_km} km`
}

async function load(silent = false) {
  if (!silent) loading.value = true
  try {
    search.value = (await api<{ data: SearchDetail }>(`/collections/${route.params.id}`)).data
    error.value = ''
  } catch (exception: any) {
    if (exception?.statusCode === 404 || exception?.response?.status === 404) notFound.value = true
    else error.value = exception.data?.message || exception.message || 'Impossible de charger cette recherche.'
  } finally {
    loading.value = false
  }
}

await load()
let interval: number | undefined
onMounted(() => interval = window.setInterval(() => void load(true), 3000))
onBeforeUnmount(() => clearInterval(interval))
useHead(() => ({ title: search.value ? `${search.value.name} — Recherches` : 'Recherche' }))
</script>

<template>
  <section>
    <p><NuxtLink to="/recherches">← Retour aux recherches</NuxtLink></p>
    <p v-if="loading" class="card muted">Chargement de la recherche…</p>
    <div v-else-if="notFound" class="card"><h1>Recherche introuvable</h1></div>
    <p v-else-if="error" class="card error">{{ error }}</p>
    <template v-else-if="search">
      <div class="page-heading">
        <div>
          <h1>{{ search.name }}</h1>
          <p><strong>{{ search.taxon?.preferred_french_name || search.taxon?.vernacular_name || search.taxon?.scientific_name || 'Tous les animaux' }}</strong></p>
          <p>{{ formatDate(search.date_from) }} → {{ formatDate(search.date_to) }} · {{ zoneLabel() }}</p>
          <div class="badges"><span v-for="source in search.sources" :key="source" class="badge">{{ source }}</span></div>
        </div>
        <NuxtLink to="/exploration"><button class="secondary">Nouvelle recherche</button></NuxtLink>
      </div>

      <details v-if="search.imports.length" class="card import-summary">
        <summary>État des imports</summary>
        <ul>
          <li v-for="item in search.imports" :key="item.id">
            <span class="badge">{{ item.source }}</span>
            <span :class="`status-${item.status}`">{{ statusLabels[item.status] || item.status }}</span>
            · {{ item.processed_count }} traitées · {{ item.created_count }} créées · {{ item.updated_count }} mises à jour
            <span v-if="item.error_message" class="error"> · {{ item.error_message }}</span>
          </li>
        </ul>
      </details>

      <ObservationBrowser
        :observations-path="`/collections/${search.id}/observations`"
        :map-path="`/collections/${search.id}/observations/map`"
        :return-to="returnTo"
        :refresh-ms="3000"
        empty-text="Aucune observation importée pour cette recherche pour le moment."
      />
    </template>
  </section>
</template>
