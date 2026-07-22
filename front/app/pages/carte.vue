<script setup lang="ts">
const api=useApi(), source=ref(''), status=ref(''), dateFrom=ref(''), dateTo=ref('')
const observations=ref<any[]>([]), error=ref('')
async function load(){try{const result=await api<any>('/observations',{query:{source:source.value||undefined,validation_status:status.value||undefined,date_from:dateFrom.value||undefined,date_to:dateTo.value||undefined,limit:1000}});observations.value=result.data}catch(e:any){error.value=e.message}}
await load()
</script>
<template><section><h1>Carte</h1><div class="card form-grid"><label>Source<select v-model="source"><option value="">Toutes</option><option>gbif</option><option>inaturalist</option><option>faune-france</option></select></label><label>Validation<input v-model="status" placeholder="research"></label><label>Du<input v-model="dateFrom" type="date"></label><label>Au<input v-model="dateTo" type="date"></label><button @click="load">Filtrer</button></div><p class="muted">{{ observations.length }} points canoniques — les grappes se développent au zoom.</p><p class="error">{{ error }}</p><MapView :observations="observations" /></section></template>
