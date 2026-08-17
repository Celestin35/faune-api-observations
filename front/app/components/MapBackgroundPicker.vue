<script setup lang="ts">
import type { MapBackground } from '~/utils/map-backgrounds'

const background = defineModel<MapBackground>({ required: true })
</script>

<template>
  <div class="map-background-picker" role="group" aria-label="Choisir le fond de carte">
    <button
      class="map-background-option map-background-standard"
      :class="{ selected: background === 'standard' }"
      :aria-pressed="background === 'standard'"
      title="Plan standard"
      @click.stop="background = 'standard'"
    >
      <span>Plan</span>
    </button>
    <button
      class="map-background-option map-background-relief"
      :class="{ selected: background === 'relief' }"
      :aria-pressed="background === 'relief'"
      title="Carte avec relief"
      @click.stop="background = 'relief'"
    >
      <span>Relief</span>
    </button>
  </div>
</template>
