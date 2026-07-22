<script setup lang="ts">
type ZoneType = 'radius' | 'departments'

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
}

interface GeographicArea {
  code: string
  name: string
  type: string
  region_name: string
  is_overseas: boolean
  faune_portal: 'faune_france' | 'faune_antilles' | 'faune_guyane' | 'faune_reunion' | 'faune_mayotte'
}

const api = useApi()
const name = ref('Tichodrome — Rennes')
const selectedTaxon = ref<TaxonResult | null>(null)
const taxonId = computed(() => selectedTaxon.value?.id)
const frequency = ref(30)
const windowMinutes = ref(10080)
const zoneType = ref<ZoneType>('radius')
const address = ref('Rennes, France')
const latitude = ref(48.1173)
const longitude = ref(-1.6778)
const radius = ref(30)
const sources = ref(['gbif', 'inaturalist'])
const areas = ref<GeographicArea[]>([])
const selectedDepartments = ref<string[]>([])
const departmentQuery = ref('')
const departmentPickerOpen = ref(false)
const loadingAreas = ref(false)
const saving = ref(false)
const message = ref('')
const error = ref('')

function searchKey(value: string): string {
  return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('fr')
}

const filteredDepartments = computed(() => {
  const query = searchKey(departmentQuery.value.trim())

  return areas.value.filter((area) => {
    if (area.type !== 'department') return false
    return query === '' || searchKey(`${area.name} ${area.code}`).includes(query)
  })
})

const selectedAreas = computed(() => selectedDepartments.value
  .map(code => areas.value.find(area => area.code === code))
  .filter((area): area is GeographicArea => Boolean(area)))

const selectedFaunePortals = computed(() => [...new Set(selectedAreas.value.map(area => area.faune_portal))])
const faunePortalNames: Record<GeographicArea['faune_portal'], string> = {
  faune_france: 'Faune-France',
  faune_antilles: 'Faune-Antilles',
  faune_guyane: 'Faune-Guyane',
  faune_reunion: 'Faune-Réunion',
  faune_mayotte: 'Faune-Mayotte',
}

const fauneFranceUnavailableReason = computed(() => {
  if (zoneType.value !== 'departments') {
    return 'Faune-France nécessite une zone constituée de départements métropolitains.'
  }
  if (selectedDepartments.value.length === 0) {
    return 'Sélectionnez au moins un département pour activer Faune-France.'
  }
  if (selectedFaunePortals.value.length > 1) {
    return 'La sélection couvre plusieurs portails Faune. Les recherches multi-portails ne sont pas encore disponibles.'
  }
  const portal = selectedFaunePortals.value[0]
  if (portal && portal !== 'faune_france') {
    return `Le connecteur ${faunePortalNames[portal]} n’est pas encore disponible. La surveillance reste possible avec GBIF et iNaturalist.`
  }
  if (!selectedTaxon.value?.sourceAvailability.fauneFrance) {
    return 'Le taxon sélectionné ne dispose pas encore d’un identifiant Faune-France.'
  }
  if (selectedTaxon.value.rank.code !== 'species') {
    return 'Le bot Faune-France accepte actuellement uniquement les taxons de rang espèce.'
  }

  return ''
})

const fauneFranceAvailable = computed(() => fauneFranceUnavailableReason.value === '')

watch(fauneFranceAvailable, (available) => {
  if (!available && sources.value.includes('faune-france')) {
    sources.value = sources.value.filter(source => source !== 'faune-france')
  }
})

async function loadAreas() {
  loadingAreas.value = true
  try {
    const response = await api<{ data: GeographicArea[] }>('/geographic-areas')
    areas.value = response.data
  } catch (exception: any) {
    error.value = exception.data?.message || exception.message || 'Impossible de charger les départements.'
  } finally {
    loadingAreas.value = false
  }
}

function toggleDepartment(code: string) {
  selectedDepartments.value = selectedDepartments.value.includes(code)
    ? selectedDepartments.value.filter(selected => selected !== code)
    : [...selectedDepartments.value, code]
}

function formatApiError(exception: any): string {
  const validationErrors = exception.data?.errors
  if (validationErrors && typeof validationErrors === 'object') {
    return Object.values(validationErrors).flat().join('\n')
  }

  return exception.data?.message || exception.message || 'La surveillance n’a pas pu être créée.'
}

async function save() {
  message.value = ''
  error.value = ''

  if (!taxonId.value) {
    error.value = 'Cherchez le taxon puis sélectionnez-le dans la liste.'
    return
  }
  if (sources.value.length === 0) {
    error.value = 'Sélectionnez au moins une source.'
    return
  }
  if (zoneType.value === 'departments' && selectedDepartments.value.length === 0) {
    error.value = 'Sélectionnez au moins un département.'
    return
  }

  const zone = zoneType.value === 'radius'
    ? {
        type: 'radius',
        address: address.value.trim(),
        latitude: latitude.value,
        longitude: longitude.value,
        radius_km: radius.value,
      }
    : {
        type: 'departments',
        department_codes: selectedDepartments.value,
      }

  saving.value = true
  try {
    const today = new Date().toISOString().slice(0, 10)
    const response = await api<{ data: { id: number } }>('/monitoring', {
      method: 'POST',
      body: {
        name: name.value,
        taxon_id: taxonId.value,
        taxon_scope: selectedTaxon.value!.defaultScope,
        date_from: today,
        date_to: today,
        zone,
        sources: sources.value,
        frequency_minutes: frequency.value,
        window_minutes: windowMinutes.value,
        is_active: true,
      },
    })
    message.value = `Surveillance #${response.data.id} créée.`
  } catch (exception: any) {
    error.value = formatApiError(exception)
  } finally {
    saving.value = false
  }
}

