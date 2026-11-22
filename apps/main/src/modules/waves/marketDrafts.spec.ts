/** PL-2.4 — the market builder's rules: the code suggestion, the name and code that follow, and the errors. */
import { describe, expect, it } from 'vitest'

import {
  codeChanged,
  draftsFromWave,
  emptyDraft,
  ownerOf,
  refreshDraft,
  suggestMarketCode,
  toInput,
  validateDrafts,
} from './marketDrafts'

describe('suggestMarketCode', () => {
  it.each([
    ['Slovakia', ['SK'], 'SK'],
    ['UK & Ireland', ['GB', 'IE'], 'UKI'],
    ['North America', ['US', 'CA'], 'NA'],
    ['Nordics', ['SE', 'NO'], 'NOR'],
    ['Česko a Slovensko', ['CZ', 'SK'], 'CAS'],
    ['DACH', ['DE', 'AT', 'CH'], 'DAC'],
  ])('%s gives %s', (name, countries, expected) => {
    expect(suggestMarketCode(name, countries).length).toBeGreaterThanOrEqual(2)
    expect(suggestMarketCode(name, countries)).toBe(expected)
  })

  it('makes it unique and never longer than six', () => {
    expect(suggestMarketCode('Nordics', ['SE', 'NO'], ['NOR'])).toBe('NOR2')
    expect(
      suggestMarketCode('Very Long Market Name Indeed Really', ['SE', 'NO']).length,
    ).toBeLessThanOrEqual(6)
  })

  it('never makes a code longer than six, whatever the name', () => {
    expect(suggestMarketCode('A B C D E F G H I J', ['SE', 'NO'])).toHaveLength(6)
    expect(suggestMarketCode('Alpha', ['SE', 'NO'], ['ALP', 'ALP2'])).toBe('ALP3')
  })

  it('handles a name with no letters', () => {
    expect(suggestMarketCode('!!!', ['SE', 'NO'])).toMatch(/^[A-Z0-9]{2,6}$/)
  })
})

describe('refreshDraft', () => {
  it('fills the name and code from one country, until the user types', () => {
    const d = emptyDraft()
    d.countries = ['SK']
    refreshDraft([d], 0)
    expect([d.name, d.code]).toEqual(['Slovakia', 'SK'])

    d.nameTouched = true
    d.name = 'Slovak home'
    d.countries = ['SK', 'CZ']
    refreshDraft([d], 0)
    expect(d.name).toBe('Slovak home')
    expect(d.code).toBe('SH')
  })

  it('leaves a typed code alone and avoids a code taken by another market', () => {
    const a = emptyDraft()
    a.countries = ['SK']
    refreshDraft([a], 0)
    const b = emptyDraft()
    b.name = 'Sk'
    b.nameTouched = true
    b.countries = ['SK']
    refreshDraft([a, b], 1)
    expect(b.code).not.toBe('SK')
    b.codeTouched = true
    b.code = 'ZZ'
    refreshDraft([a, b], 1)
    expect(b.code).toBe('ZZ')
  })
})

describe('validateDrafts', () => {
  it('flags empty name, no countries, bad and duplicate codes, duplicate names ignoring case and spacing', () => {
    const a = emptyDraft()
    const b = emptyDraft()
    const c = emptyDraft()
    Object.assign(a, { name: 'Nordics', code: 'NOR', countries: ['SE'] })
    Object.assign(b, { name: ' nordics ', code: 'NOR', countries: ['NO'] })
    Object.assign(c, { name: '', code: 'x', countries: [] })
    const errors = validateDrafts([a, b, c])
    expect(errors[a.key]).toEqual({ name: 'taken', code: 'taken' })
    expect(errors[b.key]).toEqual({ name: 'taken', code: 'taken' })
    expect(errors[c.key]).toEqual({ name: 'required', code: 'invalid', countries: 'required' })
  })

  it('flags a name over sixty characters and passes a good list', () => {
    const a = emptyDraft()
    Object.assign(a, { name: 'x'.repeat(61), code: 'AB', countries: ['SE'] })
    expect(validateDrafts([a])[a.key]).toEqual({ name: 'tooLong' })
    a.name = 'Fine'
    expect(validateDrafts([a])).toEqual({})
  })
})

describe('helpers', () => {
  it('ownerOf finds the other market holding a country', () => {
    const a = emptyDraft()
    const b = emptyDraft()
    a.countries = ['GB']
    b.countries = ['IE']
    expect(ownerOf([a, b], 'GB', b.key)).toBe(a)
    expect(ownerOf([a, b], 'GB', a.key)).toBeUndefined()
  })

  it('toInput keeps the id of an existing market, trims the name and omits an empty code', () => {
    const [d] = draftsFromWave([
      { id: '01ABC', name: 'UK', code: 'UK', countries: ['GB'] } as never,
    ])
    expect(toInput(d!)).toEqual({ id: '01ABC', name: 'UK', code: 'UK', countries: ['GB'] })
    const n = emptyDraft()
    n.name = '  A   B '
    n.countries = ['SE']
    expect(toInput(n)).toEqual({ name: 'A B', countries: ['SE'] })
  })
})

describe('A1: persona codes follow a changed short code', () => {
  const wave = [{ id: '01ABC', name: 'UK', code: 'UK', countries: ['GB'] }] as never

  it('is not offered until an existing market has a different, valid code', () => {
    const [d] = draftsFromWave(wave)
    expect(codeChanged(d!)).toBe(false)
    d!.code = 'UKX'
    expect(codeChanged(d!)).toBe(true)
    d!.code = 'U'
    expect(codeChanged(d!)).toBe(false)
    expect(codeChanged(emptyDraft())).toBe(false)
  })

  it('goes in the body only when ticked and the code differs from the saved one', () => {
    const [d] = draftsFromWave(wave)
    d!.code = 'UKX'
    expect(toInput(d!)).not.toHaveProperty('rename_persona_codes')
    d!.renamePersonaCodes = true
    expect(toInput(d!)).toEqual({
      id: '01ABC',
      name: 'UK',
      code: 'UKX',
      countries: ['GB'],
      rename_persona_codes: true,
    })
    d!.code = 'UK'
    expect(toInput(d!)).not.toHaveProperty('rename_persona_codes')
  })

  it('is never sent for a market that does not exist yet', () => {
    const n = emptyDraft()
    Object.assign(n, { name: 'New', code: 'NEW', countries: ['SE'], renamePersonaCodes: true })
    expect(toInput(n)).not.toHaveProperty('rename_persona_codes')
  })
})
