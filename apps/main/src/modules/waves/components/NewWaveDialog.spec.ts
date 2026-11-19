/** PL-1.1 W3 — NewWaveDialog: validation, campaign list, payloads, 422 mapping, success. */
import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { VSelect } from 'vuetify/components'

vi.mock('../api/waves.api', () => ({
  wavesApi: {
    list: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    show: vi.fn(),
    campaignOptions: vi.fn(),
  },
}))

import { wavesApi } from '../api/waves.api'
import { AGENCY_ULID, BRAND_ULID, makeWave, mountWithApp } from '../waveTestKit'
import NewWaveDialog from './NewWaveDialog.vue'

const body = (sel: string): HTMLElement | null => document.body.querySelector(sel)
const CAMPAIGN = '01CAMPAIGNULIDXXXXXXXXXX'

function props(wave?: ReturnType<typeof makeWave>) {
  return {
    modelValue: true,
    agencyId: AGENCY_ULID,
    brandId: BRAND_ULID,
    brandName: 'Acme Corp',
    wave,
  }
}

async function setInput(selector: string, value: string): Promise<void> {
  const input = body(`${selector} input`) as HTMLInputElement
  input.value = value
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}

async function pickMarket(
  w: Awaited<ReturnType<typeof mountWithApp>>,
  code: string,
): Promise<void> {
  await (body('[data-test="market-add"]') as HTMLElement).click()
  await flushPromises()
  ;(body(`[data-test="market-option-${code}"]`) as HTMLElement).click()
  await flushPromises()
  void w
}

async function fillValid(w: Awaited<ReturnType<typeof mountWithApp>>): Promise<void> {
  await setInput('[data-test="wave-name"]', '  Spring push  ')
  await setInput('[data-test="wave-go-live"]', '2026-11-07')
  await pickMarket(w, 'ES')
}

async function chooseCampaign(choice: 'none' | 'existing' | 'new'): Promise<void> {
  const input = body(`[data-test="wave-choice-${choice}"] input`) as HTMLInputElement
  input.click()
  await flushPromises()
}

async function submit(): Promise<void> {
  ;(body('[data-test="wave-submit"]') as HTMLElement).click()
  await flushPromises()
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(wavesApi.campaignOptions).mockResolvedValue({
    data: [
      {
        id: CAMPAIGN,
        type: 'campaign_options',
        attributes: { name: 'Spring campaign', status: 'active' },
      },
      {
        id: '01DRAFTCAMPAIGNXXXXXXXXXX',
        type: 'campaign_options',
        attributes: { name: 'Autumn', status: 'draft' },
      },
    ],
  })
  vi.mocked(wavesApi.create).mockResolvedValue({ data: makeWave() })
})

// A failed test must not leave its teleported dialog behind for the next one.
afterEach(() => {
  document.body.innerHTML = ''
})

