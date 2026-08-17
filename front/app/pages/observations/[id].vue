<script setup lang="ts">
type DetailLocation = {
  status: 'exact' | 'approximate' | 'source_masked' | 'unavailable' | null
  latitude: number | null
  longitude: number | null
  uncertaintyM: number | null
  locality?: string | null
  municipality?: string | null
  municipalityCode?: string | null
  department?: string | null
  departmentCode?: string | null
  region?: string | null
  country?: string | null
}
type Source = {
  source: string
  occurrenceId: string
  datasetId?: string | null
  scientificName?: string | null
  vernacularName?: string | null
  url?: string | null
  license?: string | null
  observedAt?: string | null
  temporalPrecision?: string | null
  location: DetailLocation & { sourcePrecision?: string | null }
  observerName?: string | null
  individualCount?: number | null
  validationStatus?: string | null
  lifeStage?: string | null
  sex?: string | null
  behavior?: string | null
  remarks?: string | null
  importedAt?: string | null
  media?: Array<{ type: string, url: string, thumbnailUrl?: string | null, sourcePageUrl?: string | null, license?: string | null, attribution?: string | null }>
}
type ObservationDetail = {
  id: number
  taxon: null | { id: number, frenchName?: string | null, scientificName: string, rank?: string | null, lineage: Array<{ id: number, scientificName: string, rank?: string | null }> }
  observedAt?: string | null
  temporalPrecision?: string | null
  location: DetailLocation
  individualCount?: number | null
  validationStatus?: string | null
  observerName?: string | null
  lifeStage?: string | null
  sex?: string | null
  behavior?: string | null
  remarks?: string | null
  firstImportedAt?: string | null
  lastSeenAt?: string | null
  sources: Source[]
}

const route = useRoute()
const api = useApi()
const observation = ref<ObservationDetail | null>(null)
const loading = ref(true)
const notFound = ref(false)
const error = ref('')
const returnTo = computed(() => {
  const value = typeof route.query.returnTo === 'string' ? route.query.returnTo : ''
  return value.startsWith('/') && !value.startsWith('//') ? value : '/recherches'
})

const sourceNames: Record<string, string> = {
  gbif: 'GBIF',
  inaturalist: 'iNaturalist',
  'faune-france': 'Faune-France',
  faune_france: 'Faune-France',
}
const locationLabels: Record<string, string> = {
  exact: 'Précise',
  approximate: 'Approximative',
  source_masked: 'Masquée par la source',
  unavailable: 'Indisponible',
}
const temporalLabels: Record<string, string> = { datetime: 'Date et heure', date: 'Date uniquement', unknown: 'Inconnue' }

function formatDate(value?: string | null) {
  return value ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long', timeStyle: 'short' }).format(new Date(value)) : 'Date inconnue'
}
function display(value: unknown) {
  return value === null || value === undefined || value === '' ? 'Non renseigné' : String(value)
}

try {
  const result = await api<{ data: ObservationDetail }>(`/observations/${route.params.id}`)
  observation.value = result.data
} catch (caught: any) {
  if (caught?.statusCode === 404 || caught?.response?.status === 404) notFound.value = true
  else error.value = caught?.data?.message || caught?.message || 'Impossible de charger cette observation.'
} finally {
  loading.value = false
}

useHead(() => ({ title: observation.value ? `${observation.value.taxon?.frenchName || observation.value.taxon?.scientificName || 'Observation'} — Observations` : 'Observation' }))
</script>

