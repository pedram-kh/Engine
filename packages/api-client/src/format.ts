/**
 * Locale-aware formatting utilities for currency amounts and dates.
 *
 * All three functions accept an explicit `locale` string (BCP 47, e.g. `'en'`,
 * `'de'`, `'pt'`) so they are pure and testable independently of any Vue or
 * Pinia context. In Vue components, pass `locale.value` (script) or `locale`
 * (template, where refs are auto-unwrapped).
 *
 * Placement in `@catalyst/api-client` keeps the logic framework-agnostic and
 * lets it be consumed by both SPAs and any future non-Vue context (e.g. SSR,
 * Node scripts) without a Vue dependency.
 */

/**
 * Format a minor-unit monetary amount (e.g. cents) as a locale-aware currency
 * string using `Intl.NumberFormat`.
 *
 * - Returns `fallback` (default `'—'`) when `minorAmount` is `null`.
 * - When `currency` is absent/null, falls back to a plain 2-decimal number so
 *   the display degrades gracefully rather than throwing.
 * - Uses `style: 'currency'` (with correct symbol placement and grouping) when
 *   a valid ISO 4217 currency code is provided.
 *
 * @example
 * formatCurrency(1234, 'EUR', 'de')   // '12,34 €'
 * formatCurrency(1234, 'EUR', 'en')   // '€12.34'
 * formatCurrency(1234, null, 'en')    // '12.34'
 * formatCurrency(null, 'EUR', 'en')   // '—'
 */
export function formatCurrency(
  minorAmount: number | null,
  currency: string | null | undefined,
  locale: string,
  fallback = '—',
): string {
  if (minorAmount === null) return fallback
  const major = minorAmount / 100
  if (!currency) {
    return new Intl.NumberFormat(locale, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(major)
  }
  try {
    return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(major)
  } catch {
    // Invalid ISO 4217 code — degrade to plain number + code so the UI never breaks.
    return `${new Intl.NumberFormat(locale, { minimumFractionDigits: 2 }).format(major)} ${currency}`
  }
}

/**
 * Format an ISO 8601 date string (or `Date` object) as a locale-aware date
 * using `Intl.DateTimeFormat`.
 *
 * Accepts `Date | string` so callers that already hold a `Date` object
 * (e.g. after guard checks) do not need to call `.toISOString()`.
 *
 * Defaults to `{ dateStyle: 'medium' }` — override via `opts` for custom
 * weekday/month layouts (e.g. the WelcomeBar full-date variant).
 *
 * @example
 * formatDate('2024-03-15', 'en')                           // 'Mar 15, 2024'
 * formatDate('2024-03-15', 'de')                           // '15. März 2024'
 * formatDate(new Date(), 'fr', { weekday: 'long', ... })  // 'lundi 15 avril …'
 */
export function formatDate(
  date: Date | string,
  locale: string,
  opts: Intl.DateTimeFormatOptions = { dateStyle: 'medium' },
): string {
  const d = typeof date === 'string' ? new Date(date) : date
  return new Intl.DateTimeFormat(locale, opts).format(d)
}

/**
 * Format an ISO 8601 date-time string (or `Date` object) as a locale-aware
 * date+time using `Intl.DateTimeFormat`.
 *
 * Returns `fallback` (default `'—'`) when the value is `null`, matching the
 * consistent placeholder used across the UI for missing timestamps.
 *
 * Defaults to `{ dateStyle: 'medium', timeStyle: 'short' }`.
 *
 * @example
 * formatDateTime('2024-03-15T10:30:00Z', 'en')  // 'Mar 15, 2024, 10:30 AM'
 * formatDateTime(null, 'en')                      // '—'
 */
export function formatDateTime(
  date: Date | string | null,
  locale: string,
  opts: Intl.DateTimeFormatOptions = { dateStyle: 'medium', timeStyle: 'short' },
  fallback = '—',
): string {
  if (date === null) return fallback
  const d = typeof date === 'string' ? new Date(date) : date
  return new Intl.DateTimeFormat(locale, opts).format(d)
}
