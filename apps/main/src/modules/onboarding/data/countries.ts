/**
 * Country picker options — re-exported from the shared registry in
 * `@catalyst/api-client` so both SPAs offer the identical full ISO
 * 3166-1 list (Phase-1 launch markets pinned first).
 *
 * This module remains the import path used across `apps/main` — the
 * canonical data moved to the shared package when the list expanded
 * from the 58 curated launch/world markets to the full ISO set.
 *
 * Backend contracts (`UpdateProfileRequest`, `UpsertTaxProfileRequest`)
 * validate `country_code` / `address.country_code` as a 2-character
 * ISO-3166-1 alpha-2 code. Every consumer surface that asks a creator
 * for a country MUST use this list (or an equivalent `<v-select>`) —
 * a free-text `<v-text-field>` invites users to type the natural-
 * language name ("Spain") which fails the backend's `size:2` rule
 * (Step 6 stabilization audit, May 19, 2026).
 */
export {
  COUNTRY_OPTIONS,
  LAUNCH_MARKET_COUNTRY_CODES,
  labelForCountryCode,
  type CountryOption,
} from '@catalyst/api-client'
