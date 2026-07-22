<script setup lang="ts">
const api=useApi(); const imports=ref<any[]>([]), error=ref('')
async function load(){try{imports.value=(await api<any>('/imports')).data}catch(e:any){error.value=e.message}}
await load(); let interval:number|undefined
onMounted(()=>interval=window.setInterval(load,3000)); onBeforeUnmount(()=>clearInterval(interval))
async function cancel(id:number){await api(`/imports/${id}/cancel`,{method:'PATCH'});await load()}
</script>
<template><section><h1>Imports</h1><p class="muted">Actualisation automatique toutes les trois secondes.</p><p class="error">{{ error }}</p><table><thead><tr><th>#</th><th>Source</th><th>Période</th><th>État</th><th>Progression</th><th>Résultat</th><th></th></tr></thead><tbody><tr v-for="item in imports" :key="item.id"><td>{{ item.id }}</td><td><span class="badge">{{ item.source }}</span></td><td>{{ item.date_from }}<br>{{ item.date_to }}</td><td :class="`status-${item.status}`">{{ item.status }}<small v-if="item.error_message" class="error"><br>{{ item.error_message }}</small></td><td>{{ item.processed_count }} / {{ item.estimated_count ?? '?' }} (plafond {{ item.limit }})</td><td>+{{ item.created_count }} / ~{{ item.updated_count }} / ={{ item.unchanged_count }} / !{{ item.failed_count }}</td><td><button v-if="item.status==='pending'" class="danger" @click="cancel(item.id)">Annuler</button></td></tr></tbody></table></section></template>
