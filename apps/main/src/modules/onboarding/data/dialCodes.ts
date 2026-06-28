/**
 * Dial-code registry for the phone / WhatsApp country-code prefix select.
 *
 * Covers every country in COUNTRY_OPTIONS plus common extras so the list
 * is useful even when creators are based outside the Phase-1 launch set.
 * Ordered: COUNTRY_OPTIONS countries first (most likely to be selected),
 * then the broader world list alphabetically by label.
 *
 * The `shortLabel` is what renders in the select items and in the field
 * pill once selected — flag emoji + dial code only, deliberately compact.
 * The `label` adds the country name for the autocomplete filter so a
 * user can type "Spain" or "+34" and find the same entry.
 */

export interface DialCodeOption {
  /** ISO 3166-1 alpha-2 country code. */
  readonly code: string
  /** E.164 country dial code, e.g. "+34". */
  readonly dialCode: string
  /** Unicode flag emoji derived from the ISO code. */
  readonly flag: string
  /** Displayed in the open dropdown: "🇪🇸 +34 Spain". */
  readonly label: string
  /** Displayed on the closed pill / selection: "🇪🇸 +34". */
  readonly shortLabel: string
}

function flag(isoCode: string): string {
  return [...isoCode.toUpperCase()]
    .map((c) => String.fromCodePoint(0x1f1e6 + c.charCodeAt(0) - 65))
    .join('')
}

function entry(code: string, dialCode: string, countryName: string): DialCodeOption {
  const f = flag(code)
  return {
    code,
    dialCode,
    flag: f,
    label: `${f} ${dialCode} ${countryName}`,
    shortLabel: `${f} ${dialCode}`,
  }
}

// ---------------------------------------------------------------------------
// Phase-1 COUNTRY_OPTIONS countries (shown first)
// ---------------------------------------------------------------------------
const LAUNCH_MARKET_CODES: readonly DialCodeOption[] = [
  entry('IE', '+353', 'Ireland'),
  entry('GB', '+44', 'United Kingdom'),
  entry('PT', '+351', 'Portugal'),
  entry('IT', '+39', 'Italy'),
  entry('ES', '+34', 'Spain'),
  entry('FR', '+33', 'France'),
  entry('DE', '+49', 'Germany'),
  entry('US', '+1', 'United States'),
  entry('CA', '+1', 'Canada'),
]

// ---------------------------------------------------------------------------
// Broader world list (remaining EU + common markets)
// ---------------------------------------------------------------------------
const WORLD_CODES: readonly DialCodeOption[] = [
  entry('AE', '+971', 'United Arab Emirates'),
  entry('AT', '+43', 'Austria'),
  entry('AU', '+61', 'Australia'),
  entry('BE', '+32', 'Belgium'),
  entry('BG', '+359', 'Bulgaria'),
  entry('BR', '+55', 'Brazil'),
  entry('CH', '+41', 'Switzerland'),
  entry('CN', '+86', 'China'),
  entry('CY', '+357', 'Cyprus'),
  entry('CZ', '+420', 'Czech Republic'),
  entry('DK', '+45', 'Denmark'),
  entry('EE', '+372', 'Estonia'),
  entry('EG', '+20', 'Egypt'),
  entry('FI', '+358', 'Finland'),
  entry('GR', '+30', 'Greece'),
  entry('HR', '+385', 'Croatia'),
  entry('HU', '+36', 'Hungary'),
  entry('ID', '+62', 'Indonesia'),
  entry('IN', '+91', 'India'),
  entry('JP', '+81', 'Japan'),
  entry('KR', '+82', 'South Korea'),
  entry('LT', '+370', 'Lithuania'),
  entry('LU', '+352', 'Luxembourg'),
  entry('LV', '+371', 'Latvia'),
  entry('MA', '+212', 'Morocco'),
  entry('MT', '+356', 'Malta'),
  entry('MX', '+52', 'Mexico'),
  entry('MY', '+60', 'Malaysia'),
  entry('NG', '+234', 'Nigeria'),
  entry('NL', '+31', 'Netherlands'),
  entry('NO', '+47', 'Norway'),
  entry('NZ', '+64', 'New Zealand'),
  entry('PH', '+63', 'Philippines'),
  entry('PK', '+92', 'Pakistan'),
  entry('PL', '+48', 'Poland'),
  entry('RO', '+40', 'Romania'),
  entry('RS', '+381', 'Serbia'),
  entry('RU', '+7', 'Russia'),
  entry('SA', '+966', 'Saudi Arabia'),
  entry('SE', '+46', 'Sweden'),
  entry('SG', '+65', 'Singapore'),
  entry('SI', '+386', 'Slovenia'),
  entry('SK', '+421', 'Slovakia'),
  entry('TH', '+66', 'Thailand'),
  entry('TR', '+90', 'Turkey'),
  entry('TW', '+886', 'Taiwan'),
  entry('UA', '+380', 'Ukraine'),
  entry('VN', '+84', 'Vietnam'),
  entry('ZA', '+27', 'South Africa'),
]

export const DIAL_CODE_OPTIONS: readonly DialCodeOption[] = [...LAUNCH_MARKET_CODES, ...WORLD_CODES]

/** ISO code → dial code lookup (first match wins for codes shared by two countries, e.g. US/CA). */
const DIAL_CODE_BY_ISO: ReadonlyMap<string, string> = new Map(
  DIAL_CODE_OPTIONS.map((d) => [d.code, d.dialCode]),
)

/**
 * Return the dial code for an ISO country code, or '+1' as a safe
 * fallback when the code isn't in the registry.
 */
export function dialCodeForCountry(isoCode: string | null): string {
  if (isoCode === null) return ''
  return DIAL_CODE_BY_ISO.get(isoCode.toUpperCase()) ?? ''
}

/**
 * Split a stored phone string (e.g. "+34 612 345 678") into its dial
 * code and local-number parts. Tries to match the longest known dial
 * code that is a prefix of the value.
 *
 * Returns `{ dialCode: '', local: value }` when no known prefix matches
 * (e.g. legacy free-text entry without a `+` prefix).
 */
export function splitPhoneValue(value: string | null): { dialCode: string; local: string } {
  if (!value) return { dialCode: '', local: '' }

  // Sort longest-first so "+1 242" is tested before "+1".
  const sorted = [...DIAL_CODE_OPTIONS].sort((a, b) => b.dialCode.length - a.dialCode.length)
  for (const option of sorted) {
    if (value.startsWith(option.dialCode)) {
      const local = value.slice(option.dialCode.length).trimStart()
      return { dialCode: option.dialCode, local }
    }
  }
  return { dialCode: '', local: value }
}
