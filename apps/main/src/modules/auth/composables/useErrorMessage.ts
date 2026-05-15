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
 * Backend code prefixes we accept for the bundle lookup. A code that
 * starts with one of these is forwarded to `t(error.code)` — anything
 * outside this list short-circuits to the unknown fallback.
 *
 * Adding a new prefix here is a deliberate decision: it widens the set
 * of backend codes the SPA will attempt to render via i18n. Each
 * prefix corresponds to a known emit-site on the backend whose codes
 * have parallel entries in the i18n bundle:
 *
 *   - `auth.`        — Identity-module errors (sign-in, MFA, lockout, …).
 *   - `validation.`  — Laravel validation rule names normalised by the
 *                      ValidationException → ApiError shaper.
 *   - `rate_limit.`  — Rate-limiter responses from the four named
 *                      limiters in
 *                      `App\Modules\Identity\IdentityServiceProvider::registerRateLimits()`
 *                      (chunk 7.1; closes the production UX bug where
 *                      throttle errors rendered with the unknown
 *                      fallback). The `rate_limit.exceeded` bundle
 *                      entries live as a top-level sibling of `auth`
 *                      in the en/pt/it `auth.json` files.
 *   - `creator.`     — Creators-module errors (Sprint 3 Chunk 3 sub-step 1).
 *                      Covers `creator.not_found`, `creator.wizard.*`
 *                      (feature_disabled, feature_enabled, incomplete).
 *                      Bundle entries live in the en/pt/it
 *                      `creator.json` files; the dedicated architecture
 *                      test in
 *                      `tests/unit/architecture/i18n-creator-codes.spec.ts`
 *                      walks the backend Creators module and asserts
 *                      each harvested literal resolves to a leaf
 *                      string in every locale.
 *
 * Per-prefix architecture tests in
 * `tests/unit/architecture/i18n-auth-codes.spec.ts` (auth.* +
 * rate_limit.*) and `tests/unit/architecture/i18n-creator-codes.spec.ts`
 * (creator.*) walk the backend source for each prefix's literals and
 * assert each one resolves to a leaf string in every locale, so a new
 * code emitted on the backend without a matching bundle entry trips
 * CI before merge.
 *
 * Codes outside these prefixes — e.g. `http.invalid_response_body`,
 * `network.error` (handled separately above), or anything synthesised
 * from the api-client transport layer — fall through to
 * `auth.ui.errors.unknown`. That conservative posture is intentional:
 * widening the predicate to "anything with a dot" would mask backend
 * regressions where a typo'd code lands and renders verbatim.
 *
 * Standing contract: a new top-level prefix (e.g. `tenant.*`,
 * `brand.*`) requires extending this predicate AND adding a parallel
 * architecture-test file AND shipping bundle entries in all three
 * locales in the same commit. The chunk-7.1 saga (chunk-3 widening
 * of this list to add `creator.*`) is the reference precedent.
 */
function isLikelyBundledCode(code: string): boolean {
  return (
    code.startsWith('auth.') ||
    code.startsWith('validation.') ||
    code.startsWith('rate_limit.') ||
    code.startsWith('creator.') ||
    // Sprint 3 Chunk 4 — magic-link invitation acceptance error codes
    // emitted by SignUpService::acceptInvitationOnSignUp(). Codes:
    //   invitation.not_found, invitation.expired,
    //   invitation.already_accepted, invitation.email_mismatch.
    // Bundle entries live in the en/pt/it `auth.json` files under
    // `invitation.*`.
    code.startsWith('invitation.')
  )
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
