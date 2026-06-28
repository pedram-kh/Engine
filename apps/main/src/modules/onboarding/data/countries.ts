/**
 * Phase-1 launch-market country picker options.
 *
 * Backend contracts (`UpdateProfileRequest`, `UpsertTaxProfileRequest`)
 * validate `country_code` / `address.country_code` as a 2-character
 * ISO-3166-1 alpha-2 code. Every consumer surface that asks a creator
 * for a country MUST use this list (or an equivalent `<v-select>`) —
 * a free-text `<v-text-field>` invites users to type the natural-
 * language name ("Spain") which fails the backend's `size:2` rule and
 * produces a `validation.failed` envelope rather than a successful
 * save. The Step 6 stabilization audit (May 19, 2026) caught one
 * surface still using a free-text input; this module exists so future
 * surfaces share the same list rather than duplicating it.
 *
 * The list is intentionally narrow — Phase 1 launch markets only.
 * Expand carefully: every code added here MUST also be representable
 * by the backend's persistence layer (the `country_code` columns are
 * plain `varchar(2)` so adding codes is purely a UI decision).
 */
export interface CountryOption {
  readonly code: string
  readonly label: string
}

export const COUNTRY_OPTIONS: readonly CountryOption[] = [
  // Phase-1 launch markets (kept first for discoverability)
  { code: 'IE', label: 'Ireland' },
  { code: 'GB', label: 'United Kingdom' },
  { code: 'PT', label: 'Portugal' },
  { code: 'IT', label: 'Italy' },
  { code: 'ES', label: 'Spain' },
  { code: 'FR', label: 'France' },
  { code: 'DE', label: 'Germany' },
  { code: 'US', label: 'United States' },
  { code: 'CA', label: 'Canada' },
  // Broader world list — mirrors DIAL_CODE_OPTIONS world set
  { code: 'AE', label: 'United Arab Emirates' },
  { code: 'AT', label: 'Austria' },
  { code: 'AU', label: 'Australia' },
  { code: 'BE', label: 'Belgium' },
  { code: 'BG', label: 'Bulgaria' },
  { code: 'BR', label: 'Brazil' },
  { code: 'CH', label: 'Switzerland' },
  { code: 'CN', label: 'China' },
  { code: 'CY', label: 'Cyprus' },
  { code: 'CZ', label: 'Czech Republic' },
  { code: 'DK', label: 'Denmark' },
  { code: 'EE', label: 'Estonia' },
  { code: 'EG', label: 'Egypt' },
  { code: 'FI', label: 'Finland' },
  { code: 'GR', label: 'Greece' },
  { code: 'HR', label: 'Croatia' },
  { code: 'HU', label: 'Hungary' },
  { code: 'ID', label: 'Indonesia' },
  { code: 'IN', label: 'India' },
  { code: 'JP', label: 'Japan' },
  { code: 'KR', label: 'South Korea' },
  { code: 'LT', label: 'Lithuania' },
  { code: 'LU', label: 'Luxembourg' },
  { code: 'LV', label: 'Latvia' },
  { code: 'MA', label: 'Morocco' },
  { code: 'MT', label: 'Malta' },
  { code: 'MX', label: 'Mexico' },
  { code: 'MY', label: 'Malaysia' },
  { code: 'NG', label: 'Nigeria' },
  { code: 'NL', label: 'Netherlands' },
  { code: 'NO', label: 'Norway' },
  { code: 'NZ', label: 'New Zealand' },
  { code: 'PH', label: 'Philippines' },
  { code: 'PK', label: 'Pakistan' },
  { code: 'PL', label: 'Poland' },
  { code: 'RO', label: 'Romania' },
  { code: 'RS', label: 'Serbia' },
  { code: 'RU', label: 'Russia' },
  { code: 'SA', label: 'Saudi Arabia' },
  { code: 'SE', label: 'Sweden' },
  { code: 'SG', label: 'Singapore' },
  { code: 'SI', label: 'Slovenia' },
  { code: 'SK', label: 'Slovakia' },
  { code: 'TH', label: 'Thailand' },
  { code: 'TR', label: 'Turkey' },
  { code: 'TW', label: 'Taiwan' },
  { code: 'UA', label: 'Ukraine' },
  { code: 'VN', label: 'Vietnam' },
  { code: 'ZA', label: 'South Africa' },
] as const

/**
 * Resolve a country code (e.g. "ES") to its human-readable label
 * ("Spain"). Falls back to the code itself when the code is not in
 * the picker list — keeps display surfaces forward-compatible with
 * any backend-only country additions.
 */
export function labelForCountryCode(code: string | null): string {
  if (code === null) return ''
  return COUNTRY_OPTIONS.find((c) => c.code === code)?.label ?? code
}
