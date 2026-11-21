/**
 * PL-2.4 — the market builder's state and rules, kept apart from the component so they can be tested on their own.
 *
 * A draft is one market as the user is editing it. An existing market keeps its `id` (the ULID the server knows it by);
 * a new one has none. The name of a market the user has not typed in is kept in step with its countries (one country: the
 * country's name), and its short code is kept in step with the name until the user edits it. The suggestion is the same
 * rule as the server's (`WaveMarketService::suggestCode`), so what the user sees is what the server would have derived.
 */
import { labelForCountryCode } from '@catalyst/api-client'
import type { WaveMarket, WaveMarketInput } from '@catalyst/api-client'

export const MAX_COUNTRIES = 40
export const MAX_NAME = 60
export const CODE_PATTERN = /^[A-Z0-9]{2,6}$/

export interface MarketDraft {
  /** Local key for the list; not sent. */
  key: string
  /** The market's ULID, for a market that exists. */
  id?: string
  name: string
  code: string
  countries: string[]
  /** The user typed the name: it no longer follows the countries. */
  nameTouched: boolean
  /** The user typed the code (or the market already has one): it no longer follows the name. */
  codeTouched: boolean
}

export interface MarketDraftErrors {
  name?: 'required' | 'tooLong' | 'taken'
  code?: 'invalid' | 'taken'
  countries?: 'required'
}

let counter = 0
function nextKey(): string {
  counter += 1
  return `d${counter}`
}

export function draftsFromWave(markets: WaveMarket[]): MarketDraft[] {
  return markets.map((m) => ({
    key: nextKey(),
    id: m.id,
    name: m.name,
    code: m.code,
    countries: [...m.countries],
    nameTouched: true,
    codeTouched: true,
  }))
}

export function emptyDraft(): MarketDraft {
  return {
    key: nextKey(),
    name: '',
    code: '',
    countries: [],
    nameTouched: false,
    codeTouched: false,
  }
}

/** What goes in a create or edit body. An empty code is left out so the server derives one. */
export function toInput(d: MarketDraft): WaveMarketInput {
  return {
    ...(d.id === undefined ? {} : { id: d.id }),
    name: d.name.trim().replace(/\s+/g, ' '),
    ...(d.code === '' ? {} : { code: d.code }),
    countries: d.countries,
  }
}

function codeFromName(name: string): string {
  const ascii = name.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
  const words = ascii.split(/[^A-Za-z0-9]+/).filter((w) => w !== '' && w.toLowerCase() !== 'and')
  if (words.length === 0) return ''
  if (words.length === 1) return (words[0] ?? '').slice(0, 3).toUpperCase()
  let code = ''
  for (const w of words) {
    code +=
      w.length <= 3 && w === w.toUpperCase() && /[A-Z]/.test(w) ? w : (w[0] ?? '').toUpperCase()
  }
  return code.toUpperCase().slice(0, 6)
}

/**
 * The short code suggested for a market: one country gives the country code, otherwise it is built from the name
 * ("UK & Ireland" gives UKI, "North America" NA, "Nordics" NOR); never shorter than two characters, at most six, and
 * made unique against `taken` with a numeric suffix.
 */
export function suggestMarketCode(name: string, countries: string[], taken: string[] = []): string {
  let base = countries.length === 1 ? (countries[0] ?? '') : codeFromName(name)
  if (base.length < 2) base = (base + countries.join('') + 'XX').slice(0, 3).toUpperCase()
  base = base.slice(0, 6)
  let code = base
  for (let n = 2; taken.includes(code); n += 1) {
    const suffix = String(n)
    code = base.slice(0, 6 - suffix.length) + suffix
  }
  return code
}

/** Bring an untouched name and an untouched code in step with the countries and the name. */
export function refreshDraft(drafts: MarketDraft[], index: number): void {
  const d = drafts[index]
  if (d === undefined) return
  if (!d.nameTouched) {
    d.name = d.countries.length === 1 ? labelForCountryCode(d.countries[0] ?? '') : d.name
    if (d.countries.length === 0) d.name = ''
  }
  if (!d.codeTouched) {
    const taken = drafts.filter((_, i) => i !== index).map((o) => o.code)
    d.code =
      d.name.trim() === '' && d.countries.length === 0
        ? ''
        : suggestMarketCode(d.name, d.countries, taken)
  }
}

/** The market of this wave that already holds the country (other than `except`), or undefined. */
export function ownerOf(
  drafts: MarketDraft[],
  country: string,
  except: string,
): MarketDraft | undefined {
  return drafts.find((d) => d.key !== except && d.countries.includes(country))
}

/** Per-market errors. Empty when the list can be saved. Names compare ignoring case and spacing, as the server does. */
export function validateDrafts(drafts: MarketDraft[]): Record<string, MarketDraftErrors> {
  const out: Record<string, MarketDraftErrors> = {}
  const names = new Map<string, number>()
  const codes = new Map<string, number>()
  for (const d of drafts) {
    const n = d.name.trim().replace(/\s+/g, ' ').toLowerCase()
    names.set(n, (names.get(n) ?? 0) + 1)
    codes.set(d.code, (codes.get(d.code) ?? 0) + 1)
  }
  for (const d of drafts) {
    const e: MarketDraftErrors = {}
    const clean = d.name.trim().replace(/\s+/g, ' ')
    if (clean === '') e.name = 'required'
    else if ([...clean].length > MAX_NAME) e.name = 'tooLong'
    else if ((names.get(clean.toLowerCase()) ?? 0) > 1) e.name = 'taken'
    if (d.code !== '' && !CODE_PATTERN.test(d.code)) e.code = 'invalid'
    else if (d.code !== '' && (codes.get(d.code) ?? 0) > 1) e.code = 'taken'
    if (d.countries.length === 0) e.countries = 'required'
    if (Object.keys(e).length > 0) out[d.key] = e
  }
  return out
}
