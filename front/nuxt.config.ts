import type { NuxtConfig } from 'nuxt/config'

export default {
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },
  css: ['maplibre-gl/dist/maplibre-gl.css', '~/assets/main.css'],
  runtimeConfig: {
    // Laravel target used only by the server-side API proxy.
    apiBase: process.env.NUXT_API_BASE || 'http://localhost:8000/api',
    // Browser and SSR requests stay on the Nuxt origin; Nitro forwards them.
    public: { apiBase: process.env.NUXT_PUBLIC_API_BASE || '/api' },
  },
  // Kept as a dedicated `npm run typecheck` step; enabling it inside the
  // Vite 8 production build currently passes duplicate project arguments.
  typescript: { strict: true, typeCheck: false },
} satisfies NuxtConfig
