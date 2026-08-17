<script setup lang="ts">
type ObservationItem = {
  id: number
  taxon?: { frenchName?: string | null; scientificName?: string | null } | null
  observedAt?: string | null
  historyAt?: string | null
  location: {
    locality?: string | null
    municipality?: string | null
    department?: string | null
    departmentCode?: string | null
  }
  individualCount?: number | null
  validationStatus?: string | null
  sources: Array<{ source: string }>
}

const props = withDefaults(defineProps<{
  observationsPath: string
  mapPath: string
  returnTo: string
  emptyText?: string
  refreshMs?: number
}>(), {
  emptyText: 'Aucune observation pour le moment.',
  refreshMs: 0,
})

const api = useApi()
const view = ref<'list' | 'map'>('list')
const observations = ref<ObservationItem[]>([])
const mapObservations = ref<any[]>([])
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const loading = ref(true)
const mapLoading = ref(false)
const mapLoaded = ref(false)
const mapTruncated = ref(false)
const mapLimit = ref(0)
const error = ref('')

function apiError(exception: any): string {
  return exception.data?.message || exception.message || 'Impossible de charger les observations.'
}

function formatDate(value?: string | null): string {
  if (!value) return 'Date inconnue'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('fr-FR', { dateStyle: 'medium', timeStyle: 'short' })
}

function locationLabel(item: ObservationItem): string {
  return item.location.locality
    || item.location.municipality
    || [item.location.department, item.location.departmentCode].filter(Boolean).join(' — ')
    || 'Lieu non renseigné'
}

async function loadList(silent = false) {
  if (!silent) loading.value = true
  try {
    const response = await api<{ data: ObservationItem[]; meta: { current_page: number; last_page: number; total: number } }>(props.observationsPath, {
      query: { page: page.value, per_page: 50 },
    })
    observations.value = response.data
    page.value = response.meta.current_page
    lastPage.value = response.meta.last_page
    total.value = response.meta.total
    error.value = ''
  } catch (exception: any) {
    error.value = apiError(exception)
  } finally {
    loading.value = false
  }
}

async function loadMap(silent = false) {
  if (!silent) mapLoading.value = true
  try {
    const response = await api<{ data: any[]; meta: { truncated: boolean; limit: number } }>(props.mapPath)
    mapObservations.value = response.data
    mapTruncated.value = response.meta.truncated
    mapLimit.value = response.meta.limit
    mapLoaded.value = true
    error.value = ''
  } catch (exception: any) {
    error.value = apiError(exception)
  } finally {
    mapLoading.value = false
  }
}

async function selectView(selected: 'list' | 'map') {
  view.value = selected
  if (selected === 'map' && !mapLoaded.value) await loadMap()
}

async function changePage(next: number) {
  page.value = next
  await loadList()
}

await loadList()
let interval: number | undefined
let mapInterval: number | undefined
onMounted(() => {
  if (!props.refreshMs) return
  interval = window.setInterval(() => void loadList(true), props.refreshMs)
  mapInterval = window.setInterval(() => {
    if (view.value === 'map') void loadMap(true)
  }, Math.max(props.refreshMs, 15000))
})
onBeforeUnmount(() => {
  clearInterval(interval)
  clearInterval(mapInterval)
})
</script>

<template>
  <div class="observation-browser">
    <div class="page-heading browser-heading">
      <p class="muted"><strong>{{ total }}</strong> observation{{ total > 1 ? 's' : '' }}</p>
      <div class="view-switch" role="group" aria-label="Affichage des observations">
        <button :class="{ secondary: view !== 'list' }" @click="selectView('list')">Liste</button>
        <button :class="{ secondary: view !== 'map' }" @click="selectView('map')">Carte</button>
      </div>
    </div>

    <p v-if="error" class="error">{{ error }}</p>
    <template v-if="view === 'list'">
      <p v-if="loading" class="card muted">Chargement des observations…</p>
      <p v-else-if="observations.length === 0" class="card muted">{{ emptyText }}</p>
      <div v-else class="table-scroll card table-card">
        <table>
          <thead><tr><th>Observation</th><th>Date observée</th><th>Lieu</th><th>Sources</th><th>Ajoutée à l’historique</th></tr></thead>
          <tbody>
            <tr v-for="item in observations" :key="item.id">
              <td>
                <NuxtLink class="observation-link" :to="`/observations/${item.id}?returnTo=${encodeURIComponent(returnTo)}`">
                  {{ item.taxon?.frenchName || item.taxon?.scientificName || `Observation #${item.id}` }}
                </NuxtLink>
                <small v-if="item.taxon?.scientificName" class="scientific-row"><em>{{ item.taxon.scientificName }}</em></small>
              </td>
              <td>{{ formatDate(item.observedAt) }}</td>
              <td>{{ locationLabel(item) }}</td>
              <td><span v-for="source in item.sources" :key="source.source" class="badge">{{ source.source }}</span></td>
              <td>{{ formatDate(item.historyAt) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="lastPage > 1" class="pagination">
        <button class="secondary" :disabled="page <= 1 || loading" @click="changePage(page - 1)">Précédent</button>
        <span>Page {{ page }} / {{ lastPage }}</span>
        <button class="secondary" :disabled="page >= lastPage || loading" @click="changePage(page + 1)">Suivant</button>
      </div>
    </template>

    <template v-else>
      <p v-if="mapLoading" class="card muted">Chargement de la carte…</p>
      <template v-else>
        <p v-if="mapTruncated" class="error">La carte affiche les {{ mapLimit }} observations les plus récentes. La liste reste complète.</p>
        <p v-if="mapObservations.length === 0" class="card muted">Aucune observation localisable sur la carte.</p>
        <MapView v-else :observations="mapObservations" :return-to="returnTo" />
      </template>
    </template>
  </div>
</template>
