<script setup lang="ts">
const api = useApi()
const today = new Date().toISOString().slice(0,10)
const monthAgo = new Date(Date.now()-30*86400000).toISOString().slice(0,10)
const selectedTaxon=ref<any|null>(null)
const taxonId=computed(()=>selectedTaxon.value?.id as number|undefined)
const dateFrom=ref(monthAgo), dateTo=ref(today), zoneType=ref<'radius'|'departments'>('radius')
const lat=ref(48.1173), lng=ref(-1.6778), radius=ref(30), selectedDepartments=ref<string[]>([])
const sources=ref(['gbif','inaturalist']), estimate=ref<any>(), busy=ref(false), message=ref(''), error=ref('')
const { data: areas } = await useAsyncData('areas', () => api<any>('/geographic-areas'))
watch(selectedTaxon,()=>{estimate.value=undefined})
function body(){return {taxon_id:taxonId.value,taxon_scope:selectedTaxon.value?.defaultScope,date_from:dateFrom.value,date_to:dateTo.value,sources:sources.value,zone:zoneType.value==='radius'?{type:'radius',latitude:lat.value,longitude:lng.value,radius_km:radius.value}:{type:'departments',department_codes:selectedDepartments.value}}}
async function runEstimate(){busy.value=true;error.value='';try{estimate.value=await api('/searches/estimate',{method:'POST',body:body()})}catch(e:any){error.value=e.data?.message||e.message}finally{busy.value=false}}
async function importNow(){if(!estimate.value||!confirm(`Confirmer l’import (plafond ${estimate.value.import_limit_per_source} par source) ?`))return;busy.value=true;try{const result=await api<any>('/imports',{method:'POST',body:{...body(),confirmed:true,estimates:estimate.value.external}});message.value=`${result.data.length} import(s) placé(s) dans la queue.`}catch(e:any){error.value=e.data?.message||e.message}finally{busy.value=false}}
</script>
<template><section><h1>Explorer les observations</h1><div class="card">
  <div class="form-grid"><label>Taxon ou tous les animaux<TaxonPicker v-model="selectedTaxon" placeholder="Vide = toutes les observations"></TaxonPicker></label>
    <label>Du<input v-model="dateFrom" type="date"></label><label>Au<input v-model="dateTo" type="date"></label>
    <label>Type de zone<select v-model="zoneType"><option value="radius">Point + rayon</option><option value="departments">Départements</option></select></label></div>
  <div v-if="zoneType==='radius'" class="form-grid"><label>Latitude<input v-model.number="lat" type="number" step=".0001"></label><label>Longitude<input v-model.number="lng" type="number" step=".0001"></label><label>Rayon (km)<input v-model.number="radius" type="number" min="1" max="200"></label></div>
  <div v-else class="grid"><label v-for="area in areas?.data" :key="area.code"><span><input v-model="selectedDepartments" type="checkbox" :value="area.code"> {{ area.code }} — {{ area.name }}</span></label></div>
  <p><strong>Sources :</strong> <label style="display:inline"><span><input v-model="sources" type="checkbox" value="gbif"> GBIF</span></label> <label style="display:inline"><span><input v-model="sources" type="checkbox" value="inaturalist"> iNaturalist</span></label></p>
  <div class="actions"><button :disabled="busy" @click="runEstimate">Estimer</button><button class="secondary" :disabled="busy||!estimate" @click="importNow">Importer après confirmation</button></div><p class="error">{{ error }}</p><p class="success">{{ message }}</p></div>
  <div v-if="estimate" class="grid" style="margin-top:1rem"><article class="card"><div class="stat">{{ estimate.local.count }}</div>déjà en local<br><small>{{ estimate.local.covered_from || 'aucune couverture' }} → {{ estimate.local.covered_to || '—' }}</small></article><article v-for="(count,source) in estimate.external" :key="source" class="card"><div class="stat">{{ typeof count==='number'?count:'—' }}</div>{{ source }}<p v-if="typeof count!=='number'" class="error">{{ count.error }}</p></article><article class="card"><strong>Recouvrement estimé</strong><p>iNaturalist dans GBIF : {{ estimate.overlap.inaturalist_in_gbif ?? 'inconnu' }}</p><p>Manquantes : {{ estimate.overlap.estimated_inaturalist_missing_from_gbif ?? 'inconnu' }}</p></article></div>
  <p v-if="estimate" class="muted">{{ estimate.warning }} Couverture complète : {{ estimate.coverage_complete ? 'oui' : 'non' }}.</p>
</section></template>
