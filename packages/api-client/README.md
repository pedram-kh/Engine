# @catalyst/api-client

Shared TypeScript client for the Catalyst Engine API. Used by `apps/main` and `apps/admin`.

## Sprint 0

- `createApiClient({ baseURL })` — returns a configured `AxiosInstance` with Catalyst's standard headers and a unified error normalizer that maps HTTP error responses to `ApiError` values.

## Phase 1 plan

Subsequent sprints add:

- Sanctum CSRF cookie handshake (Sprint 1) — `/sanctum/csrf-cookie` before stateful requests.
- Per-module typed wrappers (`identity.login(...)`, `creators.list(...)`, etc.) generated from the OpenAPI spec.
- Request/response interceptors for `traceId` propagation, locale negotiation, and retries on 502/503.
