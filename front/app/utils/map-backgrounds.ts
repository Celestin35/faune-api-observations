import type { Map, StyleSpecification } from 'maplibre-gl'

export type MapBackground = 'standard' | 'relief'

export function createMapStyle(): StyleSpecification {
  return {
    version: 8,
    sources: {
      'base-standard': {
        type: 'raster',
        tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
        tileSize: 256,
        attribution: '© OpenStreetMap contributors',
      },
      'base-relief': {
        type: 'raster',
        tiles: [
          'https://a.tile.opentopomap.org/{z}/{x}/{y}.png',
          'https://b.tile.opentopomap.org/{z}/{x}/{y}.png',
          'https://c.tile.opentopomap.org/{z}/{x}/{y}.png',
        ],
        tileSize: 256,
        attribution: '© OpenStreetMap contributors, SRTM | © OpenTopoMap (CC-BY-SA)',
      },
    },
    layers: [
      { id: 'base-standard', type: 'raster', source: 'base-standard' },
      { id: 'base-relief', type: 'raster', source: 'base-relief', layout: { visibility: 'none' } },
    ],
  }
}

export function setMapBackground(map: Map, background: MapBackground): void {
  if (!map.getLayer('base-standard') || !map.getLayer('base-relief')) return
  map.setLayoutProperty('base-standard', 'visibility', background === 'standard' ? 'visible' : 'none')
  map.setLayoutProperty('base-relief', 'visibility', background === 'relief' ? 'visible' : 'none')
}
