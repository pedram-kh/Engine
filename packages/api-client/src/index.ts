/**
 * Public surface of `@catalyst/api-client`.
 *
 * Consumers import:
 *   - `createHttpClient` — the only place axios lives in the monorepo.
 *     Configures Sanctum SPA cookie auth, CSRF preflight, and error
 *     normalization (`docs/04-API-DESIGN.md § 4`).
 *   - `createAuthApi` — typed wrapper functions for every auth endpoint
 *     shipped through Sprint-1 chunks 3 → 5.
 *   - `ApiError` — single error class thrown by every typed function.
 *     Preserves backend error codes verbatim per chunk-4 / chunk-5
 *     standard 5.4 (`docs/PROJECT-WORKFLOW.md § 5`).
 *   - All wire DTO types under `./types`.
 */

export { createAuthApi, type AuthApi, type AuthVariant, type CreateAuthApiOptions } from './auth'
export { ApiError, extractFieldErrors, type ApiErrorDetail, type ApiErrorOptions } from './errors'
export {
  createHttpClient,
  type CreateHttpClientOptions,
  type HttpClient,
  type HttpRequestOptions,
} from './http'
export { uploadToPresignedUrl } from './presigned'
export * from './types'
