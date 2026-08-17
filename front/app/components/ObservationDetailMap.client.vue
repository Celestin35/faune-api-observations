<script setup lang="ts">
import maplibregl, { type Map } from 'maplibre-gl'
import { createMapStyle, setMapBackground, type MapBackground } from '~/utils/map-backgrounds'

type Location = {
  status: 'exact' | 'approximate' | 'source_masked' | 'unavailable' | null
  latitude: number | null
  longitude: number | null
  uncertaintyM: number | null
}

const props = defineProps<{ location: Location }>()
const element = ref<HTMLDivElement>()
const background = ref<MapBackground>('standard')
let map: Map | undefined

const hasPublicPoint = computed(() =>
  Number.isFinite(Number(props.location.latitude))
  && Number.isFinite(Number(props.location.longitude)),
)

const message = computed(() => {
  if (!hasPublicPoint.value || props.location.status === 'unavailable') return 'Localisation indisponible'
  if (props.location.status === 'source_masked') return 'La source a masqué la position précise. Seule la position publique autorisée est affichée.'
  if (props.location.status === 'approximate') return 'Position approximative.'
  return ''
})

function uncertaintyCircle(longitude: number, latitude: number, radiusM: number) {
  const earthRadiusM = 6_371_008.8
  const angularDistance = radiusM / earthRadiusM
  const latitudeRad = latitude * Math.PI / 180
  const longitudeRad = longitude * Math.PI / 180
  const coordinates: [number, number][] = []

  for (let index = 0; index <= 64; index += 1) {
    const bearing = index / 64 * 2 * Math.PI
    const circleLatitude = Math.asin(
      Math.sin(latitudeRad) * Math.cos(angularDistance)
      + Math.cos(latitudeRad) * Math.sin(angularDistance) * Math.cos(bearing),
    )
    const circleLongitude = longitudeRad + Math.atan2(
      Math.sin(bearing) * Math.sin(angularDistance) * Math.cos(latitudeRad),
      Math.cos(angularDistance) - Math.sin(latitudeRad) * Math.sin(circleLatitude),
    )
    coordinates.push([circleLongitude * 180 / Math.PI, circleLatitude * 180 / Math.PI])
  }

  return {
    type: 'Feature' as const,
    properties: {},
    geometry: { type: 'Polygon' as const, coordinates: [coordinates] },
  }
}

function initializeMap() {
  if (map || !element.value || !hasPublicPoint.value || props.location.status === 'unavailable') return
  const center: [number, number] = [Number(props.location.longitude), Number(props.location.latitude)]
  map = new maplibregl.Map({
    container: element.value,
    center,
    zoom: props.location.uncertaintyM && props.location.uncertaintyM > 10_000 ? 8 : 12,
    style: createMapStyle(),
  })
  map.addControl(new maplibregl.NavigationControl(), 'top-right')
  map.on('load', () => {
    if (props.location.status === 'approximate' && props.location.uncertaintyM && props.location.uncertaintyM > 0) {
      map!.addSource('uncertainty', {
        type: 'geojson',
        data: uncertaintyCircle(center[0], center[1], props.location.uncertaintyM),
      })
      map!.addLayer({
        id: 'uncertainty-fill',
        type: 'fill',
        source: 'uncertainty',
        paint: { 'fill-color': '#146b51', 'fill-opacity': 0.14 },
      })
      map!.addLayer({
        id: 'uncertainty-outline',
        type: 'line',
        source: 'uncertainty',
        paint: { 'line-color': '#146b51', 'line-width': 2 },
      })
      const circle = uncertaintyCircle(center[0], center[1], props.location.uncertaintyM)
      const bounds = (circle.geometry.coordinates[0] ?? []).reduce(
        (current, coordinate) => current.extend(coordinate),
        new maplibregl.LngLatBounds(center, center),
      )
      map!.fitBounds(bounds, { padding: 45, maxZoom: 13 })
    }

    new maplibregl.Marker({ color: props.location.status === 'source_masked' ? '#9a6200' : '#e46e3b' })
      .setLngLat(center)
      .addTo(map!)
  })
}

onMounted(async () => {
  // A `.client.vue` component can be mounted while Nuxt is still replacing its
  // server placeholder. Waiting for the next render ensures the map container
  // ref exists before MapLibre is initialized.
  await nextTick()
  initializeMap()
})
watch(element, initializeMap, { flush: 'post' })
watch(background, value => { if (map) setMapBackground(map, value) })

onBeforeUnmount(() => map?.remove())
</script>

<template>
  <div>
    <p v-if="message" :class="hasPublicPoint ? 'location-notice' : 'muted'">{{ message }}</p>
    <div v-if="hasPublicPoint && location.status !== 'unavailable'" class="map-shell">
      <div ref="element" class="observation-detail-map" />
      <MapBackgroundPicker v-model="background" />
    </div>
  </div>
</template>
