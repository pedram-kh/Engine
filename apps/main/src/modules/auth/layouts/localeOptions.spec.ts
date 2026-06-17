import { describe, expect, it } from 'vitest'

import { UI_LOCALES, languageEndonym } from '@catalyst/api-client'

import { buildLocaleOptions } from './localeOptions'

describe('buildLocaleOptions', () => {
  it('offers every rendered UI locale, labelled by endonym', () => {
    expect(buildLocaleOptions()).toEqual(
      UI_LOCALES.map((code) => ({ value: code, title: languageEndonym(code) })),
    )
  })

  it('is sourced from the registry, not vue-i18n loaded locales', () => {
    expect(buildLocaleOptions()).toHaveLength(UI_LOCALES.length)
  })
})
