<script setup lang="ts">
interface ImportItem {
  id: number
  source: string
  taxon?: { scientific_name: string; vernacular_name?: string | null; preferred_french_name?: string | null } | null
  date_from: string
  date_to: string
  zone_type: 'france' | 'radius' | 'departments'
  zone_data: { latitude?: number; longitude?: number; radius_km?: number; address?: string; department_codes?: string[] }
  status: string
  progress_stage?: 'queued' | 'fetching' | 'saving' | 'finished' | null
  progress_current?: number
  progress_total?: number | null
  progress_message?: string | null
  processed_count: number
  estimated_count?: number | null
  limit: number
  created_count: number
  updated_count: number
  unchanged_count: number
  failed_count: number
  error_message?: string | null
  started_at?: string | null
  finished_at?: string | null
}

const api = useApi()
const imports = ref<ImportItem[]>([])
const error = ref('')
const cancelling = ref<number | null>(null)

const statusLabels: Record<string, string> = {
  pending: 'En attente',
  running: 'En cours',
  completed: 'Terminé',
  partial: 'Partiel',
  failed: 'Échec',
  cancelled: 'Annulé',
}

function formatDate(value: string): string {
  const match = value.match(/^(\d{4})-(\d{2})-(\d{2})/)
  return match ? `${match[3]}/${match[2]}/${match[1]}` : value
}

function formatDateTime(value?: string | null): string {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('fr-FR')
}

function progressLabel(item: ImportItem): string {
  if (item.progress_stage === 'fetching') {
    const pages = item.progress_total ? `page ${item.progress_current || 0}/${item.progress_total}` : `page ${item.progress_current || 0}`
    return `Récupération : ${pages}${item.progress_message ? ` · ${item.progress_message}` : ''}`
  }
  if (item.progress_stage === 'saving') {
    const total = item.progress_total ?? item.estimated_count
    return `Enregistrement : ${item.processed_count}${total != null ? ` / ${total}` : ''}`
  }
  return item.progress_message || ''
}

function progressMaximum(item: ImportItem): number | undefined {
  return item.progress_total || item.estimated_count || undefined
}

function progressValue(item: ImportItem): number {
  return item.progress_stage === 'fetching' ? (item.progress_current || 0) : item.processed_count
}

function zoneLabel(item: ImportItem): string {
  if (item.zone_type === 'france') return 'France entière'
  if (item.zone_type === 'departments') return `Départements : ${(item.zone_data.department_codes || []).join(', ')}`
  const address = item.zone_data.address ? `${item.zone_data.address} · ` : ''
  return `${address}${item.zone_data.latitude}, ${item.zone_data.longitude} · ${item.zone_data.radius_km} km`
}

async function load() {
  try {
    imports.value = (await api<{ data: ImportItem[] }>('/imports')).data
    error.value = ''
  } catch (exception: any) {
    error.value = exception.data?.message || exception.message || 'Impossible de charger les imports.'
  }
}

async function cancel(item: ImportItem) {
  if (!confirm(`Annuler l’import #${item.id} ?`)) return
  cancelling.value = item.id
  try {
    await api(`/imports/${item.id}/cancel`, { method: 'PATCH' })
    await load()
  } catch (exception: any) {
    error.value = exception.data?.message || exception.message || 'Impossible d’annuler cet import.'
  } finally {
    cancelling.value = null
  }
}

await load()
let interval: number | undefined
onMounted(() => interval = window.setInterval(load, 3000))
onBeforeUnmount(() => clearInterval(interval))
</script>

<template>
  <section>
    <h1>Imports</h1>
    <p class="muted">GBIF, iNaturalist et Faune-France sont actualisés automatiquement toutes les trois secondes.</p>
    <p v-if="error" class="error">{{ error }}</p>
    <div class="import-list">
      <article v-for="item in imports" :key="item.id" class="card">
        <div class="page-heading">
          <div>
            <strong>#{{ item.id }} · {{ item.taxon?.preferred_french_name || item.taxon?.vernacular_name || item.taxon?.scientific_name || 'Tous les animaux' }}</strong>
            <p><span class="badge">{{ item.source }}</span> {{ formatDate(item.date_from) }} → {{ formatDate(item.date_to) }}</p>
          </div>
          <span :class="`status-${item.status}`">{{ statusLabels[item.status] || item.status }}</span>
        </div>
        <p class="monitoring-zone">{{ zoneLabel(item) }}</p>
        <div v-if="progressLabel(item)" class="import-progress">
          <strong>{{ progressLabel(item) }}</strong>
          <progress
            v-if="progressMaximum(item)"
            :value="progressValue(item)"
            :max="progressMaximum(item)"
          />
        </div>
        <p>
          Traité : {{ item.processed_count }}
          <template v-if="item.estimated_count != null"> / {{ item.estimated_count }}</template>
          <template v-else> · total encore inconnu</template>
          · plafond {{ item.limit }}
        </p>
        <p>Créées {{ item.created_count }} · mises à jour {{ item.updated_count }} · inchangées {{ item.unchanged_count }} · échecs {{ item.failed_count }}</p>
        <p v-if="item.started_at || item.finished_at" class="muted">Début : {{ formatDateTime(item.started_at) }} · fin : {{ formatDateTime(item.finished_at) }}</p>
        <p v-if="item.error_message" class="error">{{ item.error_message }}</p>
        <button
          v-if="item.status === 'pending'"
          class="danger"
          :disabled="cancelling === item.id"
          @click="cancel(item)"
        >
          {{ cancelling === item.id ? 'Annulation…' : 'Annuler' }}
        </button>
      </article>
    </div>
  </section>
</template>
