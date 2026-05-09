/**
 * Module-local re-export of the singleton {@link AuthApi} bound to the
 * main SPA's surface. The store imports through here so unit tests can
 * mock the export without mocking the global app-wide `core/api`
 * module.
 *
 * Per `docs/02-CONVENTIONS.md § 3.1`, every module that talks to the
 * backend exposes its own `<module>.api.ts` file. This module keeps
 * its surface narrow — only the auth API.
 */

import { authApi } from '@/core/api'

export { authApi }
