/**
 * Wizard submit-error → i18n-key resolver.
 *
 * Every wizard step's catch block ends up doing the same thing:
 *
 *     errorKey = error instanceof ApiError ? error.code : <generic>
 *
 * The naive form has two bugs:
 *
 *   1. `validation.failed` (the JSON:API envelope code that
 *      `ValidationExceptionRenderer` emits for every 422) has no
 *      bundle entry — per-rule disambiguation lives in the per-field
 *      `details[]` array, not at the top level. Rendering it through
 *      `t()` falls through to the literal "validation.failed" string
 *      in red text. Caught in the wild on Step 6 Tax (May 19, 2026).
 *   2. Any future backend-side renamed code (`auth.session.expired`
 *      → `session.expired`, etc.) silently surfaces as the new
 *      literal string until someone notices and adds a bundle entry.
 *
 * This helper exists for the FORM-LESS wizard surfaces (Step 4
 * remove, Step 5 KYC initiate, Step 7 Payout initiate, Step 8
 * Contract initiate, Step 9 Review submit) where the caller has no
 * per-field UI to bind to. For those, the right behaviour is:
 *
 *   - Pass through codes that are KNOWN good business codes
 *     (anything namespaced `creator.*` or `vendor.*` — these are
 *     intentional bundle entries the SPA's catalog has translations
 *     for, and `te(key)` would confirm if we wanted a stricter
 *     guard).
 *   - Block `validation.failed` and other "system" codes; map them
 *     to the generic `creator.ui.errors.upload_failed` fallback.
 *
 * Typed-form surfaces (Steps 2 / 3 / 6, plus every page the SignUp
 * audit already covered) MUST NOT use this helper — they should
 * call `extractFieldErrors` directly and bind per-field via
 * `:error-messages`. The banner is the fallback for top-level
 * BUSINESS codes only.
 */

import { ApiError } from '@catalyst/api-client'

const PASSTHROUGH_PREFIXES: readonly string[] = ['creator.', 'vendor.', 'wizard.']

/**
 * @param error - the value caught from an async wizard action
 * @param fallback - the i18n key the banner falls back to when no
 *   business-namespaced code is available. Steps that talk to vendor
 *   APIs typically use `creator.ui.errors.upload_failed`; the review
 *   submit uses `creator.wizard.incomplete`.
 */
export function resolveSubmitErrorKey(error: unknown, fallback: string): string {
  if (!(error instanceof ApiError)) return fallback

  const code = error.code
  if (code === 'validation.failed') return fallback

  for (const prefix of PASSTHROUGH_PREFIXES) {
    if (code.startsWith(prefix)) return code
  }

  return fallback
}
