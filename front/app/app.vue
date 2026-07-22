<template>
  <div>
    <header class="topbar">
      <NuxtLink class="brand" to="/dashboard">Observations</NuxtLink>
      <nav>
        <NuxtLink to="/dashboard">Tableau de bord</NuxtLink>
        <NuxtLink to="/exploration">Explorer</NuxtLink>
        <NuxtLink to="/carte">Carte</NuxtLink>
        <NuxtLink to="/surveillances">Surveillances</NuxtLink>
        <NuxtLink to="/imports">Imports</NuxtLink>
      </nav>
    </header>
    <main><NuxtPage /></main>
  </div>
</template>
