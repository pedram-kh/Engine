import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vuetify from 'vite-plugin-vuetify'

/**
 * The admin Vite dev server's `/api` + `/sanctum` proxy target. Defaults
 * to the canonical Laravel dev server port (8000) so `pnpm dev` works
 * out-of-the-box; the chunk-7.6 Playwright config overrides this to
 * point at the e2e-admin-owned 8001 so `e2e-main` and `e2e-admin` can
 * run concurrently in CI without colliding on a single API port. See
 * `apps/admin/playwright.config.ts` for the override site.
 */
const proxyTarget = process.env.CATALYST_ADMIN_API_PROXY_TARGET ?? 'http://127.0.0.1:8000'

export default defineConfig({
  plugins: [vue(), vuetify({ autoImport: true })],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    host: '127.0.0.1',
    port: 5174,
    strictPort: true,
    proxy: {
      // Both prefixes route to the Laravel backend; `/sanctum/csrf-cookie`
      // sets the XSRF-TOKEN cookie that the api-client forwards on
      // state-changing requests (`docs/04-API-DESIGN.md § 4`).
      '/api': {
        target: proxyTarget,
        changeOrigin: true,
      },
      '/sanctum': {
        target: proxyTarget,
        changeOrigin: true,
      },
    },
  },
  preview: {
    host: '127.0.0.1',
    port: 5174,
    strictPort: true,
  },
})
