import { createAuthApi, createHttpClient } from '@catalyst/api-client'

/**
 * Singleton HTTP client wired to the Laravel backend. Every API call
 * across the main SPA goes through this instance so Sanctum SPA cookie
 * auth, CSRF preflight, and `ApiError` normalization land in exactly
 * one place (`docs/02-CONVENTIONS.md § 3.6`).
 *
 * `VITE_API_BASE_URL` defaults to `/api/v1` in dev; the Vite proxy in
 * `vite.config.ts` forwards both `/api` and `/sanctum` to
 * `http://127.0.0.1:8000`.
 */
export const http = createHttpClient({
  baseUrl: import.meta.env.VITE_API_BASE_URL ?? '/api/v1',
})

/**
 * Typed authentication API bound to the main SPA's surface
 * (`/me`, `/auth/*`). The Pinia auth store consumes this directly.
 */
export const authApi = createAuthApi(http, { variant: 'main' })
