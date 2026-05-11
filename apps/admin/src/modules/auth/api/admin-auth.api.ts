/**
 * Module-local re-export of the singleton {@link AuthApi} bound to the
 * admin SPA's surface. The store imports through here so unit tests can
 * mock the export without mocking the global app-wide `core/api`
 * module.
 *
 * Per `docs/02-CONVENTIONS.md § 3.1`, every module that talks to the
 * backend exposes its own `<module>.api.ts` file. The admin module
 * keeps its surface narrow — only the auth API — and the file is named
 * `admin-auth.api.ts` (not `auth.api.ts`) to make the cross-SPA
 * boundary visible in greps and code search.
 *
 * The singleton itself is bound to the admin endpoint variant in
 * `apps/admin/src/core/api/index.ts`:
 *
 *     createAuthApi(http, { variant: 'admin' })
 *
 * which routes `/me` → `/admin/me`, `/auth/login` → `/admin/auth/login`,
 * etc. The browser then sends the `catalyst_admin_session` cookie (set
 * by the backend's `UseAdminSessionCookie` middleware whenever the path
 * begins with `api/v1/admin/`) — there is NO SPA-side cookie selection,
 * the boundary is path-based and enforced on the backend.
 *
 * This file is a pure re-export. The "exclusion + guard" pattern from
 * chunk 6.2-6.4 change-request #3 applies: the file is excluded from
 * the auth-flow 100% coverage gate (its contract is verified by
 * typecheck alone), and an architecture test in
 * `apps/admin/tests/unit/architecture/auth-api-reexport-shape.spec.ts`
 * guards against runtime logic landing here.
 */

import { authApi } from '@/core/api'

export { authApi }
