/**
 * Dial-code registry for the phone / WhatsApp country-code prefix select.
 *
 * Covers every country in COUNTRY_OPTIONS that has a public telephone
 * network (uninhabited territories AQ / BV / HM / TF / UM carry no
 * E.164 code and are omitted). Ordered: Phase-1 launch markets first
 * (most likely to be selected), then the full world list alphabetically
 * by label.
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
// Full world list (alphabetical by label), mirroring COUNTRY_OPTIONS
// ---------------------------------------------------------------------------
const WORLD_CODES: readonly DialCodeOption[] = [
  entry('AF', '+93', 'Afghanistan'),
  entry('AX', '+358', 'Åland Islands'),
  entry('AL', '+355', 'Albania'),
  entry('DZ', '+213', 'Algeria'),
  entry('AS', '+1', 'American Samoa'),
  entry('AD', '+376', 'Andorra'),
  entry('AO', '+244', 'Angola'),
  entry('AI', '+1', 'Anguilla'),
  entry('AG', '+1', 'Antigua & Barbuda'),
  entry('AR', '+54', 'Argentina'),
  entry('AM', '+374', 'Armenia'),
  entry('AW', '+297', 'Aruba'),
  entry('AU', '+61', 'Australia'),
  entry('AT', '+43', 'Austria'),
  entry('AZ', '+994', 'Azerbaijan'),
  entry('BS', '+1', 'Bahamas'),
  entry('BH', '+973', 'Bahrain'),
  entry('BD', '+880', 'Bangladesh'),
  entry('BB', '+1', 'Barbados'),
  entry('BY', '+375', 'Belarus'),
  entry('BE', '+32', 'Belgium'),
  entry('BZ', '+501', 'Belize'),
  entry('BJ', '+229', 'Benin'),
  entry('BM', '+1', 'Bermuda'),
  entry('BT', '+975', 'Bhutan'),
  entry('BO', '+591', 'Bolivia'),
  entry('BA', '+387', 'Bosnia & Herzegovina'),
  entry('BW', '+267', 'Botswana'),
  entry('BR', '+55', 'Brazil'),
  entry('IO', '+246', 'British Indian Ocean Territory'),
  entry('VG', '+1', 'British Virgin Islands'),
  entry('BN', '+673', 'Brunei'),
  entry('BG', '+359', 'Bulgaria'),
  entry('BF', '+226', 'Burkina Faso'),
  entry('BI', '+257', 'Burundi'),
  entry('KH', '+855', 'Cambodia'),
  entry('CM', '+237', 'Cameroon'),
  entry('CV', '+238', 'Cape Verde'),
  entry('BQ', '+599', 'Caribbean Netherlands'),
  entry('KY', '+1', 'Cayman Islands'),
  entry('CF', '+236', 'Central African Republic'),
  entry('TD', '+235', 'Chad'),
  entry('CL', '+56', 'Chile'),
  entry('CN', '+86', 'China'),
  entry('CX', '+61', 'Christmas Island'),
  entry('CC', '+61', 'Cocos (Keeling) Islands'),
  entry('CO', '+57', 'Colombia'),
  entry('KM', '+269', 'Comoros'),
  entry('CG', '+242', 'Congo - Brazzaville'),
  entry('CD', '+243', 'Congo - Kinshasa'),
  entry('CK', '+682', 'Cook Islands'),
  entry('CR', '+506', 'Costa Rica'),
  entry('CI', '+225', 'Côte d’Ivoire'),
  entry('HR', '+385', 'Croatia'),
  entry('CU', '+53', 'Cuba'),
  entry('CW', '+599', 'Curaçao'),
  entry('CY', '+357', 'Cyprus'),
  entry('CZ', '+420', 'Czech Republic'),
  entry('DK', '+45', 'Denmark'),
  entry('DJ', '+253', 'Djibouti'),
  entry('DM', '+1', 'Dominica'),
  entry('DO', '+1', 'Dominican Republic'),
  entry('EC', '+593', 'Ecuador'),
  entry('EG', '+20', 'Egypt'),
  entry('SV', '+503', 'El Salvador'),
  entry('GQ', '+240', 'Equatorial Guinea'),
  entry('ER', '+291', 'Eritrea'),
  entry('EE', '+372', 'Estonia'),
  entry('SZ', '+268', 'Eswatini'),
  entry('ET', '+251', 'Ethiopia'),
  entry('FK', '+500', 'Falkland Islands'),
  entry('FO', '+298', 'Faroe Islands'),
  entry('FJ', '+679', 'Fiji'),
  entry('FI', '+358', 'Finland'),
  entry('GF', '+594', 'French Guiana'),
  entry('PF', '+689', 'French Polynesia'),
  entry('GA', '+241', 'Gabon'),
  entry('GM', '+220', 'Gambia'),
  entry('GE', '+995', 'Georgia'),
  entry('GH', '+233', 'Ghana'),
  entry('GI', '+350', 'Gibraltar'),
  entry('GR', '+30', 'Greece'),
  entry('GL', '+299', 'Greenland'),
  entry('GD', '+1', 'Grenada'),
  entry('GP', '+590', 'Guadeloupe'),
  entry('GU', '+1', 'Guam'),
  entry('GT', '+502', 'Guatemala'),
  entry('GG', '+44', 'Guernsey'),
  entry('GN', '+224', 'Guinea'),
  entry('GW', '+245', 'Guinea-Bissau'),
  entry('GY', '+592', 'Guyana'),
  entry('HT', '+509', 'Haiti'),
  entry('HN', '+504', 'Honduras'),
  entry('HK', '+852', 'Hong Kong'),
  entry('HU', '+36', 'Hungary'),
  entry('IS', '+354', 'Iceland'),
  entry('IN', '+91', 'India'),
  entry('ID', '+62', 'Indonesia'),
  entry('IR', '+98', 'Iran'),
  entry('IQ', '+964', 'Iraq'),
  entry('IM', '+44', 'Isle of Man'),
  entry('IL', '+972', 'Israel'),
  entry('JM', '+1', 'Jamaica'),
  entry('JP', '+81', 'Japan'),
  entry('JE', '+44', 'Jersey'),
  entry('JO', '+962', 'Jordan'),
  entry('KZ', '+7', 'Kazakhstan'),
  entry('KE', '+254', 'Kenya'),
  entry('KI', '+686', 'Kiribati'),
  entry('XK', '+383', 'Kosovo'),
  entry('KW', '+965', 'Kuwait'),
  entry('KG', '+996', 'Kyrgyzstan'),
  entry('LA', '+856', 'Laos'),
  entry('LV', '+371', 'Latvia'),
  entry('LB', '+961', 'Lebanon'),
  entry('LS', '+266', 'Lesotho'),
  entry('LR', '+231', 'Liberia'),
  entry('LY', '+218', 'Libya'),
  entry('LI', '+423', 'Liechtenstein'),
  entry('LT', '+370', 'Lithuania'),
  entry('LU', '+352', 'Luxembourg'),
  entry('MO', '+853', 'Macao'),
  entry('MG', '+261', 'Madagascar'),
  entry('MW', '+265', 'Malawi'),
  entry('MY', '+60', 'Malaysia'),
  entry('MV', '+960', 'Maldives'),
  entry('ML', '+223', 'Mali'),
  entry('MT', '+356', 'Malta'),
  entry('MH', '+692', 'Marshall Islands'),
  entry('MQ', '+596', 'Martinique'),
  entry('MR', '+222', 'Mauritania'),
  entry('MU', '+230', 'Mauritius'),
  entry('YT', '+262', 'Mayotte'),
  entry('MX', '+52', 'Mexico'),
  entry('FM', '+691', 'Micronesia'),
  entry('MD', '+373', 'Moldova'),
  entry('MC', '+377', 'Monaco'),
  entry('MN', '+976', 'Mongolia'),
  entry('ME', '+382', 'Montenegro'),
  entry('MS', '+1', 'Montserrat'),
  entry('MA', '+212', 'Morocco'),
  entry('MZ', '+258', 'Mozambique'),
  entry('MM', '+95', 'Myanmar (Burma)'),
  entry('NA', '+264', 'Namibia'),
  entry('NR', '+674', 'Nauru'),
  entry('NP', '+977', 'Nepal'),
  entry('NL', '+31', 'Netherlands'),
  entry('NC', '+687', 'New Caledonia'),
  entry('NZ', '+64', 'New Zealand'),
  entry('NI', '+505', 'Nicaragua'),
  entry('NE', '+227', 'Niger'),
  entry('NG', '+234', 'Nigeria'),
  entry('NU', '+683', 'Niue'),
  entry('NF', '+672', 'Norfolk Island'),
  entry('KP', '+850', 'North Korea'),
  entry('MK', '+389', 'North Macedonia'),
  entry('MP', '+1', 'Northern Mariana Islands'),
  entry('NO', '+47', 'Norway'),
  entry('OM', '+968', 'Oman'),
  entry('PK', '+92', 'Pakistan'),
  entry('PW', '+680', 'Palau'),
  entry('PS', '+970', 'Palestinian Territories'),
  entry('PA', '+507', 'Panama'),
  entry('PG', '+675', 'Papua New Guinea'),
  entry('PY', '+595', 'Paraguay'),
  entry('PE', '+51', 'Peru'),
  entry('PH', '+63', 'Philippines'),
  entry('PN', '+64', 'Pitcairn Islands'),
  entry('PL', '+48', 'Poland'),
  entry('PR', '+1', 'Puerto Rico'),
  entry('QA', '+974', 'Qatar'),
  entry('RE', '+262', 'Réunion'),
  entry('RO', '+40', 'Romania'),
  entry('RU', '+7', 'Russia'),
  entry('RW', '+250', 'Rwanda'),
  entry('WS', '+685', 'Samoa'),
  entry('SM', '+378', 'San Marino'),
  entry('ST', '+239', 'São Tomé & Príncipe'),
  entry('SA', '+966', 'Saudi Arabia'),
  entry('SN', '+221', 'Senegal'),
  entry('RS', '+381', 'Serbia'),
  entry('SC', '+248', 'Seychelles'),
  entry('SL', '+232', 'Sierra Leone'),
  entry('SG', '+65', 'Singapore'),
  entry('SX', '+1', 'Sint Maarten'),
  entry('SK', '+421', 'Slovakia'),
  entry('SI', '+386', 'Slovenia'),
  entry('SB', '+677', 'Solomon Islands'),
  entry('SO', '+252', 'Somalia'),
  entry('ZA', '+27', 'South Africa'),
  entry('GS', '+500', 'South Georgia & South Sandwich Islands'),
  entry('KR', '+82', 'South Korea'),
  entry('SS', '+211', 'South Sudan'),
  entry('LK', '+94', 'Sri Lanka'),
  entry('BL', '+590', 'St. Barthélemy'),
  entry('SH', '+290', 'St. Helena'),
  entry('KN', '+1', 'St. Kitts & Nevis'),
  entry('LC', '+1', 'St. Lucia'),
  entry('MF', '+590', 'St. Martin'),
  entry('PM', '+508', 'St. Pierre & Miquelon'),
  entry('VC', '+1', 'St. Vincent & Grenadines'),
  entry('SD', '+249', 'Sudan'),
  entry('SR', '+597', 'Suriname'),
  entry('SJ', '+47', 'Svalbard & Jan Mayen'),
  entry('SE', '+46', 'Sweden'),
  entry('CH', '+41', 'Switzerland'),
  entry('SY', '+963', 'Syria'),
  entry('TW', '+886', 'Taiwan'),
  entry('TJ', '+992', 'Tajikistan'),
  entry('TZ', '+255', 'Tanzania'),
  entry('TH', '+66', 'Thailand'),
  entry('TL', '+670', 'Timor-Leste'),
  entry('TG', '+228', 'Togo'),
  entry('TK', '+690', 'Tokelau'),
  entry('TO', '+676', 'Tonga'),
  entry('TT', '+1', 'Trinidad & Tobago'),
  entry('TN', '+216', 'Tunisia'),
  entry('TR', '+90', 'Türkiye'),
  entry('TM', '+993', 'Turkmenistan'),
  entry('TC', '+1', 'Turks & Caicos Islands'),
  entry('TV', '+688', 'Tuvalu'),
  entry('VI', '+1', 'U.S. Virgin Islands'),
  entry('UG', '+256', 'Uganda'),
  entry('UA', '+380', 'Ukraine'),
  entry('AE', '+971', 'United Arab Emirates'),
  entry('UY', '+598', 'Uruguay'),
  entry('UZ', '+998', 'Uzbekistan'),
  entry('VU', '+678', 'Vanuatu'),
  entry('VA', '+39', 'Vatican City'),
  entry('VE', '+58', 'Venezuela'),
  entry('VN', '+84', 'Vietnam'),
  entry('WF', '+681', 'Wallis & Futuna'),
  entry('EH', '+212', 'Western Sahara'),
  entry('YE', '+967', 'Yemen'),
  entry('ZM', '+260', 'Zambia'),
  entry('ZW', '+263', 'Zimbabwe'),
]

export const DIAL_CODE_OPTIONS: readonly DialCodeOption[] = [...LAUNCH_MARKET_CODES, ...WORLD_CODES]

/** ISO code → dial code lookup (first match wins for codes shared by two countries, e.g. US/CA). */
const DIAL_CODE_BY_ISO: ReadonlyMap<string, string> = new Map(
  DIAL_CODE_OPTIONS.map((d) => [d.code, d.dialCode]),
)

/**
 * Return the dial code for an ISO country code, or '' when the code
 * isn't in the registry (e.g. an uninhabited territory).
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