describe('NewWaveDialog (PL-1.1 W3)', () => {
  it('requires a name, a go-live date and at least one market, and sends nothing until they are there', async () => {
    const w = await mountWithApp(NewWaveDialog, props())
    await submit()
    expect(wavesApi.create).not.toHaveBeenCalled()
    expect(document.body.textContent).toContain('Enter a wave name.')
    expect(document.body.textContent).toContain('Choose a go-live date.')
    expect(document.body.textContent).toContain('Add at least one market.')
    w.unmount()
  })

  it("loads this brand's campaign options and shows each one with its status", async () => {
    const w = await mountWithApp(NewWaveDialog, props())
    expect(wavesApi.campaignOptions).toHaveBeenCalledWith(AGENCY_ULID, BRAND_ULID)
    await chooseCampaign('existing')
    const select = w.findComponent(VSelect)
    const titles = (select.props('items') as { title: string; value: string | null }[]).map(
      (i) => i.title,
    )
    expect(titles).toEqual(['Spring campaign · Active', 'Autumn · Draft'])
    w.unmount()
  })

  it('"No campaign" sends campaign_id null, and the name is trimmed', async () => {
    const w = await mountWithApp(NewWaveDialog, props())
    await fillValid(w)
    await submit()
    expect(wavesApi.create).toHaveBeenCalledWith(AGENCY_ULID, BRAND_ULID, {
      name: 'Spring push',
      go_live_on: '2026-11-07',
      markets: ['ES'],
      campaign_id: null,
    })
    w.unmount()
  })

  it('a chosen campaign sends its ulid', async () => {
    const w = await mountWithApp(NewWaveDialog, props())
    await fillValid(w)
    await chooseCampaign('existing')
    w.findComponent(VSelect).vm.$emit('update:modelValue', CAMPAIGN)
    await flushPromises()
    await submit()
    expect(vi.mocked(wavesApi.create).mock.calls[0]?.[2]).toMatchObject({ campaign_id: CAMPAIGN })
    w.unmount()
  })

  it.each([
    ['wave.campaign_not_available', 'That campaign cannot be linked to a wave of this brand.'],
    ['wave.campaign_already_linked', 'That campaign already has a wave.'],
    ['wave.brand_archived', 'This brand is archived. Restore it to change its waves.'],
    ['something.else', 'The wave could not be saved. Try again.'],
  ])('maps a 422 %s to its message and keeps the dialog open', async (code, message) => {
    vi.mocked(wavesApi.create).mockRejectedValue(
      new ApiError({ status: 422, code, message: 'x', details: [], raw: null } as never),
    )
    const w = await mountWithApp(NewWaveDialog, props())
    await fillValid(w)
    await submit()
    expect(body('[data-test="wave-form-error"]')?.textContent?.trim()).toBe(message)
    expect(w.emitted('update:modelValue')).toBeUndefined()
    w.unmount()
  })

  it('success emits saved with the wave and closes', async () => {
    const w = await mountWithApp(NewWaveDialog, props())
    await fillValid(w)
    await submit()
    expect(w.emitted('saved')?.[0]).toEqual([makeWave()])
    expect(w.emitted('update:modelValue')?.at(-1)).toEqual([false])
    w.unmount()
  })

  it('edit mode: an unlinked wave can have a campaign set; only changed fields are sent', async () => {
    vi.mocked(wavesApi.update).mockResolvedValue({ data: makeWave({}, true) })
    const w = await mountWithApp(NewWaveDialog, props(makeWave()))
    expect(body('[data-test="wave-campaign-locked"]')).toBeNull()
    w.findComponent(VSelect).vm.$emit('update:modelValue', CAMPAIGN)
    await flushPromises()
    await submit()
    expect(wavesApi.update).toHaveBeenCalledWith(AGENCY_ULID, BRAND_ULID, makeWave().id, {
      campaign_id: CAMPAIGN,
    })
    w.unmount()
  })

  it('edit mode: a linked wave shows a read-only campaign name and never sends campaign_id', async () => {
    vi.mocked(wavesApi.update).mockResolvedValue({ data: makeWave({ name: 'Renamed' }, true) })
    const w = await mountWithApp(NewWaveDialog, props(makeWave({}, true)))
    expect(body('[data-test="wave-campaign-locked"]')).not.toBeNull()
    expect(w.findComponent(VSelect).exists()).toBe(false)
    expect(wavesApi.campaignOptions).not.toHaveBeenCalled()
    await setInput('[data-test="wave-name"]', 'Renamed')
    await submit()
    expect(vi.mocked(wavesApi.update).mock.calls[0]?.[3]).toEqual({ name: 'Renamed' })
    w.unmount()
  })

  it('edit mode maps wave.campaign_link_locked', async () => {
    vi.mocked(wavesApi.update).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'wave.campaign_link_locked',
        message: 'x',
        details: [],
        raw: null,
      } as never),
    )
    const w = await mountWithApp(NewWaveDialog, props(makeWave({}, true)))
    await setInput('[data-test="wave-name"]', 'Renamed')
    await submit()
    expect(body('[data-test="wave-form-error"]')?.textContent).toContain(
      'Selected or further on this campaign',
    )
    w.unmount()
  })

  // ── PL-1.2 W4 ────────────────────────────────────────────────────────────────────────────────────────
  it('W4: offers three choices, and "create now" sends new_campaign and no campaign_id', async () => {
    const w = await mountWithApp(NewWaveDialog, props())
    for (const c of ['none', 'existing', 'new']) {
      expect(body(`[data-test="wave-choice-${c}"]`), c).not.toBeNull()
    }
    await fillValid(w)
    await chooseCampaign('new')
    await setInput('[data-test="wave-new-campaign-name"]', '  Spring for wave  ')
    await submit()
    const sent = vi.mocked(wavesApi.create).mock.calls[0]?.[2] as unknown as Record<string, unknown>
    expect(sent.new_campaign).toEqual({ name: 'Spring for wave' })
    expect('campaign_id' in sent).toBe(false)
    w.unmount()
  })

  it('W4: the new campaign needs a name, and nothing is sent without it', async () => {
    const w = await mountWithApp(NewWaveDialog, props())
    await fillValid(w)
    await chooseCampaign('new')
    await submit()
    expect(wavesApi.create).not.toHaveBeenCalled()
    expect(body('[data-test="wave-dialog"]')?.textContent ?? document.body.textContent).toContain(
      'Enter a campaign name.',
    )
    w.unmount()
  })

  it('W4: an unlocked link can be removed on edit (campaign_id null), a locked one is read-only', async () => {
    vi.mocked(wavesApi.update).mockResolvedValue({ data: makeWave() })
    const open = await mountWithApp(NewWaveDialog, props(makeWave({ link_locked: false }, true)))
    expect(body('[data-test="wave-campaign"]')).not.toBeNull()
    open.findComponent(VSelect).vm.$emit('update:modelValue', null)
    await flushPromises()
    await submit()
    expect(vi.mocked(wavesApi.update).mock.calls[0]?.[3]).toEqual({ campaign_id: null })
    open.unmount()
    document.body.innerHTML = ''

    const locked = await mountWithApp(NewWaveDialog, props(makeWave({ link_locked: true }, true)))
    expect(body('[data-test="wave-campaign"]')).toBeNull()
    expect(body('[data-test="wave-campaign-locked"]')).not.toBeNull()
    expect(document.body.textContent).toContain('Selected or further')
    locked.unmount()
  })

  // ── PL-2.1 ────────────────────────────────────────────────────────────────────────────────────────────
  it('PL-2.1: wave.market_has_personas names the markets and their persona counts', async () => {
    vi.mocked(wavesApi.update).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'wave.market_has_personas',
        message: 'x',
        details: [
          {
            code: 'wave.market_has_personas',
            meta: {
              markets: [
                { market: 'SK', persona_count: 2 },
                { market: 'RO', persona_count: 1 },
              ],
            },
          },
        ],
        raw: null,
      } as never),
    )
    const w = await mountWithApp(NewWaveDialog, props(makeWave()))
    await setInput('[data-test="wave-name"]', 'Renamed')
    await submit()
    expect(body('[data-test="wave-form-error"]')?.textContent).toContain(
      'Remove the personas of these markets first: SK (2), RO (1).',
    )
    w.unmount()
  })

  it('PL-2.1: a wave.market_has_personas without the meta falls back to a plain message, never a crash', async () => {
    vi.mocked(wavesApi.update).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'wave.market_has_personas',
        message: 'x',
        details: [{ code: 'wave.market_has_personas', meta: { markets: 'nope' } }],
        raw: null,
      } as never),
    )
    const w = await mountWithApp(NewWaveDialog, props(makeWave()))
    await setInput('[data-test="wave-name"]', 'Renamed')
    await submit()
    expect(body('[data-test="wave-form-error"]')).not.toBeNull()
    expect(body('[data-test="wave-form-error"]')?.textContent).not.toContain('undefined')
    w.unmount()
  })
})
