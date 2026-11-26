/**
 * PL-3.2b — the language control of the script head: nothing at all for an English-only market, one item per language plus
 * "Side by side", and a select for the partner of English when the market has several local languages.
 */
import { describe, expect, it } from 'vitest'

import type { LanguageView } from '../scriptsLogic'
import { mountStrategy } from '../strategyTestKit'
import ScriptLanguageToggle from './ScriptLanguageToggle.vue'

const mountToggle = (languages: string[], view: LanguageView) =>
  mountStrategy(ScriptLanguageToggle, { languages, view })

describe('ScriptLanguageToggle (PL-3.2b)', () => {
  it('an English-only market draws nothing: no toggle, no empty element', async () => {
    const w = await mountToggle(['en'], { mode: 'single', language: 'en' })
    expect(w.find('[data-test="script-language-toggle"]').exists()).toBe(false)
    expect(w.text()).toBe('')
  })

  it('shows EN, SK and "Side by side"; the selected item says so; clicking emits the new view', async () => {
    const w = await mountToggle(['en', 'sk'], { mode: 'side', local: 'sk' })
    expect(w.get('[data-test="script-language-en"]').text()).toBe('EN')
    expect(w.get('[data-test="script-language-sk"]').text()).toBe('SK')
    expect(w.get('[data-test="script-language-side"]').attributes('aria-pressed')).toBe('true')
    expect(w.get('[data-test="script-language-sk"]').attributes('aria-pressed')).toBe('false')
    // one local language: no select
    expect(w.find('[data-test="script-language-with"]').exists()).toBe(false)

    await w.get('[data-test="script-language-sk"]').trigger('click')
    expect(w.emitted('update:view')?.[0]).toEqual([{ mode: 'single', language: 'sk' }])
    await w.get('[data-test="script-language-en"]').trigger('click')
    expect(w.emitted('update:view')?.[1]).toEqual([{ mode: 'single', language: 'en' }])
  })

  it('"Side by side" from a single view pairs English with the first local language', async () => {
    const w = await mountToggle(['en', 'sk', 'hu'], { mode: 'single', language: 'en' })
    await w.get('[data-test="script-language-side"]').trigger('click')
    expect(w.emitted('update:view')?.[0]).toEqual([{ mode: 'side', local: 'sk' }])
  })

  it('with several local languages the select picks the partner, only while side by side', async () => {
    const single = await mountToggle(['en', 'sk', 'hu'], { mode: 'single', language: 'en' })
    expect(single.find('[data-test="script-language-with"]').exists()).toBe(false)

    const w = await mountToggle(['en', 'sk', 'hu'], { mode: 'side', local: 'sk' })
    const select = w.get('[data-test="script-language-with"]')
    expect((select.element as HTMLSelectElement).value).toBe('sk')
    await select.setValue('hu')
    expect(w.emitted('update:view')?.[0]).toEqual([{ mode: 'side', local: 'hu' }])
  })
})
