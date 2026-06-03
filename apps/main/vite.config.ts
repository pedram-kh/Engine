import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vuetify from 'vite-plugin-vuetify'

/**
 * Vuetify `autoImport` injects per-component imports (`vuetify/components/VBtn`,
 * …) into each SFC. Those SFCs live in lazy route chunks, so Vite's startup
 * dep-scan never sees them — the first visit to a section discovers new
 * components, re-bundles, and emits `optimized dependencies changed. reloading`,
 * which forces a full page reload that interrupts the in-flight route change
 * (visible symptom: "long refresh, doesn't navigate the first time, works the
 * second"). Pre-declaring the component modules here bundles them at server
 * start, so every section navigates instantly on first click. Dev-only concern
 * — production builds already bundle everything ahead of time.
 *
 * Entries are the module specifiers `autoImport` actually generates (sub-
 * components share a module, e.g. VContainer/VRow/VCol/VSpacer → VGrid,
 * VCardText → VCard, VDataTableServer → VDataTable). Add new ones when a page
 * introduces a Vuetify component not already covered.
 */
const vuetifyComponents = [
  'VApp',
  'VMain',
  'VAppBar',
  'VNavigationDrawer',
  'VList',
  'VCard',
  'VBtn',
  'VBtnToggle',
  'VIcon',
  'VAvatar',
  'VMenu',
  'VDivider',
  'VGrid',
  'VForm',
  'VSelect',
  'VTextField',
  'VTextarea',
  'VCheckbox',
  'VFileInput',
  'VAlert',
  'VChip',
  'VChipGroup',
  'VSkeletonLoader',
  'VProgressLinear',
  'VProgressCircular',
  'VDialog',
  'VSnackbar',
  'VDataTable',
  'VTable',
].map((name) => `vuetify/components/${name}`)

export default defineConfig({
  plugins: [vue(), vuetify({ autoImport: true })],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  optimizeDeps: {
    include: ['vuetify', 'vuetify/directives', ...vuetifyComponents],
  },
  server: {
    host: '127.0.0.1',
    port: 5173,
    strictPort: true,
    proxy: {
      // Both prefixes route to the Laravel backend; `/sanctum/csrf-cookie`
      // sets the XSRF-TOKEN cookie that the api-client forwards on
      // state-changing requests (`docs/04-API-DESIGN.md § 4`).
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
      '/sanctum': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  preview: {
    host: '127.0.0.1',
    port: 5173,
    strictPort: true,
  },
})
