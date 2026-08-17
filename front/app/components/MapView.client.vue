<script setup lang="ts">
import maplibregl, { type GeoJSONSource, type Map as MapLibreMap } from 'maplibre-gl'
import type { Point } from 'geojson'
import { createMapStyle, setMapBackground, type MapBackground } from '~/utils/map-backgrounds'

type TaxonGroup = { key: string; label: string }
type Observation = {
  id: number
  latitude: number
  longitude: number
  observed_at: string | null
  validation_status: string | null
  taxonGroup?: TaxonGroup | null
  taxon?: {
    frenchName?: string | null
    scientificName?: string | null
    scientific_name?: string | null
    vernacular_name?: string | null
  }
  sources: Array<{ source: string; source_url?: string }>
}

const props = defineProps<{ observations: Observation[]; returnTo?: string }>()
const element = ref<HTMLDivElement>()
const background = ref<MapBackground>('standard')
let map: MapLibreMap | undefined

const knownGroupColors: Record<string, string> = {
  Aves: '#2563eb',
  Mammalia: '#d97706',
  Reptilia: '#16a34a',
  Amphibia: '#0d9488',
  Actinopterygii: '#0891b2',
  Elasmobranchii: '#0369a1',
  Insecta: '#7c3aed',
  Arachnida: '#dc2626',
  Malacostraca: '#ea580c',
  Gastropoda: '#9333ea',
  Bivalvia: '#db2777',
  Branchiopoda: '#ca8a04',
  Chilopoda: '#be123c',
  Diplopoda: '#854d0e',
}
const fallbackColors = ['#4f46e5', '#059669', '#c2410c', '#a21caf', '#0f766e', '#be123c', '#0369a1', '#65a30d']

function groupKey(observation: Observation): string {
  return observation.taxonGroup?.key || 'other'
}

function groupLabel(observation: Observation): string {
  return observation.taxonGroup?.label || 'Autres taxons'
}

function groupColor(key: string): string {
  if (knownGroupColors[key]) return knownGroupColors[key]
  let hash = 0
  for (const character of key) hash = ((hash << 5) - hash + character.charCodeAt(0)) | 0
  return fallbackColors[Math.abs(hash) % fallbackColors.length] || '#475569'
}

const legend = computed(() => {
  const groups = new Map<string, { key: string; label: string; color: string; count: number }>()
  for (const observation of props.observations) {
    const key = groupKey(observation)
    const current = groups.get(key)
    if (current) current.count += 1
    else groups.set(key, { key, label: groupLabel(observation), color: groupColor(key), count: 1 })
  }
  return [...groups.values()].sort((left, right) => right.count - left.count || left.label.localeCompare(right.label, 'fr'))
})

const geojson = computed(() => ({
  type: 'FeatureCollection' as const,
  features: props.observations
    .filter(observation => observation.latitude != null && observation.longitude != null)
    .map(observation => ({
      type: 'Feature' as const,
      geometry: {
        type: 'Point' as const,
        coordinates: [Number(observation.longitude), Number(observation.latitude)],
      },
      properties: {
        id: observation.id,
        name: observation.taxon?.frenchName || observation.taxon?.vernacular_name || 'Nom français non renseigné',
        scientific: observation.taxon?.scientificName || observation.taxon?.scientific_name || '',
        date: observation.observed_at ? new Date(observation.observed_at).toLocaleDateString('fr-FR') : 'Date inconnue',
        sources: observation.sources.map(source => source.source).join(', '),
        group: groupLabel(observation),
        groupColor: groupColor(groupKey(observation)),
      },
    })),
}))

