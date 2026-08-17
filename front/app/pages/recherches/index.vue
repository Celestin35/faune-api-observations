<script setup lang="ts">
type ImportItem = { id: number; source: string; status: string }
type SearchItem = {
  id: number
  name: string
  taxon?: { scientific_name?: string | null; vernacular_name?: string | null; preferred_french_name?: string | null } | null
  date_from?: string | null
  date_to?: string | null
  zone_type: 'france' | 'departments' | 'radius'
  zone_data: { address?: string; department_codes?: string[]; radius_km?: number }
  sources: string[]
  observations_count: number
  imports: ImportItem[]
  created_at: string
}

const api = useApi()
const searches = ref<SearchItem[]>([])
const loading = ref(true)
const deleting = ref<number | null>(null)
const error = ref('')
const message = ref('')

const statusLabels: Record<string, string> = {
  pending: 'En attente', running: 'En cours', completed: 'Terminée', partial: 'Partielle', failed: 'Échec', cancelled: 'Annulée',
}

function formatDate(value?: string | null): string {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString('fr-FR')
}

function zoneLabel(search: SearchItem): string {
  if (search.zone_type === 'france') return 'France entière'
  if (search.zone_type === 'departments') return `Départements : ${(search.zone_data.department_codes || []).join(', ')}`
  return `${search.zone_data.address || 'Point sélectionné'} · rayon ${search.zone_data.radius_km} km`
}

function searchStatus(search: SearchItem): string {
  if (search.imports.some(item => item.status === 'running')) return 'running'
  if (search.imports.some(item => item.status === 'pending')) return 'pending'
  if (search.imports.some(item => item.status === 'failed')) return 'failed'
  if (search.imports.some(item => item.status === 'partial')) return 'partial'
  if (search.imports.length && search.imports.every(item => item.status === 'cancelled')) return 'cancelled'
  return 'completed'
}

async function load(silent = false) {
  if (!silent) loading.value = true
  try {
    searches.value = (await api<{ data: SearchItem[] }>('/collections')).data
    error.value = ''
  } catch (exception: any) {
    error.value = exception.data?.message || exception.message || 'Impossible de charger les recherches.'
  } finally {
    loading.value = false
  }
}

async function remove(search: SearchItem) {
  if (!confirm(`Supprimer la recherche « ${search.name} » et ses observations qui ne sont utilisées nulle part ailleurs ?`)) return
  deleting.value = search.id
  error.value = ''
  message.value = ''
  try {
    await api(`/collections/${search.id}`, { method: 'DELETE' })
    searches.value = searches.value.filter(item => item.id !== search.id)
    message.value = `Recherche « ${search.name} » supprimée.`
  } catch (exception: any) {
    error.value = exception.data?.message || exception.message || 'Impossible de supprimer cette recherche.'
  } finally {
    deleting.value = null
  }
}

await load()
let interval: number | undefined
onMounted(() => interval = window.setInterval(() => void load(true), 3000))
onBeforeUnmount(() => clearInterval(interval))
</script>

<template>
  <section>
    <div class="page-heading">
      <div>
        <h1>Dernières recherches</h1>
        <p class="muted">Ouvrez une recherche pour consulter ses observations en liste ou sur la carte.</p>
      </div>
      <NuxtLink to="/exploration"><button>Nouvelle recherche</button></NuxtLink>
    </div>
    <p v-if="message" class="success">{{ message }}</p>
    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="loading" class="card muted">Chargement des recherches…</p>
    <p v-else-if="searches.length === 0 && !error" class="card muted">Aucune recherche enregistrée. Lancez-en une depuis Explorer.</p>
    <div v-else class="search-list">
      <article v-for="search in searches" :key="search.id" class="card">
        <div class="page-heading">
          <div>
            <h2><NuxtLink :to="`/recherches/${search.id}`">{{ search.name }}</NuxtLink></h2>
            <p><strong>{{ search.taxon?.preferred_french_name || search.taxon?.vernacular_name || search.taxon?.scientific_name || 'Tous les animaux' }}</strong></p>
          </div>
          <span :class="`status-${searchStatus(search)}`">{{ statusLabels[searchStatus(search)] }}</span>
        </div>
        <p>{{ formatDate(search.date_from) }} → {{ formatDate(search.date_to) }} · {{ zoneLabel(search) }}</p>
        <div class="badges"><span v-for="source in search.sources" :key="source" class="badge">{{ source }}</span></div>
        <p><strong>{{ search.observations_count }}</strong> observation{{ search.observations_count > 1 ? 's' : '' }}</p>
        <small class="muted">Recherche créée le {{ formatDate(search.created_at) }}</small>
        <div class="actions">
          <NuxtLink :to="`/recherches/${search.id}`"><button>Voir les observations</button></NuxtLink>
          <button class="danger" :disabled="deleting === search.id" @click="remove(search)">{{ deleting === search.id ? 'Suppression…' : 'Supprimer' }}</button>
        </div>
      </article>
    </div>
  </section>
</template>
