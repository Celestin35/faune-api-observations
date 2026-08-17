<script setup lang="ts">
interface GeographicArea {
  code: string
  name: string
}

interface MonitoringRule {
  id: number
  name: string
  taxon?: { scientific_name: string } | null
  zone_type: 'france' | 'radius' | 'departments'
  zone_data: {
    type: 'france' | 'radius' | 'departments'
    address?: string
    latitude?: number
    longitude?: number
    radius_km?: number
    department_codes?: string[]
  }
  sources: string[]
  frequency_minutes: number
  window_minutes: number
  is_active: boolean
  next_sync_at?: string | null
  observations_count: number
}

const api = useApi()
const rules = ref<MonitoringRule[]>([])
const areas = ref<GeographicArea[]>([])
const message = ref('')
const error = ref('')
const deletingRuleId = ref<number | null>(null)

const areaNames = computed(() => new Map(areas.value.map(area => [area.code, area.name])))

async function load() {
  error.value = ''
  try {
    const [monitoringResponse, areasResponse] = await Promise.all([
      api<{ data: MonitoringRule[] }>('/monitoring'),
      api<{ data: GeographicArea[] }>('/geographic-areas'),
    ])
    rules.value = monitoringResponse.data
    areas.value = areasResponse.data
  } catch (exception: any) {
    error.value = exception.data?.message || exception.message || 'Impossible de charger les surveillances.'
  }
}

function zoneLabel(rule: MonitoringRule): string {
  if (rule.zone_type === 'france') return 'France entière'
  if (rule.zone_type === 'departments') {
    return (rule.zone_data.department_codes || [])
      .map(code => `${areaNames.value.get(code) || 'Département'} — ${code}`)
      .join(', ')
  }

  const point = `${rule.zone_data.latitude}, ${rule.zone_data.longitude}`
  const location = rule.zone_data.address ? `${rule.zone_data.address} · ${point}` : point
  return `${location} · rayon ${rule.zone_data.radius_km} km`
}

async function toggle(rule: MonitoringRule) {
  message.value = ''
  error.value = ''
  try {
    await api(`/monitoring/${rule.id}`, { method: 'PATCH', body: { is_active: !rule.is_active } })
    await load()
  } catch (exception: any) {
    error.value = exception.data?.message || exception.message || 'Impossible de modifier la surveillance.'
  }
}

async function sync(rule: MonitoringRule) {
  message.value = ''
  error.value = ''
  try {
    await api(`/monitoring/${rule.id}/sync`, { method: 'POST' })
    message.value = `Synchronisation ${rule.name} planifiée.`
    await load()
  } catch (exception: any) {
    error.value = exception.data?.message || exception.message || 'Impossible de planifier la synchronisation.'
  }
}

async function remove(rule: MonitoringRule) {
  const confirmed = window.confirm(
    `Supprimer définitivement la surveillance « ${rule.name} » et les observations qui ne sont utilisées nulle part ailleurs ?`,
  )
  if (!confirmed) {
    return
  }

  message.value = ''
  error.value = ''
  deletingRuleId.value = rule.id
  try {
    await api(`/monitoring/${rule.id}`, { method: 'DELETE' })
    rules.value = rules.value.filter(candidate => candidate.id !== rule.id)
    message.value = `Surveillance « ${rule.name} » supprimée.`
  } catch (exception: any) {
    error.value = exception.data?.message || exception.message || 'Impossible de supprimer la surveillance.'
  } finally {
    deletingRuleId.value = null
  }
}

await load()
</script>

<template>
  <section>
    <div class="page-heading">
      <div>
        <h1>Surveillances</h1>
        <p class="muted">Fréquence minimale : iNaturalist 5 min, GBIF 30 min.</p>
      </div>
      <NuxtLink to="/surveillances/nouvelle"><button>Nouvelle surveillance</button></NuxtLink>
    </div>

    <p v-if="message" class="success">{{ message }}</p>
    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="rules.length === 0 && !error" class="card muted">Aucune surveillance pour le moment.</p>
    <div class="grid">
      <article v-for="rule in rules" :key="rule.id" class="card">
        <h2>{{ rule.name }}</h2>
        <p><i>{{ rule.taxon?.scientific_name || 'Animalia' }}</i></p>
        <p class="monitoring-zone">{{ zoneLabel(rule) }}</p>
        <div class="badges">
          <span v-for="source in rule.sources" :key="source" class="badge">{{ source }}</span>
        </div>
        <p>Toutes les {{ rule.frequency_minutes }} min · fenêtre {{ rule.window_minutes }} min</p>
        <p><strong>{{ rule.observations_count }}</strong> observation{{ rule.observations_count > 1 ? 's' : '' }} dans l’historique de 2 mois</p>
        <p :class="rule.is_active ? 'success' : 'muted'">{{ rule.is_active ? 'Active' : 'Désactivée' }}</p>
        <small>Prochaine : {{ rule.next_sync_at || 'à planifier' }}</small>
        <div class="actions">
          <NuxtLink :to="`/surveillances/${rule.id}`"><button>Voir l’historique</button></NuxtLink>
          <button class="secondary" :disabled="deletingRuleId === rule.id" @click="sync(rule)">Synchroniser</button>
          <button class="secondary" :disabled="deletingRuleId === rule.id" @click="toggle(rule)">{{ rule.is_active ? 'Désactiver' : 'Activer' }}</button>
          <button
            class="danger"
            :disabled="deletingRuleId === rule.id"
            :aria-label="`Supprimer la surveillance ${rule.name}`"
            @click="remove(rule)"
          >
            {{ deletingRuleId === rule.id ? 'Suppression…' : 'Supprimer' }}
          </button>
        </div>
      </article>
    </div>
  </section>
</template>
