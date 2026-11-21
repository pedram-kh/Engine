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

/** Pick a country for the market in card `index` (the create dialog opens with one empty card). */
async function pickCountry(
  w: Awaited<ReturnType<typeof mountWithApp>>,
  index: number,
  code: string,
): Promise<void> {
  const card = body(`[data-test="market-card-${index}"]`) as HTMLElement
  ;(card.querySelector('[data-test="market-add-country"]') as HTMLElement).click()
  await flushPromises()
  // A menu that was closed stays in the DOM, hidden: the one just opened is the last.
  const options = document.body.querySelectorAll(`[data-test="market-option-${code}"]`)
  ;(options[options.length - 1] as HTMLElement).click()
  await flushPromises()
  void w
}

async function addMarketCard(): Promise<void> {
  ;(body('[data-test="market-add"]') as HTMLElement).click()
  await flushPromises()
}

async function fillValid(w: Awaited<ReturnType<typeof mountWithApp>>): Promise<void> {
  await setInput('[data-test="wave-name"]', '  Spring push  ')
  await setInput('[data-test="wave-go-live"]', '2026-11-07')
  await pickCountry(w, 0, 'ES')
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
    // The create dialog opens with one empty market: it needs a name and a country.
    expect(document.body.textContent).toContain('Give the market a name.')
    expect(document.body.textContent).toContain('Add at least one country.')
    expect(document.body.textContent).toContain('Fix the market details marked above.')
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
      markets: [{ name: 'Spain', code: 'ES', countries: ['ES'] }],
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
                { market: '01JSK', name: 'Slovakia', code: 'SK', persona_count: 2 },
                { market: '01JRO', name: 'Romania', code: 'RO', persona_count: 1 },
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
      'Remove the personas of these markets first: Slovakia (2), Romania (1).',
    )
    w.unmount()
  })

  // ── PL-2.4 ────────────────────────────────────────────────────────────────────────────────────────────
  it('PL-2.4: a market that is already there goes back as it is (ulid, name, code, countries), a new country as a one-country market, with the version', async () => {
    vi.mocked(wavesApi.update).mockResolvedValue({ data: makeWave() })
    const wave = makeWave()
    const w = await mountWithApp(NewWaveDialog, props(wave))
    await addMarketCard()
    await pickCountry(w, 2, 'FR')
    await submit()
    expect(wavesApi.update).toHaveBeenCalledWith(AGENCY_ULID, BRAND_ULID, wave.id, {
      markets: [...wave.attributes.markets, { name: 'France', code: 'FR', countries: ['FR'] }],
      markets_version: wave.attributes.markets_version,
    })
    w.unmount()
  })

  it('PL-2.4: an edit that leaves the markets alone sends neither markets nor the version', async () => {
    vi.mocked(wavesApi.update).mockResolvedValue({ data: makeWave({ name: 'Renamed' }) })
    const w = await mountWithApp(NewWaveDialog, props(makeWave()))
    await setInput('[data-test="wave-name"]', 'Renamed')
    await submit()
    expect(wavesApi.update).toHaveBeenCalledWith(AGENCY_ULID, BRAND_ULID, makeWave().id, {
      name: 'Renamed',
    })
    w.unmount()
  })

  it('PL-2.4: the refusal names the markets by their names', async () => {
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
                {
                  market: '01JWMARKETULID000000000000',
                  name: 'Slovakia',
                  code: 'SK',
                  persona_count: 2,
                },
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
    expect(body('[data-test="wave-form-error"]')?.textContent).toContain('Slovakia (2)')
    w.unmount()
  })

  it('PL-2.4 (G5): 409 wave.markets_changed says so, offers a reload, and the reload brings in the current markets', async () => {
    vi.mocked(wavesApi.update).mockRejectedValue(
      new ApiError({
        status: 409,
        code: 'wave.markets_changed',
        message: 'x',
        details: [{ code: 'wave.markets_changed' }],
        raw: null,
      } as never),
    )
    const fresh = makeWave({
      markets: [
        { id: '01JWMARKETNEW000000000000N', name: 'Nordics', code: 'NOR', countries: ['SE', 'NO'] },
      ],
      markets_version: 'v2',
    })
    vi.mocked(wavesApi.show).mockResolvedValue({ data: fresh })
    const w = await mountWithApp(NewWaveDialog, props(makeWave()))
    await addMarketCard()
    await pickCountry(w, 2, 'FR')
    await submit()
    expect(body('[data-test="wave-form-error"]')?.textContent).toContain(
      'changed by someone else since you opened this window',
    )
    ;(body('[data-test="wave-markets-reload"]') as HTMLElement).click()
    await flushPromises()
    expect(wavesApi.show).toHaveBeenCalledWith(AGENCY_ULID, BRAND_ULID, makeWave().id)
    expect(w.emitted('refreshed')?.[0]).toEqual([fresh])
    expect(body('[data-test="wave-form-error"]')).toBeNull()
    expect(body('[data-test="market-card-1"]')).toBeNull()
    expect(
      (
        body(
          '[data-test="market-card-0"] [data-test="market-name-input"] input',
        ) as HTMLInputElement
      ).value,
    ).toBe('Nordics')
    // The next save carries the fresh version.
    vi.mocked(wavesApi.update).mockReset()
    vi.mocked(wavesApi.update).mockResolvedValue({ data: fresh })
    await pickCountry(w, 0, 'DK')
    await submit()
    expect(vi.mocked(wavesApi.update).mock.calls[0]?.[3]).toMatchObject({ markets_version: 'v2' })
    w.unmount()
  })

  it('PL-2.3: the same refusal adds the concept counts of the markets that have concepts', async () => {
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
                {
                  market: '01JSK',
                  name: 'Slovakia',
                  code: 'SK',
                  persona_count: 2,
                  concept_count: 3,
                },
                {
                  market: '01JRO',
                  name: 'Romania',
                  code: 'RO',
                  persona_count: 1,
                  concept_count: 0,
                },
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
    const text = body('[data-test="wave-form-error"]')?.textContent ?? ''
    expect(text).toContain('Slovakia (2), Romania (1).')
    expect(text).toContain('Concepts exist in: Slovakia (3).')
    expect(text).not.toContain('Romania (0)')
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

  // ── PL-2.4 Stage 2: the market builder ────────────────────────────────────────────────────────────────
  it('a single-country market is named for its country and takes the country code; a second country keeps the name', async () => {
    const w = await mountWithApp(NewWaveDialog, props())
    await pickCountry(w, 0, 'IE')
    const name = () => (body('[data-test="market-name-input"] input') as HTMLInputElement).value
    const code = () => (body('[data-test="market-code-input"] input') as HTMLInputElement).value
    expect(name()).toBe('Ireland')
    expect(code()).toBe('IE')
    await pickCountry(w, 0, 'GB')
    expect(name()).toBe('Ireland')
    await setInput('[data-test="market-name-input"]', 'UK & Ireland')
    expect(code()).toBe('UKI')
    await setInput('[data-test="market-code-input"]', 'ukie')
    expect(code()).toBe('UKIE')
    await setInput('[data-test="market-name-input"]', 'UK and Ireland')
    expect(code()).toBe('UKIE')
    w.unmount()
  })

  it('a country already in another market is unavailable and says which market has it', async () => {
    const w = await mountWithApp(NewWaveDialog, props())
    await pickCountry(w, 0, 'IE')
    await addMarketCard()
    const card = body('[data-test="market-card-1"]') as HTMLElement
    ;(card.querySelector('[data-test="market-add-country"]') as HTMLElement).click()
    await flushPromises()
    const options = document.body.querySelectorAll('[data-test="market-option-IE"]')
    const option = options[options.length - 1] as HTMLElement
    expect(option.classList.contains('v-list-item--disabled')).toBe(true)
    expect(option.textContent).toContain('In Ireland')
    option.click()
    await flushPromises()
    expect(card.querySelector('[data-test="market-chip-IE"]')).toBeNull()
    w.unmount()
  })

  it('rename, add and remove countries, remove a market; an existing market keeps its id and code', async () => {
    vi.mocked(wavesApi.update).mockResolvedValue({ data: makeWave() })
    const wave = makeWave()
    const w = await mountWithApp(NewWaveDialog, props(wave))
    await setInput('[data-test="market-card-0"] [data-test="market-name-input"]', 'Iberia')
    ;(
      (body('[data-test="market-card-1"]') as HTMLElement).querySelector(
        '[data-test="market-remove"]',
      ) as HTMLElement
    ).click()
    await flushPromises()
    await pickCountry(w, 0, 'AD')
    ;(body('[data-test="market-chip-ES"] .v-chip__close') as HTMLElement).click()
    await flushPromises()
    await submit()
    expect(wavesApi.update).toHaveBeenCalledWith(AGENCY_ULID, BRAND_ULID, wave.id, {
      markets: [
        { id: wave.attributes.markets[0]?.id, name: 'Iberia', code: 'ES', countries: ['AD'] },
      ],
      markets_version: wave.attributes.markets_version,
    })
    w.unmount()
  })

  it('names, short codes and limits are checked before anything is sent', async () => {
    const w = await mountWithApp(NewWaveDialog, props())
    await setInput('[data-test="wave-name"]', 'Spring')
    await setInput('[data-test="wave-go-live"]', '2026-11-07')
    await pickCountry(w, 0, 'ES')
    await addMarketCard()
    await pickCountry(w, 1, 'PT')
    await setInput('[data-test="market-card-1"] [data-test="market-name-input"]', 'spain')
    await setInput('[data-test="market-card-1"] [data-test="market-code-input"]', 'ES')
    await submit()
    expect(wavesApi.create).not.toHaveBeenCalled()
    expect(document.body.textContent).toContain('Another market already has this name.')
    expect(document.body.textContent).toContain('Another market already has this code.')
    w.unmount()
  })

  it('stops at 20 markets', async () => {
    const w = await mountWithApp(NewWaveDialog, props())
    for (let i = 0; i < 25; i++) await addMarketCard()
    expect(document.body.querySelectorAll('[data-test^="market-card-"]').length).toBe(20)
    expect(body('[data-test="market-limit"]')?.textContent).toContain('20')
    w.unmount()
  })

  it('a hostile market name is shown as text, never as markup', async () => {
    const hostile = '<img src=x onerror=alert(1)><b>x</b>'
    const w = await mountWithApp(NewWaveDialog, props())
    await pickCountry(w, 0, 'ES')
    await setInput('[data-test="market-name-input"]', hostile)
    expect(document.body.querySelector('img[src="x"]')).toBeNull()
    expect((body('[data-test="market-name-input"] input') as HTMLInputElement).value).toBe(hostile)
    w.unmount()
  })

  it('wave.country_in_two_markets and the other market refusals read in words, with the market names', async () => {
    vi.mocked(wavesApi.update).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'wave.country_in_two_markets',
        message: 'x',
        details: [
          {
            code: 'wave.country_in_two_markets',
            meta: { country: 'IE', markets: ['UK & Ireland', 'Ireland'] },
          },
        ],
        raw: null,
      } as never),
    )
    const w = await mountWithApp(NewWaveDialog, props(makeWave()))
    await setInput('[data-test="wave-name"]', 'Renamed')
    await submit()
    const text = body('[data-test="wave-form-error"]')?.textContent ?? ''
    expect(text).toContain('Ireland')
    expect(text).toContain('UK & Ireland')
    w.unmount()
  })
})