<template>
  <section>
    <p><NuxtLink :to="returnTo">← Retour aux observations</NuxtLink></p>
    <p v-if="loading" class="muted">Chargement de l’observation…</p>
    <div v-else-if="notFound" class="card">
      <h1>Observation introuvable</h1>
      <p>Cette observation n’existe pas ou n’est plus accessible.</p>
    </div>
    <div v-else-if="error" class="card">
      <h1>Erreur de chargement</h1>
      <p class="error">{{ error }}</p>
    </div>
    <template v-else-if="observation">
      <header class="observation-heading">
        <div>
          <h1>{{ observation.taxon?.frenchName || observation.taxon?.scientificName || 'Observation' }}</h1>
          <p v-if="observation.taxon?.scientificName" class="scientific-name"><em>{{ observation.taxon.scientificName }}</em></p>
          <p>{{ formatDate(observation.observedAt) }} · {{ temporalLabels[observation.temporalPrecision || 'unknown'] || observation.temporalPrecision }}</p>
        </div>
        <div class="badges">
          <span v-for="source in observation.sources" :key="`${source.source}-${source.occurrenceId}`" class="badge">{{ sourceNames[source.source] || source.source }}</span>
          <span v-if="observation.validationStatus" class="badge">{{ observation.validationStatus }}</span>
        </div>
      </header>

      <div class="observation-detail-grid">
        <article class="card observation-map-card">
          <h2>Carte</h2>
          <ObservationDetailMap :location="observation.location" />
        </article>

        <article class="card">
          <h2>Localisation</h2>
          <dl class="detail-list">
            <template v-if="observation.location.locality"><dt>Localité</dt><dd>{{ observation.location.locality }}</dd></template>
            <dt>Commune</dt><dd>{{ display(observation.location.municipality) }}</dd>
            <dt>Département</dt><dd>{{ display(observation.location.department) }}<template v-if="observation.location.departmentCode"> — {{ observation.location.departmentCode }}</template></dd>
            <dt>Région</dt><dd>{{ display(observation.location.region) }}</dd>
            <template v-if="observation.location.country"><dt>Pays</dt><dd>{{ observation.location.country }}</dd></template>
            <template v-if="observation.location.latitude !== null && observation.location.longitude !== null">
              <dt>Coordonnées publiques</dt><dd>{{ observation.location.latitude }}, {{ observation.location.longitude }}</dd>
            </template>
            <template v-if="observation.location.uncertaintyM"><dt>Incertitude</dt><dd>{{ observation.location.uncertaintyM }} m</dd></template>
            <dt>Précision</dt><dd>{{ locationLabels[observation.location.status || 'unavailable'] || observation.location.status }}</dd>
          </dl>
        </article>

        <article class="card">
          <h2>Observation</h2>
          <dl class="detail-list">
            <template v-if="observation.individualCount !== null && observation.individualCount !== undefined"><dt>Individus</dt><dd>{{ observation.individualCount }}</dd></template>
            <template v-if="observation.observerName"><dt>Observateur</dt><dd>{{ observation.observerName }}</dd></template>
            <template v-if="observation.lifeStage"><dt>Stade de vie</dt><dd>{{ observation.lifeStage }}</dd></template>
            <template v-if="observation.sex"><dt>Sexe</dt><dd>{{ observation.sex }}</dd></template>
            <template v-if="observation.behavior"><dt>Comportement</dt><dd>{{ observation.behavior }}</dd></template>
            <template v-if="observation.remarks"><dt>Remarques</dt><dd>{{ observation.remarks }}</dd></template>
          </dl>
          <p v-if="!observation.individualCount && !observation.observerName && !observation.lifeStage && !observation.sex && !observation.behavior && !observation.remarks" class="muted">Aucune information biologique complémentaire.</p>
        </article>

        <article class="card">
          <h2>Taxonomie</h2>
          <dl class="detail-list">
            <template v-if="observation.taxon?.frenchName"><dt>Nom français</dt><dd>{{ observation.taxon.frenchName }}</dd></template>
            <template v-if="observation.taxon?.scientificName"><dt>Nom scientifique</dt><dd><em>{{ observation.taxon.scientificName }}</em></dd></template>
            <template v-if="observation.taxon?.rank"><dt>Rang</dt><dd>{{ observation.taxon.rank }}</dd></template>
          </dl>
          <ol v-if="observation.taxon?.lineage.length" class="taxon-lineage">
            <li v-for="ancestor in observation.taxon.lineage" :key="ancestor.id">{{ ancestor.rank ? `${ancestor.rank} — ` : '' }}<em>{{ ancestor.scientificName }}</em></li>
          </ol>
          <p v-else class="muted">Lignée TAXREF non renseignée.</p>
        </article>
      </div>

      <section class="observation-sources">
        <h2>Provenances</h2>
        <div class="grid">
          <article v-for="source in observation.sources" :key="`${source.source}-${source.occurrenceId}`" class="card source-detail">
            <div class="page-heading"><h3>{{ sourceNames[source.source] || source.source }}</h3><a v-if="source.url" :href="source.url" target="_blank" rel="noopener noreferrer">Voir l’original</a></div>
            <dl class="detail-list">
              <dt>Identifiant</dt><dd>{{ source.occurrenceId }}</dd>
              <template v-if="source.datasetId"><dt>Jeu de données</dt><dd>{{ source.datasetId }}</dd></template>
              <template v-if="source.license"><dt>Licence</dt><dd>{{ source.license }}</dd></template>
              <template v-if="source.observedAt"><dt>Date source</dt><dd>{{ formatDate(source.observedAt) }}</dd></template>
              <dt>Précision source</dt><dd>{{ locationLabels[source.location.status || 'unavailable'] || source.location.status }}</dd>
              <template v-if="source.location.locality"><dt>Lieu source</dt><dd>{{ source.location.locality }}</dd></template>
              <template v-if="source.location.latitude !== null && source.location.longitude !== null"><dt>Coordonnées publiques</dt><dd>{{ source.location.latitude }}, {{ source.location.longitude }}</dd></template>
              <template v-if="source.location.uncertaintyM"><dt>Incertitude</dt><dd>{{ source.location.uncertaintyM }} m</dd></template>
              <template v-if="source.importedAt"><dt>Importée le</dt><dd>{{ formatDate(source.importedAt) }}</dd></template>
            </dl>
            <div v-if="source.media?.length" class="source-media">
              <a v-for="medium in source.media" :key="medium.url" :href="medium.sourcePageUrl || medium.url" target="_blank" rel="noopener noreferrer">
                <img v-if="medium.thumbnailUrl || medium.type.toLowerCase().includes('image')" :src="medium.thumbnailUrl || medium.url" :alt="medium.attribution || `Média ${sourceNames[source.source] || source.source}`">
                <span v-else>Voir le média</span>
              </a>
            </div>
          </article>
        </div>
      </section>
    </template>
  </section>
</template>
