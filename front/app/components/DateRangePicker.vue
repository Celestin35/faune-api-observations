<script setup lang="ts">
import type { DateRange } from '~/types/observation-query'

const dates = defineModel<DateRange>({ required: true })

type DatePreset = 'last30' | 'thisYear' | 'currentSeason' | 'previousSeason' | 'lastWinter' | 'lastSpring' | 'lastSummer' | 'lastAutumn' | 'all' | 'custom'
type Season = 'winter' | 'spring' | 'summer' | 'autumn'

const preset = ref<DatePreset>('last30')

function calendarDate(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function today(): Date {
  const now = new Date()
  return new Date(now.getFullYear(), now.getMonth(), now.getDate())
}

function range(from: Date, to: Date): DateRange {
  return { dateFrom: calendarDate(from), dateTo: calendarDate(to) }
}

function seasonAt(date: Date): DateRange {
  const year = date.getFullYear()
  const month = date.getMonth()
  if (month >= 2 && month <= 4) return range(new Date(year, 2, 1), new Date(year, 5, 0))
  if (month >= 5 && month <= 7) return range(new Date(year, 5, 1), new Date(year, 8, 0))
  if (month >= 8 && month <= 10) return range(new Date(year, 8, 1), new Date(year, 11, 0))
  return month === 11
    ? range(new Date(year, 11, 1), new Date(year + 1, 2, 0))
    : range(new Date(year - 1, 11, 1), new Date(year, 2, 0))
}

function lastCompletedSeason(season: Season, now: Date): DateRange {
  const definitions: Record<Season, [number, number]> = {
    winter: [11, 2],
    spring: [2, 5],
    summer: [5, 8],
    autumn: [8, 11],
  }
  const [startMonth, endMonth] = definitions[season]
  for (let startYear = now.getFullYear(); startYear >= now.getFullYear() - 2; startYear--) {
    const endYear = season === 'winter' ? startYear + 1 : startYear
    const end = new Date(endYear, endMonth, 0)
    if (end < now) return range(new Date(startYear, startMonth, 1), end)
  }
  return range(new Date(now.getFullYear() - 1, startMonth, 1), now)
}

function applyPreset() {
  if (preset.value === 'custom') return
  const now = today()
  let selected: DateRange
  if (preset.value === 'last30') {
    const from = new Date(now)
    from.setDate(from.getDate() - 30)
    selected = range(from, now)
  } else if (preset.value === 'thisYear') {
    selected = range(new Date(now.getFullYear(), 0, 1), now)
  } else if (preset.value === 'currentSeason') {
    const current = seasonAt(now)
    selected = { dateFrom: current.dateFrom, dateTo: calendarDate(now) }
  } else if (preset.value === 'previousSeason') {
    const current = seasonAt(now)
    const dayBefore = new Date(`${current.dateFrom}T12:00:00`)
    dayBefore.setDate(dayBefore.getDate() - 1)
    selected = seasonAt(dayBefore)
  } else if (preset.value === 'all') {
    selected = { dateFrom: '1800-01-01', dateTo: calendarDate(now) }
  } else {
    selected = lastCompletedSeason(preset.value.replace('last', '').toLowerCase() as Season, now)
  }
  dates.value.dateFrom = selected.dateFrom
  dates.value.dateTo = selected.dateTo
}
</script>

<template>
  <label>
    Période
    <select v-model="preset" @change="applyPreset">
      <option value="last30">30 derniers jours</option>
      <option value="thisYear">Cette année</option>
      <option value="currentSeason">Saison en cours</option>
      <option value="previousSeason">Saison précédente</option>
      <option value="lastWinter">Hiver dernier</option>
      <option value="lastSpring">Printemps dernier</option>
      <option value="lastSummer">Été dernier</option>
      <option value="lastAutumn">Automne dernier</option>
      <option value="all">Depuis le début</option>
      <option value="custom">Dates personnalisées</option>
    </select>
  </label>
  <template v-if="preset === 'custom'">
    <label>
      Du
      <input v-model="dates.dateFrom" type="date" required>
    </label>
    <label>
      Au
      <input v-model="dates.dateTo" type="date" required>
    </label>
  </template>
  <p v-else class="period-summary field-help">
    Du {{ dates.dateFrom }} au {{ dates.dateTo }}
  </p>
</template>
