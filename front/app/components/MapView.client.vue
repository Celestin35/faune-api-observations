<script setup lang="ts">
import maplibregl, { type GeoJSONSource, type Map } from 'maplibre-gl'
import type { Point } from 'geojson'

type Observation = { id:number, latitude:number, longitude:number, observed_at:string|null, validation_status:string|null,
  taxon?:{scientific_name:string, vernacular_name?:string}, sources:Array<{source:string, source_url?:string}> }
const props = defineProps<{ observations: Observation[] }>()
const element = ref<HTMLDivElement>()
let map: Map | undefined
const geojson = computed(() => ({ type: 'FeatureCollection' as const, features: props.observations.filter(o => o.latitude != null && o.longitude != null).map(o => ({
  type: 'Feature' as const, geometry: { type: 'Point' as const, coordinates: [Number(o.longitude), Number(o.latitude)] },
  properties: { id:o.id, name:o.taxon?.vernacular_name || o.taxon?.scientific_name || 'Observation', scientific:o.taxon?.scientific_name || '',
    date:o.observed_at ? new Date(o.observed_at).toLocaleDateString('fr-FR') : 'Date inconnue', sources:o.sources.map(s => s.source).join(', ') }
})) }))

onMounted(() => {
  if (!element.value) return
  map = new maplibregl.Map({ container: element.value, center: [2.2, 46.4], zoom: 5,
    style: { version: 8, sources: { osm: { type: 'raster', tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'], tileSize: 256,
      attribution: '© OpenStreetMap contributors' } }, layers: [{ id:'osm', type:'raster', source:'osm' }] } })
  map.addControl(new maplibregl.NavigationControl(), 'top-right')
  map.on('load', () => {
    map!.addSource('observations', { type:'geojson', data:geojson.value, cluster:true, clusterRadius:45, clusterMaxZoom:13 })
    map!.addLayer({ id:'clusters', type:'circle', source:'observations', filter:['has','point_count'], paint:{'circle-color':'#146b51','circle-radius':['step',['get','point_count'],18,25,25,100,32],'circle-stroke-color':'#fff','circle-stroke-width':2} })
    map!.addLayer({ id:'cluster-count', type:'symbol', source:'observations', filter:['has','point_count'], layout:{'text-field':['get','point_count_abbreviated'],'text-size':12}, paint:{'text-color':'#fff'} })
    map!.addLayer({ id:'points', type:'circle', source:'observations', filter:['!',['has','point_count']], paint:{'circle-color':'#e46e3b','circle-radius':7,'circle-stroke-color':'#fff','circle-stroke-width':2} })
    map!.addLayer({ id:'dates', type:'symbol', source:'observations', minzoom:12, filter:['!',['has','point_count']], layout:{'text-field':['get','date'],'text-offset':[0,1.2],'text-size':11}, paint:{'text-color':'#17211b','text-halo-color':'#fff','text-halo-width':2} })
    map!.on('click', 'clusters', async e => { const f = map!.queryRenderedFeatures(e.point,{layers:['clusters']})[0]; if(!f)return; const id=Number(f.properties?.cluster_id); const zoom=await (map!.getSource('observations') as GeoJSONSource).getClusterExpansionZoom(id); map!.easeTo({center:(f.geometry as Point).coordinates as [number,number],zoom}) })
    map!.on('click', 'points', e => { const f=e.features?.[0]; if(!f) return; const p=f.properties as Record<string,string|undefined>; const box=document.createElement('div'); const title=document.createElement('strong'); title.textContent=p.name??'Observation'; box.append(title, document.createElement('br'), p.scientific??'', document.createElement('br'), p.date??'', document.createElement('br'), `Sources : ${p.sources??''}`); new maplibregl.Popup().setLngLat((f.geometry as Point).coordinates as [number,number]).setDOMContent(box).addTo(map!) })
  })
})
watch(geojson, value => (map?.getSource('observations') as GeoJSONSource | undefined)?.setData(value))
onBeforeUnmount(() => map?.remove())
</script>
<template><div ref="element" class="map" /></template>
