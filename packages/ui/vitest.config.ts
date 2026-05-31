import { fileURLToPath } from 'node:url'

import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

/**
 * Vitest harness for `@catalyst/ui` (Sprint 4 Chunk 1 sub-step 1a).
 *
 * Stands up the package's own test runner so the shared component specs
 * no longer have to live in the consuming SPA (the "packages/ui has no
 * test harness" tech-debt entry this chunk closes). The config mirrors
 * `apps/main/vitest.config.ts`'s Vuetify-under-jsdom wiring:
 *   - jsdom environment + the browser-API polyfills (`tests/setup.ts`).
 *   - `vuetify` inlined via `server.deps.inline` so its ESM-only build is
 *     transformed for the test runner (matches apps/main).
 *   - `@vitejs/plugin-vue` to compile the `.vue` SFCs — apps/main gets this
 *     from its Vite config; `packages/ui` has no Vite build of its own, so
 *     the plugin is wired in directly here.
 *
 * No coverage thresholds: this mirrors `packages/design-tokens` (which runs
 * Vitest without a gate). The shared-component coverage gate is not part of
 * the harness-gap close; the existing source-inspection architecture tests
 * (`typography-consumption`, `color-system-parity`) remain the package's
 * regression net alongside these specs.
 */
export default defineConfig({
  plugins: [vue()],
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.{spec,test}.ts'],
    exclude: ['node_modules/**'],
    globals: true,
    root: fileURLToPath(new URL('./', import.meta.url)),
    setupFiles: ['./tests/setup.ts'],
    server: {
      deps: {
        inline: ['vuetify'],
      },
    },
  },
})