await loadAreas()
</script>

<template>
  <section>
    <h1>Nouvelle surveillance</h1>

    <form class="card monitoring-form" @submit.prevent="save">
      <div class="form-grid">
        <label>
          Nom
          <input v-model="name" required maxlength="255">
        </label>

        <label class="taxon-field">
          Taxon
          <TaxonPicker v-model="selectedTaxon" required></TaxonPicker>
        </label>

        <label>
          Fréquence (min)
          <input v-model.number="frequency" type="number" min="5" required>
        </label>

        <label>
          Fenêtre glissante (min)
          <input v-model.number="windowMinutes" type="number" min="5" required>
        </label>
      </div>

      <fieldset class="form-section">
        <legend>Zone surveillée</legend>
        <div class="zone-type-switch">
          <label :class="{ selected: zoneType === 'radius' }">
            <input v-model="zoneType" type="radio" value="radius">
            Autour d’une adresse
          </label>
          <label :class="{ selected: zoneType === 'departments' }">
            <input v-model="zoneType" type="radio" value="departments">
            Par départements
          </label>
        </div>

        <div v-if="zoneType === 'radius'">
          <div class="form-grid">
            <label class="wide-field">
              Adresse / lieu
              <input v-model="address" maxlength="255" placeholder="Rennes, France">
            </label>
            <label>
              Latitude
              <input v-model.number="latitude" type="number" min="-90" max="90" step="any" required>
            </label>
            <label>
              Longitude
              <input v-model.number="longitude" type="number" min="-180" max="180" step="any" required>
            </label>
            <label>
              Rayon (km)
              <input v-model.number="radius" type="number" min="0.1" max="200" step="0.1" required>
            </label>
          </div>
          <p class="field-help">L’adresse est un libellé. La recherche utilise les coordonnées et le rayon ci-dessus.</p>
        </div>

        <div v-else class="department-field">
          <label>
            Départements
            <div class="department-picker">
              <input
                v-model="departmentQuery"
                role="combobox"
                aria-label="Rechercher un département"
                :aria-expanded="departmentPickerOpen"
                autocomplete="off"
                placeholder="Écrire un nom ou un numéro, par exemple 22"
                @focus="departmentPickerOpen = true"
                @blur="departmentPickerOpen = false"
                @keydown.esc="departmentPickerOpen = false"
              >
              <div v-if="departmentPickerOpen" class="department-options" role="listbox">
                <p v-if="loadingAreas" class="muted">Chargement…</p>
                <p v-else-if="filteredDepartments.length === 0" class="muted">Aucun département configuré ne correspond.</p>
                <button
                  v-for="area in filteredDepartments"
                  v-else
                  :key="area.code"
                  type="button"
                  class="department-option"
                  :class="{ selected: selectedDepartments.includes(area.code) }"
                  role="option"
                  :aria-selected="selectedDepartments.includes(area.code)"
                  @mousedown.prevent="toggleDepartment(area.code)"
                >
                  <span>{{ area.name }} — {{ area.code }}</span>
                  <span><small>{{ area.region_name }}</small> {{ selectedDepartments.includes(area.code) ? '✓' : '' }}</span>
                </button>
              </div>
            </div>
          </label>

          <div v-if="selectedAreas.length" class="selected-departments" aria-label="Départements sélectionnés">
            <button v-for="area in selectedAreas" :key="area.code" type="button" @click="toggleDepartment(area.code)">
              {{ area.name }} — {{ area.code }} <span aria-hidden="true">×</span>
            </button>
          </div>
          <p class="field-help">{{ selectedDepartments.length }} département(s) sélectionné(s). Cliquez à nouveau sur un département pour le retirer.</p>
        </div>
      </fieldset>

      <fieldset class="form-section">
        <legend>Sources</legend>
        <div class="inline-checks">
          <label><input v-model="sources" type="checkbox" value="gbif"> GBIF</label>
          <label><input v-model="sources" type="checkbox" value="inaturalist"> iNaturalist</label>
          <label :class="{ 'source-disabled': !fauneFranceAvailable }">
            <input v-model="sources" type="checkbox" value="faune-france" :disabled="!fauneFranceAvailable">
            Faune-France
          </label>
        </div>
        <p v-if="fauneFranceUnavailableReason" class="field-help">{{ fauneFranceUnavailableReason }}</p>
        <p v-else class="field-help success">Faune-France utilisera uniquement le masque des départements métropolitains sélectionnés.</p>
      </fieldset>

      <button type="submit" :disabled="saving">{{ saving ? 'Création…' : 'Créer' }}</button>
      <p v-if="message" class="success">{{ message }}</p>
      <p v-if="error" class="error">{{ error }}</p>
    </form>
  </section>
</template>