onMounted(() => {
  if (!element.value) return
  map = new maplibregl.Map({
    container: element.value,
    center: [2.2, 46.4],
    zoom: 5,
    style: createMapStyle(),
  })
  map.addControl(new maplibregl.NavigationControl(), 'top-right')
  map.on('load', () => {
    map!.addSource('observations', {
      type: 'geojson',
      data: geojson.value,
      cluster: true,
      clusterRadius: 45,
      clusterMaxZoom: 13,
    })
    map!.addLayer({
      id: 'clusters',
      type: 'circle',
      source: 'observations',
      filter: ['has', 'point_count'],
      paint: {
        'circle-color': '#173f35',
        'circle-radius': ['step', ['get', 'point_count'], 18, 25, 25, 100, 32],
        'circle-stroke-color': '#fff',
        'circle-stroke-width': 2,
      },
    })
    map!.addLayer({
      id: 'cluster-count',
      type: 'symbol',
      source: 'observations',
      filter: ['has', 'point_count'],
      layout: { 'text-field': ['get', 'point_count_abbreviated'], 'text-size': 12 },
      paint: { 'text-color': '#fff' },
    })
    map!.addLayer({
      id: 'points',
      type: 'circle',
      source: 'observations',
      filter: ['!', ['has', 'point_count']],
      paint: {
        'circle-color': ['get', 'groupColor'],
        'circle-radius': 7,
        'circle-stroke-color': '#fff',
        'circle-stroke-width': 2,
      },
    })
    map!.addLayer({
      id: 'dates',
      type: 'symbol',
      source: 'observations',
      minzoom: 12,
      filter: ['!', ['has', 'point_count']],
      layout: { 'text-field': ['get', 'date'], 'text-offset': [0, 1.2], 'text-size': 11 },
      paint: { 'text-color': '#17211b', 'text-halo-color': '#fff', 'text-halo-width': 2 },
    })
    map!.on('click', 'clusters', async (event) => {
      const feature = map!.queryRenderedFeatures(event.point, { layers: ['clusters'] })[0]
      if (!feature) return
      const id = Number(feature.properties?.cluster_id)
      const zoom = await (map!.getSource('observations') as GeoJSONSource).getClusterExpansionZoom(id)
      map!.easeTo({ center: (feature.geometry as Point).coordinates as [number, number], zoom })
    })
    map!.on('click', 'points', (event) => {
      const feature = event.features?.[0]
      if (!feature) return
      const properties = feature.properties as Record<string, string | undefined>
      const box = document.createElement('div')
      const title = document.createElement('strong')
      const detailLink = document.createElement('a')
      title.textContent = properties.name ?? 'Observation'
      const suffix = props.returnTo ? `?returnTo=${encodeURIComponent(props.returnTo)}` : ''
      detailLink.href = `/observations/${properties.id}${suffix}`
      detailLink.textContent = 'Voir le détail de l’observation'
      detailLink.className = 'map-popup-detail'
      box.append(
        title,
        document.createElement('br'),
        properties.scientific ?? '',
        document.createElement('br'),
        `Groupe : ${properties.group ?? 'Autres taxons'}`,
        document.createElement('br'),
        properties.date ?? '',
        document.createElement('br'),
        `Sources : ${properties.sources ?? ''}`,
        document.createElement('br'),
        detailLink,
      )
      new maplibregl.Popup()
        .setLngLat((feature.geometry as Point).coordinates as [number, number])
        .setDOMContent(box)
        .addTo(map!)
    })
    for (const layer of ['clusters', 'points']) {
      map!.on('mouseenter', layer, () => { map!.getCanvas().style.cursor = 'pointer' })
      map!.on('mouseleave', layer, () => { map!.getCanvas().style.cursor = '' })
    }
  })
})

watch(geojson, value => (map?.getSource('observations') as GeoJSONSource | undefined)?.setData(value))
watch(background, value => { if (map) setMapBackground(map, value) })
onBeforeUnmount(() => map?.remove())
</script>

<template>
  <div class="map-shell">
    <div ref="element" class="map" />
    <div v-if="legend.length" class="taxon-map-legend" aria-label="Légende des groupes taxonomiques">
      <strong>Groupes</strong>
      <div class="taxon-map-legend-items">
        <span v-for="group in legend" :key="group.key">
          <i :style="{ backgroundColor: group.color }" />{{ group.label }} <small>{{ group.count }}</small>
        </span>
      </div>
    </div>
    <MapBackgroundPicker v-model="background" />
  </div>
</template>
