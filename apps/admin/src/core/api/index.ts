import { createAuthApi, createHttpClient } from '@catalyst/api-client'

/**
 * Singleton HTTP client wired to the Laravel backend. Same contract as
 * the main SPA's instance — Sanctum cookie auth, CSRF preflight, error
 * normalization. The admin SPA shares the api-client package so types
 * and error handling never drift between the two surfaces (chunk-6.2
 * review priority #9).
 */
export const http = createHttpClient({
  baseUrl: import.meta.env.VITE_API_BASE_URL ?? '/api/v1',
})

/**
 * Typed authentication API bound to the admin SPA's surface
 * (`/admin/me`, `/admin/auth/*`). Chunk 7 wires this into the admin
 * Pinia store.
 */
export const authApi = createAuthApi(http, { variant: 'admin' })
