/**
 * Resolve a thrown error to a user-facing i18n key.
 *
 * Inputs:
 *   - `ApiError` — the typed error from `@catalyst/api-client`. Its
 *     `code` is the dotted backend code (e.g.
 *     `auth.invalid_credentials`); we resolve via `t(error.code)` so
 *     the i18n bundle controls the surface text. `meta` (if present)
 *     is forwarded as named placeholders for keys that interpolate
 *     (`auth.login.account_locked_temporary` ⇒ `{minutes}`).
 *   - Network errors (status 0, code `network.error`) resolve to the
 *     dedicated `auth.ui.errors.network` key.
 *   - Anything else resolves to `auth.ui.errors.unknown`.
 *
 * Returns the resolved i18n key + the message bag. The caller passes
 * the key + bag to `t(key, bag)` to render the localised string.
 *
 * Two pages render error messages from this resolver: SignIn (every
 * login error code) and ResetPassword / EnableTotp (the inline error
 * surface). Extracting it as a composable keeps the test surface
 * narrow — the resolver is tested once here and the pages just verify
 * that they render whatever the resolver returns.
 */

import { ApiError } from '@catalyst/api-client'

/**
 * The bag of named placeholders forwarded into `t(key, bag)`. The
 * backend's error meta carries values like `minutes` and `seconds` for
 * the rate-limit messages.
 */
export type MessageBag = Record<string, string | number>

export interface ResolvedError {
  key: string
  values: MessageBag
}

/**
 * Default fallback when the thrown value is neither an ApiError nor a
 * recognised network error.
 */
export const UNKNOWN_ERROR_KEY = 'auth.ui.errors.unknown'

/**
 * Network-error key — fired when the api-client surfaces status 0
 * (transport failure) or `code === 'network.error'`.
 */
export const NETWORK_ERROR_KEY = 'auth.ui.errors.network'

/**
 * Backend codes we explicitly know exist in the i18n bundle. Anything
 * outside this list still gets `t(error.code)`-resolved on the page,
 * but we conservatively guard against a fallback to `auth.ui.errors.unknown`
 * by giving `t()` a chance to find the key first.
 *
 * The architecture test in `tests/unit/architecture/i18n-auth-codes.spec.ts`
 * is the source of truth — every backend code MUST resolve in every
 * locale. This list is purely for the resolver's "do I know this key
 * in the bundle?" check; it does NOT short-circuit code → message
 * mapping.
 */
function isLikelyBundledCode(code: string): boolean {
  return code.startsWith('auth.') || code.startsWith('validation.')
}

/**
 * Pure function — does NOT call `t()`. The caller supplies a
 * `messageExists(key)` predicate so the resolver can fall back to the
 * unknown key when the resolved key is not present in the active
 * locale (defence in depth — the architecture test should already
 * prevent this, but a corrupted bundle should not crash the UI).
 */
export function resolveErrorMessage(
  error: unknown,
  messageExists?: (key: string) => boolean,
): ResolvedError {
  const exists = messageExists ?? ((): boolean => true)

  if (error instanceof ApiError) {
    if (error.status === 0 || error.code === 'network.error') {
      return { key: NETWORK_ERROR_KEY, values: {} }
    }
    if (isLikelyBundledCode(error.code) && exists(error.code)) {
      const values: MessageBag = {}
      // Backend rate-limit / lockout codes carry their interpolation
      // payload under the first detail entry's `meta` (e.g. `{minutes: 5}`).
      const detailMeta = error.details[0]?.meta
      if (detailMeta !== undefined) {
        for (const [k, v] of Object.entries(detailMeta)) {
          if (typeof v === 'string' || typeof v === 'number') {
            values[k] = v
          }
        }
      }
      return { key: error.code, values }
    }
  }

  return { key: UNKNOWN_ERROR_KEY, values: {} }
}
