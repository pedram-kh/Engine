/**
 * PL-3.2b — the script languages dialog: English is always there and cannot be removed, removing says the text is kept and
 * hidden, Save waits for the unsaved text to be saved first, and sends the local languages in order.
 */
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/strategy.api', () => ({ strategyApi: { setScriptLanguages: vi.fn() } }))

import { strategyApi } from '../api/strategy.api'
import { mountStrategy } from '../strategyTestKit'
import ScriptLanguagesDialog from './ScriptLanguagesDialog.vue'

const SCOPE = { agencyId: 'A', brandId: 'B', waveId: 'W' } as never
const CURRENT = {
  market: 'M',
  max_local: 3,
  languages: [{ code: 'sk', position: 1, scripts: 2, texts: 5 }],
}
const q = (s: string) => document.body.querySelector<HTMLElement>(s)

async function mountDialog(over: Record<string, unknown> = {}) {
  const w = await mountStrategy(
    ScriptLanguagesDialog,
    {
      modelValue: false,
      scope: SCOPE,
      market: 'M',
      marketName: 'Slovakia',
      current: CURRENT,
      beforeSave: () => Promise.resolve(true),
      ...over,
    },
    {},
  )
  // the list is read when the dialog opens
  await w.setProps({ modelValue: true })
  await flushPromises()
  return w
}

describe('ScriptLanguagesDialog (PL-3.2b)', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('lists English (always on, no Remove) and the local languages with a Remove each', async () => {
    await mountDialog()
    expect(q('[data-test="script-languages-en"]')).not.toBeNull()
    expect(q('[data-test="script-languages-remove-en"]')).toBeNull()
    expect(q('[data-test="script-languages-row-sk"]')).not.toBeNull()
    expect(q('[data-test="script-languages-remove-sk"]')).not.toBeNull()
    expect(q('[data-test="script-languages-keep"]')?.textContent).toContain('kept')
  })

  it('nothing changed: Save does nothing; removing Slovak says its text is hidden, not deleted, and Save sends no languages', async () => {
    const w = await mountDialog()
    ;(q('[data-test="script-languages-save"]') as HTMLButtonElement).click()
    await flushPromises()
    expect(strategyApi.setScriptLanguages).not.toHaveBeenCalled()
    ;(q('[data-test="script-languages-remove-sk"]') as HTMLButtonElement).click()
    await flushPromises()
    expect(q('[data-test="script-languages-removing"]')).not.toBeNull()
    vi.mocked(strategyApi.setScriptLanguages).mockResolvedValue({
      data: { market: 'M', max_local: 3, languages: [] },
    })
    ;(q('[data-test="script-languages-save"]') as HTMLButtonElement).click()
    await flushPromises()
    expect(strategyApi.setScriptLanguages).toHaveBeenCalledWith(SCOPE, 'M', [])
    expect(w.emitted('saved')?.[0]?.[0]).toEqual({ market: 'M', max_local: 3, languages: [] })
    expect(w.emitted('update:modelValue')?.at(-1)).toEqual([false])
  })

  it('if the unsaved text cannot be saved first, nothing is sent and the person is told', async () => {
    await mountDialog({ beforeSave: () => Promise.resolve(false) })
    ;(q('[data-test="script-languages-remove-sk"]') as HTMLButtonElement).click()
    await flushPromises()
    ;(q('[data-test="script-languages-save"]') as HTMLButtonElement).click()
    await flushPromises()
    expect(strategyApi.setScriptLanguages).not.toHaveBeenCalled()
    expect(q('[data-test="script-languages-error"]')).not.toBeNull()
  })

  it('Cancel closes without sending', async () => {
    const w = await mountDialog()
    ;(q('[data-test="script-languages-cancel"]') as HTMLButtonElement).click()
    await flushPromises()
    expect(w.emitted('update:modelValue')?.at(-1)).toEqual([false])
    expect(strategyApi.setScriptLanguages).not.toHaveBeenCalled()
  })

  it('at the limit of local languages the add control says the market is full', async () => {
    await mountDialog({
      current: {
        market: 'M',
        max_local: 1,
        languages: [{ code: 'sk', position: 1, scripts: 0, texts: 0 }],
      },
    })
    expect(q('[data-test="script-languages-full"]')).not.toBeNull()
    expect(q('[data-test="script-languages-add"]')).toBeNull()
  })
})
