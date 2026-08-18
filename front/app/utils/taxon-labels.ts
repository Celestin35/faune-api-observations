interface RankedTaxon {
  rank: { code?: string | null; label?: string | null }
}

const fallbackRankLabels: Record<string, string> = {
  kingdom: 'Règne',
  phylum: 'Embranchement',
  class: 'Classe',
  order: 'Ordre',
  family: 'Famille',
  genus: 'Genre',
  species: 'Espèce',
  subspecies: 'Sous-espèce',
}

export function taxonRankLabel(taxon: RankedTaxon): string {
  const label = taxon.rank.label?.trim()
  if (label) return label

  const code = taxon.rank.code?.toLowerCase() || ''
  return fallbackRankLabels[code] || 'Groupe taxonomique'
}

export function taxonScopeLabel(taxon: RankedTaxon, scope: 'exact' | 'subtree'): string {
  const rank = taxonRankLabel(taxon)
  if (scope === 'exact') return `${rank} uniquement`
  if (taxon.rank.code?.toLowerCase() === 'species') return `${rank} et sous-espèces`
  return `${rank} et descendants`
}
