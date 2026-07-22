<script setup lang="ts">
interface TaxonResult {
  id: number
  acceptedScientificName: string
  preferredFrenchName?: string | null
  matchedName: string
  matchedNameType: string
  rank: { code?: string | null; label?: string | null }
  lineage: string[]
  reference: { provider: string; version: string; cdRef: number } | null
  defaultScope: 'exact' | 'subtree'
  sourceAvailability: { gbif: boolean; inaturalist: boolean; fauneFrance: boolean }
  localStatus?: string
}

const selected = defineModel<TaxonResult | null>({ default: null })
const props = withDefaults(defineProps<{ placeholder?: string; required?: boolean }>(), {
  placeholder: 'Nom français ou scientifique',
  required: false,
})
const api = useApi()
const query = ref('')
const results = ref<TaxonResult[]>([])
const loading = ref(false)
const error = ref('')
let timer: ReturnType<typeof setTimeout> | undefined

watch(selected, (value) => {
  if (value) query.value = value.preferredFrenchName || value.acceptedScientificName
}, { immediate: true })

watch(query, (value) => {
  if (selected.value && value !== (selected.value.preferredFrenchName || selected.value.acceptedScientificName)) {
    selected.value = null
  }
  results.value = []
  error.value = ''
  if (timer) clearTimeout(timer)
  if (value.trim().length < 2 || selected.value) return
  timer = setTimeout(search, 300)
})

async function search() {
  if (query.value.trim().length < 2) return
  loading.value = true
  try {
    const response = await api<{ data: TaxonResult[] }>('/taxa/search', { query: { q: query.value, limit: 12 } })
    results.value = response.data
  } catch (exception: any) {
    error.value = exception.data?.message || exception.message || 'Recherche taxonomique indisponible.'
  } finally {
    loading.value = false
  }
}

function choose(taxon: TaxonResult) {
  selected.value = taxon
  query.value = taxon.preferredFrenchName || taxon.acceptedScientificName
  results.value = []
}
</script>

<template>
  <div class="taxon-picker">
    <input
      v-model="query"
      :required="props.required"
      :placeholder="props.placeholder"
      autocomplete="off"
      role="combobox"
      :aria-expanded="results.length > 0"
      @keyup.enter.prevent="search"
    >
    <small v-if="loading">Recherche locale…</small>
    <small v-else-if="selected" class="field-confirmation">
      {{ selected.rank.label || selected.rank.code }} · {{ selected.defaultScope === 'subtree' ? 'descendants inclus' : 'taxon exact' }}
    </small>
    <small v-else-if="query.trim()" class="muted">Sélectionnez un résultat dans la liste.</small>
    <small v-if="error" class="error">{{ error }}</small>
    <div v-if="results.length" class="taxon-results" role="listbox">
      <button v-for="taxon in results" :key="taxon.id" type="button" role="option" @click="choose(taxon)">
        <strong>{{ taxon.preferredFrenchName || taxon.acceptedScientificName }}</strong>
        <i v-if="taxon.preferredFrenchName">{{ taxon.acceptedScientificName }}</i>
        <small>{{ taxon.rank.label || taxon.rank.code }}<template v-if="taxon.lineage.length"> · {{ taxon.lineage.join(' › ') }}</template></small>
        <small v-if="taxon.matchedName !== taxon.preferredFrenchName && taxon.matchedName !== taxon.acceptedScientificName">
          Correspondance : {{ taxon.matchedName }}
        </small>
      </button>
    </div>
  </div>
</template>

<style scoped>
.taxon-picker { position: relative; display: grid; gap: .25rem; }
.taxon-results { position: absolute; z-index: 20; top: 100%; left: 0; right: 0; max-height: 24rem; overflow: auto; background: white; border: 1px solid #bcc8c1; border-radius: .5rem; box-shadow: 0 .6rem 1.5rem #173c2b25; }
.taxon-results button { width: 100%; display: grid; gap: .15rem; padding: .65rem .75rem; border: 0; border-bottom: 1px solid #e2e7e3; border-radius: 0; background: white; color: inherit; text-align: left; }
.taxon-results button:hover, .taxon-results button:focus { background: #edf5f0; }
.taxon-results i, .taxon-results small { font-weight: 400; }
</style>
