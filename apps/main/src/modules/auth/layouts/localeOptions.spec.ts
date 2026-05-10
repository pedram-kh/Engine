import { describe, expect, it } from 'vitest'

import { buildLocaleOptions } from './localeOptions'

describe('buildLocaleOptions', () => {
  it('maps each locale to a {value, title} pair', () => {
    const t = (k: string): string => `T:${k}`
    expect(buildLocaleOptions(['en', 'pt', 'it'], t)).toEqual([
      { value: 'en', title: 'T:app.locale.en' },
      { value: 'pt', title: 'T:app.locale.pt' },
      { value: 'it', title: 'T:app.locale.it' },
    ])
  })

  it('returns an empty list when no locales are provided', () => {
    expect(buildLocaleOptions([], () => 'unused')).toEqual([])
  })
})
