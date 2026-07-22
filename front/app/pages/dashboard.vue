<script setup lang="ts">
const api = useApi()
const { data, error, refresh } = await useAsyncData('dashboard', () => api<any>('/dashboard'))
</script>
<template>
  <section><h1>Tableau de bord</h1><p class="muted">État de la collection locale et des synchronisations.</p>
    <p v-if="error" class="error">{{ error.message }}</p>
    <div class="grid">
      <article class="card"><div class="stat">{{ data?.observations ?? '—' }}</div>observations canoniques</article>
      <article class="card"><div class="stat">{{ data?.active_monitoring ?? '—' }}</div>surveillances actives</article>
      <article class="card"><div class="stat">{{ data?.running_imports ?? '—' }}</div>imports en cours</article>
      <article class="card"><strong>Sources</strong><div v-for="(count, source) in data?.sources" :key="source">{{ source }} : {{ count }}</div></article>
    </div><div class="actions"><button @click="refresh()">Actualiser</button><NuxtLink to="/exploration"><button>Nouvelle exploration</button></NuxtLink></div>
  </section>
</template>
