export interface TaxonResult {
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

export interface TaxonSelection {
  taxon: TaxonResult
  scope: 'exact' | 'subtree'
}

export interface GeographicArea {
  code: string
  name: string
  type: string
  region_name: string
  is_overseas: boolean
  faune_portal: 'faune_france' | 'faune_antilles' | 'faune_guyane' | 'faune_reunion' | 'faune_mayotte'
}

export interface QueryZone {
  mode: 'france' | 'address' | 'coordinates' | 'departments'
  address: string
  addressConfirmed: boolean
  latitude: number
  longitude: number
  radiusKm: number
  departmentCodes: string[]
}

export interface DateRange {
  dateFrom: string
  dateTo: string
}
