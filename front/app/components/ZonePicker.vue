<script setup lang="ts">
import type { GeographicArea, QueryZone } from '~/types/observation-query'

interface AddressSuggestion {
  label: string
  latitude: number
  longitude: number
  kind?: string | null
}

const zone = defineModel<QueryZone>({ required: true })
defineProps<{ areas: GeographicArea[]; loadingAreas?: boolean }>()
const api = useApi()
const suggestions = ref<AddressSuggestion[]>([])
const pickerOpen = ref(false)
const loading = ref(false)
const error = ref('')
let timer: ReturnType<typeof setTimeout> | undefined
let confirmedAddress = ''

watch(() => zone.value.address, (value, previous) => {
  if (value === previous) return
  if (value === confirmedAddress) return
  zone.value.addressConfirmed = false
  suggestions.value = []
  error.value = ''
  if (timer) clearTimeout(timer)
  if (zone.value.mode !== 'address' || value.trim().length < 3) return
  timer = setTimeout(search, 300)
})

async function search() {
  const query = zone.value.address.trim()
  if (query.length < 3 || zone.value.mode !== 'address') return
  loading.value = true
  try {
    const response = await api<{ data: AddressSuggestion[] }>('/geocoding/addresses', { query: { q: query, limit: 6 } })
    if (zone.value.address.trim() !== query) return
    suggestions.value = response.data
    pickerOpen.value = true
    if (!response.data.length) error.value = 'Aucune adresse ou lieu correspondant.'
  } catch (exception: any) {
    error.value = exception.data?.message || exception.message || 'La recherche d’adresses est indisponible.'
  } finally {
    loading.value = false
  }
}

function choose(suggestion: AddressSuggestion) {
  confirmedAddress = suggestion.label
  zone.value.address = suggestion.label
  zone.value.latitude = suggestion.latitude
  zone.value.longitude = suggestion.longitude
  zone.value.addressConfirmed = true
  suggestions.value = []
  pickerOpen.value = false
  error.value = ''
}
</script>

<template>
  <fieldset class="form-section">
    <legend>Zone</legend>
    <div class="zone-type-switch">
      <label :class="{ selected: zone.mode === 'france' }">
        <input v-model="zone.mode" type="radio" value="france"> France entière
      </label>
      <label :class="{ selected: zone.mode === 'address' }">
        <input v-model="zone.mode" type="radio" value="address"> Adresse + rayon
      </label>
      <label :class="{ selected: zone.mode === 'coordinates' }">
        <input v-model="zone.mode" type="radio" value="coordinates"> Coordonnées + rayon
      </label>
      <label :class="{ selected: zone.mode === 'departments' }">
        <input v-model="zone.mode" type="radio" value="departments"> Départements
      </label>
    </div>

    <p v-if="zone.mode === 'france'" class="field-help">
      La recherche couvre les 96 départements métropolitains, Corse comprise, sans les départements d’outre-mer.
    </p>

    <div v-else-if="zone.mode === 'address'">
      <div class="form-grid">
        <label class="wide-field address-field">
          Adresse / lieu
          <div class="address-picker">
            <input
              v-model="zone.address"
              autocomplete="off"
              maxlength="255"
              placeholder="Commencez à saisir une adresse ou un lieu"
              role="combobox"
              :aria-expanded="pickerOpen"
              @focus="pickerOpen = true; search()"
              @blur="pickerOpen = false"
            >
            <div v-if="pickerOpen && (loading || suggestions.length)" class="address-options" role="listbox">
              <p v-if="loading" class="muted">Recherche…</p>
              <button
                v-for="suggestion in suggestions"
                v-else
                :key="`${suggestion.label}-${suggestion.latitude}-${suggestion.longitude}`"
                type="button"
                @mousedown.prevent="choose(suggestion)"
              >
                <strong>{{ suggestion.label }}</strong>
                <small v-if="suggestion.kind">{{ suggestion.kind }}</small>
              </button>
            </div>
          </div>
        </label>
        <label>Rayon (km)<input v-model.number="zone.radiusKm" type="number" min="0.1" max="200" step="0.1"></label>
      </div>
      <p v-if="error" class="error field-help">{{ error }}</p>
      <p v-else-if="zone.addressConfirmed" class="success field-help">
        Coordonnées calculées : {{ zone.latitude.toFixed(6) }}, {{ zone.longitude.toFixed(6) }}.
      </p>
      <p v-else class="field-help">Sélectionnez une adresse proposée afin de valider ses coordonnées.</p>
    </div>

    <div v-else-if="zone.mode === 'coordinates'" class="form-grid">
      <label>Latitude<input v-model.number="zone.latitude" type="number" min="-90" max="90" step="any"></label>
      <label>Longitude<input v-model.number="zone.longitude" type="number" min="-180" max="180" step="any"></label>
      <label>Rayon (km)<input v-model.number="zone.radiusKm" type="number" min="0.1" max="200" step="0.1"></label>
    </div>

    <DepartmentPicker
      v-else
      v-model="zone.departmentCodes"
      :areas="areas"
      :loading="loadingAreas"
    />
  </fieldset>
</template>
