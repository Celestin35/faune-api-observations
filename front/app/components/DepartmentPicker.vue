<script setup lang="ts">
import type { GeographicArea } from '~/types/observation-query'

const selected = defineModel<string[]>({ required: true })
const props = defineProps<{ areas: GeographicArea[]; loading?: boolean }>()
const query = ref('')
const open = ref(false)

function searchKey(value: string): string {
  return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('fr')
}

const filtered = computed(() => {
  const value = searchKey(query.value.trim())
  return props.areas.filter(area => area.type === 'department'
    && (value === '' || searchKey(`${area.name} ${area.code}`).includes(value)))
})
const selectedAreas = computed(() => selected.value
  .map(code => props.areas.find(area => area.code === code))
  .filter((area): area is GeographicArea => Boolean(area)))

function toggle(code: string) {
  selected.value = selected.value.includes(code)
    ? selected.value.filter(selectedCode => selectedCode !== code)
    : [...selected.value, code]
}
</script>

<template>
  <div class="department-field">
    <label>
      Départements
      <div class="department-picker">
        <input
          v-model="query"
          role="combobox"
          aria-label="Rechercher un département"
          :aria-expanded="open"
          autocomplete="off"
          placeholder="Nom ou numéro, par exemple Côtes-d’Armor ou 22"
          @focus="open = true"
          @blur="open = false"
          @keydown.esc="open = false"
        >
        <div v-if="open" class="department-options" role="listbox">
          <p v-if="loading" class="muted">Chargement…</p>
          <p v-else-if="filtered.length === 0" class="muted">Aucun département correspondant.</p>
          <button
            v-for="area in filtered"
            v-else
            :key="area.code"
            type="button"
            class="department-option"
            :class="{ selected: selected.includes(area.code) }"
            role="option"
            :aria-selected="selected.includes(area.code)"
            @mousedown.prevent="toggle(area.code)"
          >
            <span>{{ area.name }} — {{ area.code }}</span>
            <span><small>{{ area.region_name }}</small> {{ selected.includes(area.code) ? '✓' : '' }}</span>
          </button>
        </div>
      </div>
    </label>
    <div v-if="selectedAreas.length" class="selected-departments">
      <button v-for="area in selectedAreas" :key="area.code" type="button" @click="toggle(area.code)">
        {{ area.name }} — {{ area.code }} <span aria-hidden="true">×</span>
      </button>
    </div>
    <p class="field-help">{{ selected.length }} département(s) sélectionné(s).</p>
  </div>
</template>
