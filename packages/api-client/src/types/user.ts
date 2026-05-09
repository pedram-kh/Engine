/**
 * Domain types for the authenticated user, mirroring the wire contract
 * exposed by the backend's `App\Modules\Identity\Http\Resources\UserResource`.
 *
 * These types are the single source consumed by both the main and admin
 * SPAs (Q1 in `docs/reviews/sprint-1-chunk-6-plan-approved.md`). The
 * shape is JSON:API-flavoured: a top-level `data` envelope wrapping a
 * resource object with `id`, `type`, and `attributes`.
 *
 * Conventions:
 *   - All keys are `snake_case`, matching the backend `toArray()` output
 *     verbatim. The api-client never re-cases payloads — drift between
 *     frontend and backend is loud and immediate.
 *   - Discriminator unions are non-optional. `user_type` is the
 *     discriminator the chunk-6.5 router will switch on
 *     (`creator | agency_user | platform_admin`); `brand_user` is
 *     reserved for Phase 2 and is included so the type matches the
 *     backend enum even though the SPA never sees it during Phase 1.
 *   - Timestamps are ISO 8601 strings (`Carbon::toIso8601String()`).
 *     `email_verified_at` and `last_login_at` are nullable on the
 *     wire and surface as `string | null` here.
 */

/**
 * The full set of user kinds the backend recognises. Mirrors
 * `App\Modules\Identity\Enums\UserType`.
 *
 * `brand_user` is reserved for Phase 2 — Phase 1 never assigns it but
 * the union carries it so the schema and code agree on the eventual
 * full set.
 */
export type UserType = 'creator' | 'agency_user' | 'brand_user' | 'platform_admin'

/**
 * The user-facing theme preference. Mirrors
 * `App\Modules\Identity\Enums\ThemePreference`.
 */
export type ThemePreference = 'light' | 'dark' | 'system'

/**
 * The two-letter language code the user picked (or `null` if they have
 * not picked one yet). The accepted set is constrained to the locales
 * the SPA i18n bundle ships (`en | pt | it`); future locales must land
 * here AND in `apps/main/src/locales/` AND on the backend list.
 */
export type PreferredLanguage = 'en' | 'pt' | 'it'

/**
 * Three-letter ISO-4217 currency code (e.g. `'USD'`, `'EUR'`, `'BRL'`).
 * The backend stores this as an opaque string; the type is intentionally
 * `string` rather than a union so adding a currency on the backend does
 * not require a frontend release.
 */
export type CurrencyCode = string

/**
 * IANA timezone identifier (e.g. `'Europe/Lisbon'`, `'America/Sao_Paulo'`).
 * Same opacity rationale as {@link CurrencyCode}.
 */
export type TimezoneIdentifier = string

/**
 * The flat attribute map the backend nests under `data.attributes`. Keys
 * mirror `UserResource::toArray()['attributes']` verbatim.
 */
export interface UserAttributes {
  email: string
  email_verified_at: string | null
  name: string
  user_type: UserType
  preferred_language: PreferredLanguage | null
  preferred_currency: CurrencyCode | null
  timezone: TimezoneIdentifier | null
  theme_preference: ThemePreference
  /**
   * `true` for users whose `users.mfa_required` flag is set (today
   * always platform admins, by chunk-5 priority #7). The SPA uses this
   * to drive the 2FA-enrollment-required flow on the admin surface.
   */
  mfa_required: boolean
  /**
   * `true` once the user has completed the two-step 2FA enrollment
   * flow. The flag is the `User::hasTwoFactorEnabled()` projection on
   * the backend.
   */
  two_factor_enabled: boolean
  last_login_at: string | null
  created_at: string
}

/**
 * The wrapper the backend's `JsonResource` produces — `id` is the user's
 * ULID (the public identifier per `docs/03-DATA-MODEL.md §2`), `type` is
 * the literal string `'user'`, and `attributes` is the flat map above.
 */
export interface UserResource {
  id: string
  type: 'user'
  attributes: UserAttributes
}

/**
 * The full envelope `GET /api/v1/me` (and `GET /api/v1/admin/me`)
 * returns on a 2xx. The api-client unwraps this on behalf of callers
 * and exposes {@link UserResource} directly — but the typed shape is
 * preserved here for tests and for any caller that wants to inspect the
 * raw envelope.
 */
export interface UserEnvelope {
  data: UserResource
}

/**
 * Convenience alias used throughout the SPA. The store's `user: User | null`
 * field is typed as this; consumers that need the full envelope use
 * {@link UserResource} directly.
 */
export type User = UserResource
