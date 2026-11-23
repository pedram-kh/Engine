/**
 * PL-3.1 — step 5, Creators: the summary row and the persona warning, the card (what shows only when the data exists, the
 * declined look, the read-only status), the remove confirmation and the server's refusals, the picker (what is listed, the
 * caps before the request, the single-select Replace, a quick reopen), the client's decision dialog by role, hostile text
 * as plain text, and the wave with no campaign.
 */
import type { WaveCreatorsData } from '@catalyst/api-client'
import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { VLayout } from 'vuetify/components'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/strategy.api', () => ({
  strategyApi: {
    listPersonas: vi.fn(),
    creators: vi.fn(),
    creatorCandidates: vi.fn(),
    addCreators: vi.fn(),
    updateCreator: vi.fn(),
    replaceCreator: vi.fn(),
    removeCreator: vi.fn(),
    creatorDecisions: vi.fn(),
    decideCreator: vi.fn(),
    listNotes: vi.fn(),
  },
}))
vi.mock('@/modules/waves/api/waves.api', () => ({
  wavesApi: { campaignOptions: vi.fn(), update: vi.fn(), createCampaign: vi.fn() },
}))

import { wavesApi } from '@/modules/waves/api/waves.api'
import { strategyApi } from '../api/strategy.api'
import {
  CREATOR_ID,
  makeCandidate,
  makeCreatorMarket,
  makeCreatorsData,
  makeSlot,
  SLOT_ID,
} from '../creatorsTestKit'
import {
  BRAND_ULID,
  makePersona,
  makeSummary,
  makeWave,
  mkt,
  mountStrategy,
  overlay,
  overlayText,
  WAVE_ULID,
} from '../strategyTestKit'
import CreatorPickerDialog from './CreatorPickerDialog.vue'
import CreatorsStep from './CreatorsStep.vue'

const ROUTE = `/brands/${BRAND_ULID}/waves/${WAVE_ULID}/strategy/creators`
const HOSTILE = '<img src=x onerror=alert(1)>'
const persona = makePersona(1, 'SK')

type Role = 'agency_admin' | 'agency_manager' | 'agency_staff'

// The notes sheet is a navigation drawer: it needs a Vuetify layout around it.
const Host = defineComponent({
  props: { summary: { type: Object, required: true }, wave: { type: Object, required: true } },
  setup(props) {
    return () =>
      h(VLayout, null, () =>
        h(CreatorsStep, { summary: props.summary as never, wave: props.wave as never }),
      )
  },
})

async function mountStep(data: WaveCreatorsData, role: Role = 'agency_admin') {
  vi.mocked(strategyApi.listPersonas).mockResolvedValue({ data: [persona] })
  vi.mocked(strategyApi.creators).mockResolvedValue({ data })
  const w = await mountStrategy(
    Host,
    {
      summary: makeSummary({ markets: [mkt('SK', 1), mkt('RO', 0)] }),
      wave: makeWave({}),
    },
    { role, route: ROUTE },
  )
  await flushPromises()
  return w
}

async function click(selector: string): Promise<void> {
  const el = overlay(selector)
  if (el === null) throw new Error(`no ${selector}`)
  el.click()
  await flushPromises()
}

function type(selector: string, value: string): void {
  const el = overlay(selector) as HTMLInputElement | HTMLTextAreaElement | null
  if (el === null) throw new Error(`no ${selector}`)
  el.value = value
  el.dispatchEvent(new Event('input', { bubbles: true }))
}

const sk = (n = 2) =>
  makeCreatorMarket(
    'SK',
    Array.from({ length: n }, (_, i) => makeSlot(i + 1)),
  )

describe('CreatorsStep (PL-3.1)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(strategyApi.creatorCandidates).mockResolvedValue({
      data: [],
      meta: {
        total: 0,
        page: 1,
        per_page: 30,
        last_page: 1,
        market: 'SK',
        market_countries: ['SK'],
      },
    })
  })
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('summary: the total, a pill only for statuses that have a creator, the persona coverage and the warning', async () => {
    const market = sk(3)
    market.slots[1] = makeSlot(2, { status: 'confirmed', detail: 'accepted' })
    market.summary = {
      total: 3,
      by_status: { shortlisted: 2, confirmed: 1 },
      personas: [{ id: persona.id, code: 'SK-P1', title: 'Office', count: 2 }],
      warnings: { personas_without_creator: ['SK-P2'] },
    }
    const w = await mountStep(makeCreatorsData([market, makeCreatorMarket('RO', [])]))

    expect(w.get('[data-test="creators-summary-count"]').text()).toContain('3')
    expect(w.find('[data-test="creators-summary-shortlisted"]').exists()).toBe(true)
    expect(w.find('[data-test="creators-summary-confirmed"]').exists()).toBe(true)
    expect(w.find('[data-test="creators-summary-contacted"]').exists()).toBe(false)
    expect(w.find('[data-test="creators-summary-declined"]').exists()).toBe(false)
    expect(w.get('[data-test="creators-summary-persona-SK-P1"]').text()).toContain('2')
    expect(w.get('[data-test="creators-persona-warning"]').text()).toContain('SK-P2')
  })

  it('no warning when every persona has a creator', async () => {
    const w = await mountStep(makeCreatorsData([sk(2), makeCreatorMarket('RO', [])]))
    expect(w.find('[data-test="creators-persona-warning"]').exists()).toBe(false)
  })

  it('card: verified, example content and location only when the data exists; the status is read-only', async () => {
    const market = makeCreatorMarket('SK', [
      makeSlot(1, {}, { verified: true, example_content_url: 'https://example.com/reel' }),
      makeSlot(
        2,
        {},
        { verified: false, example_content_url: 'javascript:alert(1)', region: null },
      ),
    ])
    const w = await mountStep(makeCreatorsData([market, makeCreatorMarket('RO', [])]))

    expect(w.find(`[data-test="creator-verified-${SLOT_ID(1)}"]`).exists()).toBe(true)
    expect(w.find(`[data-test="creator-verified-${SLOT_ID(2)}"]`).exists()).toBe(false)
    expect(w.find(`[data-test="creator-example-${SLOT_ID(1)}"]`).exists()).toBe(true)
    // A non-http(s) link is never drawn.
    expect(w.find(`[data-test="creator-example-${SLOT_ID(2)}"]`).exists()).toBe(false)
    const status = w.get(`[data-test="creator-status-${SLOT_ID(1)}"]`)
    expect(status.element.tagName).not.toBe('SELECT')
    expect(status.find('select, input, button').exists()).toBe(false)
  })

  it('card: a declined creator is drawn at 60 % opacity and an erased one reads "Deleted creator"', async () => {
    const market = makeCreatorMarket('SK', [
      makeSlot(1, { status: 'declined', detail: 'declined' }),
      makeSlot(2, {}, { is_erased: true, display_name: null }),
    ])
    const w = await mountStep(makeCreatorsData([market, makeCreatorMarket('RO', [])]))

    expect(
      w
        .get(`[data-test="creator-card-${SLOT_ID(1)}"]`)
        .classes()
        .join(' '),
    ).toContain('declined')
    const erased = w.get(`[data-test="creator-card-${SLOT_ID(2)}"]`)
    expect(erased.text()).toContain('Deleted creator')
    expect(
      erased.get(`[data-test="creator-profile-${SLOT_ID(2)}"]`).attributes('disabled'),
    ).toBeDefined()
  })

  it('card: hostile names render as plain text', async () => {
    const market = makeCreatorMarket('SK', [makeSlot(1, {}, { display_name: HOSTILE })])
    const w = await mountStep(makeCreatorsData([market, makeCreatorMarket('RO', [])]))

    expect(w.get(`[data-test="creator-name-${SLOT_ID(1)}"]`).text()).toBe(HOSTILE)
    expect(w.find('img[src="x"]').exists()).toBe(false)
  })

  it('slot code: Enter saves the new code, Escape cancels and sends nothing', async () => {
    const data = makeCreatorsData([sk(1), makeCreatorMarket('RO', [])])
    vi.mocked(strategyApi.updateCreator).mockResolvedValue({ data })
    const w = await mountStep(data)

    await w.get(`[data-test="creator-slot-code-edit-${SLOT_ID(1)}"]`).trigger('click')
    const input = w.get(`[data-test="creator-slot-code-input-${SLOT_ID(1)}"]`)
    await input.setValue('SK-HERO')
    await input.trigger('keydown', { key: 'Escape' })
    expect(strategyApi.updateCreator).not.toHaveBeenCalled()

    await w.get(`[data-test="creator-slot-code-edit-${SLOT_ID(1)}"]`).trigger('click')
    const again = w.get(`[data-test="creator-slot-code-input-${SLOT_ID(1)}"]`)
    await again.setValue('SK-HERO')
    await again.trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(strategyApi.updateCreator).toHaveBeenCalledWith(expect.anything(), SLOT_ID(1), {
      slot_code: 'SK-HERO',
    })
  })

  it('remove: asks first; Cancel sends nothing; the server refusal for a creator in Filming shows in the page', async () => {
    const data = makeCreatorsData([sk(1), makeCreatorMarket('RO', [])])
    const w = await mountStep(data)

    await w.get(`[data-test="creator-remove-${SLOT_ID(1)}"]`).trigger('click')
    await flushPromises()
    expect(overlay('[data-test="creator-remove-dialog"]')).not.toBeNull()
    expect(strategyApi.removeCreator).not.toHaveBeenCalled()

    vi.mocked(strategyApi.removeCreator).mockRejectedValue(
      new ApiError({ status: 422, code: 'wave_creator.remove_blocked_in_work', message: 'x' }),
    )
    await click('[data-test="creator-remove-dialog"] [data-test="confirm-ok"]')
    expect(strategyApi.removeCreator).toHaveBeenCalledWith(expect.anything(), SLOT_ID(1))
    expect(overlayText()).toContain('already in production')
  })

  it('no campaign: the panel explains and offers Link (a select of unlinked campaigns) and Create; the grid is not drawn', async () => {
    vi.mocked(wavesApi.campaignOptions).mockResolvedValue({
      data: [
        {
          id: 'C1',
          type: 'campaign_options',
          attributes: { name: 'Bolt Spare Campaign', status: 'draft' },
        },
      ],
    } as never)
    const data = makeCreatorsData([makeCreatorMarket('SK', [])], {
      campaign: { linked: false, id: null, name: null },
    })
    const w = await mountStep(data)

    expect(w.find('[data-test="creators-no-campaign"]').exists()).toBe(true)
    expect(w.find('[data-test="creators-link-select"]').exists()).toBe(true)
    expect(w.find('[data-test="creators-create-name"]').exists()).toBe(true)
    expect(w.find('[data-test="creators-grid"]').exists()).toBe(false)
    expect(w.find('[data-test="creators-add"]').exists()).toBe(false)
  })

  it('no campaign: Create needs a name and calls the create endpoint once', async () => {
    vi.mocked(wavesApi.campaignOptions).mockResolvedValue({ data: [] } as never)
    vi.mocked(wavesApi.createCampaign).mockResolvedValue({ data: makeWave({}) } as never)
    const data = makeCreatorsData([makeCreatorMarket('SK', [])], {
      campaign: { linked: false, id: null, name: null },
    })
    const w = await mountStep(data)

    await w.get('[data-test="creators-create-submit"]').trigger('click')
    await flushPromises()
    expect(wavesApi.createCampaign).not.toHaveBeenCalled()

    await w.get('[data-test="creators-create-name"] input').setValue('Bolt Wave Campaign')
    await w.get('[data-test="creators-create-submit"]').trigger('click')
    await flushPromises()
    expect(wavesApi.createCampaign).toHaveBeenCalledTimes(1)
    expect(vi.mocked(wavesApi.createCampaign).mock.calls[0]?.[3]).toBe('Bolt Wave Campaign')
  })

  it('no campaign: staff see the explanation but no Link or Create', async () => {
    const data = makeCreatorsData([makeCreatorMarket('SK', [])], {
      campaign: { linked: false, id: null, name: null },
    })
    const w = await mountStep(data, 'agency_staff')
    expect(w.find('[data-test="creators-no-campaign-readonly"]').exists()).toBe(true)
    expect(w.find('[data-test="creators-link-select"]').exists()).toBe(false)
    expect(w.find('[data-test="creators-create-submit"]').exists()).toBe(false)
  })

  it('empty market: the empty state names the campaign and offers Add; at the cap Add is disabled and the cap is said', async () => {
    const empty = await mountStep(
      makeCreatorsData([makeCreatorMarket('SK', []), makeCreatorMarket('RO', [])]),
    )
    expect(empty.get('[data-test="creators-empty"]').text()).toContain('Bolt Summer Wave Campaign')
    expect(empty.get('[data-test="creators-empty-cta"]').attributes('disabled')).toBeUndefined()
    empty.unmount()

    const full = makeCreatorMarket(
      'SK',
      Array.from({ length: 30 }, (_, i) => makeSlot(i + 1)),
    )
    const w = await mountStep(makeCreatorsData([full, makeCreatorMarket('RO', [])]))
    expect(w.get('[data-test="creators-at-cap"]').text()).toBe(
      'Maximum of 30 creators reached in Slovakia',
    )
    expect(w.get('[data-test="creators-add"]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-test="creators-add-slot"]').attributes('disabled')).toBeDefined()
  })

  describe('picker', () => {
    async function openPicker(data: WaveCreatorsData, role: Role = 'agency_admin') {
      const w = await mountStep(data, role)
      await w.get('[data-test="creators-add"]').trigger('click')
      await flushPromises()
      return w
    }
    const meta = (total: number) => ({
      total,
      page: 1,
      per_page: 30,
      last_page: 1,
      market: 'SK',
      market_countries: ['SK'],
    })

    it('opens on the market, lists the server candidates, shows the countries as removable chips and "Already in wave" with the market', async () => {
      vi.mocked(strategyApi.creatorCandidates).mockResolvedValue({
        data: [makeCandidate(1), makeCandidate(2, { already_in_wave: true, in_market: 'RO' })],
        meta: meta(2),
      })
      await openPicker(makeCreatorsData([sk(1), makeCreatorMarket('RO', [])]))

      expect(overlay('[data-test="creator-picker"]')).not.toBeNull()
      expect(strategyApi.creatorCandidates).toHaveBeenCalledTimes(1)
      expect(vi.mocked(strategyApi.creatorCandidates).mock.calls[0]?.[1]).toMatchObject({
        market: 'SK',
        countries: ['SK'],
      })
      expect(overlay('[data-test="creator-picker-country-SK"]')).not.toBeNull()
      expect(overlay(`[data-test="picker-in-wave-${CREATOR_ID(102)}"]`)?.textContent).toContain(
        'Romania',
      )
      expect(overlay(`[data-test="picker-in-wave-${CREATOR_ID(101)}"]`)).toBeNull()
    })

    it('removing a country chip reads the list again without it', async () => {
      vi.mocked(strategyApi.creatorCandidates).mockResolvedValue({
        data: [makeCandidate(1)],
        meta: meta(1),
      })
      await openPicker(makeCreatorsData([sk(1), makeCreatorMarket('RO', [])]))
      await click('[data-test="creator-picker-country-remove-SK"]')

      expect(strategyApi.creatorCandidates).toHaveBeenCalledTimes(2)
      expect(vi.mocked(strategyApi.creatorCandidates).mock.calls[1]?.[1]).toMatchObject({
        countries: [],
      })
    })

    it('multi-select: the footer counts and names, the submit sends the ticked ulids; an "Already in wave" card cannot be ticked', async () => {
      vi.mocked(strategyApi.creatorCandidates).mockResolvedValue({
        data: [
          makeCandidate(1),
          makeCandidate(2),
          makeCandidate(3, { already_in_wave: true, in_market: 'RO' }),
        ],
        meta: meta(3),
      })
      vi.mocked(strategyApi.addCreators).mockResolvedValue({
        data: makeCreatorsData([sk(3)]),
      } as never)
      await openPicker(makeCreatorsData([sk(1), makeCreatorMarket('RO', [])]))

      await click(`[data-test="picker-card-${CREATOR_ID(101)}"]`)
      await click(`[data-test="picker-card-${CREATOR_ID(102)}"]`)
      await click(`[data-test="picker-card-${CREATOR_ID(103)}"]`)
      expect(overlay('[data-test="creator-picker-selected"]')?.textContent).toContain('2')
      expect(overlay('[data-test="creator-picker-names"]')?.textContent).toContain('Candidate 1')

      await click('[data-test="creator-picker-submit"]')
      expect(strategyApi.addCreators).toHaveBeenCalledWith(expect.anything(), {
        market: 'SK',
        creators: [CREATOR_ID(101), CREATOR_ID(102)],
      })
    })

    it('the cap is shown before the request: ticking more than the room disables the submit and sends nothing', async () => {
      const market = makeCreatorMarket(
        'SK',
        Array.from({ length: 29 }, (_, i) => makeSlot(i + 1)),
      )
      vi.mocked(strategyApi.creatorCandidates).mockResolvedValue({
        data: [makeCandidate(1), makeCandidate(2)],
        meta: meta(2),
      })
      await openPicker(makeCreatorsData([market, makeCreatorMarket('RO', [])]))
      expect(overlay('[data-test="creator-picker-cap"]')?.textContent).toContain('1')

      await click(`[data-test="picker-card-${CREATOR_ID(101)}"]`)
      await click(`[data-test="picker-card-${CREATOR_ID(102)}"]`)
      const submit = overlay('[data-test="creator-picker-submit"]') as HTMLButtonElement
      expect(submit.disabled).toBe(true)
      submit.click()
      await flushPromises()
      expect(strategyApi.addCreators).not.toHaveBeenCalled()
    })

    it.each([
      [3, '3 creators in Slovakia', 'normal'],
      [1, '1 creator in Slovakia', 'normal'],
      [24, '24 creators in Slovakia', 'normal'],
      [25, 'Up to 30 creators per market · 5 places left', 'near'],
      [29, 'Up to 30 creators per market · 1 place left', 'near'],
    ])(
      'F2: with %i in the market the line under the list reads "%s" (a count, never a quota)',
      async (n, text, state) => {
        vi.mocked(strategyApi.creatorCandidates).mockResolvedValue({
          data: [makeCandidate(1)],
          meta: meta(1),
        })
        const market = makeCreatorMarket(
          'SK',
          Array.from({ length: n }, (_, i) => makeSlot(i + 1)),
        )
        await openPicker(makeCreatorsData([market, makeCreatorMarket('RO', [])]))
        const el = overlay('[data-test="creator-picker-cap"]')
        expect(el?.textContent?.trim()).toBe(text)
        expect(el?.getAttribute('data-state')).toBe(state)
      },
    )

    it('F2: at 30 the maximum is said for the market and the submit is off (the dialog opened on a stale count)', async () => {
      vi.mocked(strategyApi.creatorCandidates).mockResolvedValue({
        data: [makeCandidate(1)],
        meta: meta(1),
      })
      const w = await mountStrategy(
        CreatorPickerDialog,
        {
          modelValue: true,
          scope: { brandUlid: 'b', waveUlid: 'w' },
          market: { id: 'SK', name: 'Slovakia', code: 'SK', countries: ['SK'] },
          marketName: () => 'Slovakia',
          personas: [],
          campaignId: 'c',
          campaignName: 'Camp',
          mode: 'add',
          replacing: null,
          roomMarket: 0,
          roomWave: 70,
          perMarket: 30,
          perWave: 100,
        },
        { role: 'agency_admin', route: ROUTE },
      )
      await flushPromises()
      const el = overlay('[data-test="creator-picker-cap"]')
      expect(el?.textContent?.trim()).toBe('Maximum of 30 creators reached in Slovakia')
      expect(el?.getAttribute('data-state')).toBe('max')
      expect((overlay('[data-test="creator-picker-submit"]') as HTMLButtonElement).disabled).toBe(
        true,
      )
      w.unmount()
    })

    it('F2: the wave ceiling follows the same pattern when it is the nearer one', async () => {
      vi.mocked(strategyApi.creatorCandidates).mockResolvedValue({
        data: [makeCandidate(1)],
        meta: meta(1),
      })
      const markets = ['SK', 'RO', 'CZ', 'PL'].map((code, i) =>
        makeCreatorMarket(
          code,
          Array.from({ length: i === 0 ? 12 : 28 }, (_, k) => makeSlot(i * 40 + k + 1)),
        ),
      )
      // 12 + 28 + 28 + 28 = 96 of 100 in the wave: the wave is the nearer ceiling for the 12-creator market.
      await openPicker(makeCreatorsData(markets))
      const el = overlay('[data-test="creator-picker-cap"]')
      expect(el?.textContent?.trim()).toBe('Up to 100 creators per wave · 4 places left')
      expect(el?.getAttribute('data-state')).toBe('near')
    })

    it('F1: a card shows the creator CURRENT status as the pill, the origin only as a small tag; a declined creator can still be ticked', async () => {
      vi.mocked(strategyApi.creatorCandidates).mockResolvedValue({
        data: [
          makeCandidate(1, {
            status: 'confirmed',
            detail: 'accepted',
            applied: false,
            invited: true,
          }),
          makeCandidate(2, { status: 'declined', detail: 'declined', applied: true }),
          makeCandidate(3),
        ],
        meta: meta(3),
      })
      await openPicker(makeCreatorsData([sk(1), makeCreatorMarket('RO', [])]))
      expect(overlay(`[data-test="picker-status-${CREATOR_ID(101)}"]`)?.textContent).toContain(
        'Confirmed',
      )
      expect(overlay(`[data-test="picker-status-${CREATOR_ID(102)}"]`)?.textContent).toContain(
        'Declined',
      )
      expect(overlay(`[data-test="picker-status-${CREATOR_ID(103)}"]`)?.textContent).toContain(
        'Shortlisted',
      )
      // The origin stays, as a secondary tag.
      expect(overlay(`[data-test="picker-card-${CREATOR_ID(101)}"]`)?.textContent).toContain(
        'Invited',
      )
      // Today's rule, kept: a declined creator is not blocked from being picked.
      await click(`[data-test="picker-card-${CREATOR_ID(102)}"]`)
      expect(overlay('[data-test="creator-picker-selected"]')?.textContent).toContain('1')
    })

    it('F3: the footer reads "n selected" and the button "Add n creators" (singular for one)', async () => {
      vi.mocked(strategyApi.creatorCandidates).mockResolvedValue({
        data: [makeCandidate(1), makeCandidate(2)],
        meta: meta(2),
      })
      await openPicker(makeCreatorsData([sk(1), makeCreatorMarket('RO', [])]))
      await click(`[data-test="picker-card-${CREATOR_ID(101)}"]`)
      expect(overlay('[data-test="creator-picker-selected"]')?.textContent?.trim()).toBe(
        '1 selected',
      )
      expect(overlay('[data-test="creator-picker-submit"]')?.textContent?.trim()).toBe(
        'Add 1 creator',
      )
      await click(`[data-test="picker-card-${CREATOR_ID(102)}"]`)
      expect(overlay('[data-test="creator-picker-selected"]')?.textContent?.trim()).toBe(
        '2 selected',
      )
      expect(overlay('[data-test="creator-picker-submit"]')?.textContent?.trim()).toBe(
        'Add 2 creators',
      )
    })

    it('empty: a campaign with no applicants or invitees says so and links to the campaign', async () => {
      await openPicker(makeCreatorsData([sk(1), makeCreatorMarket('RO', [])]))
      expect(overlay('[data-test="creator-picker-empty"]')).not.toBeNull()
      expect(overlay('[data-test="creator-picker-campaign-link"]')).not.toBeNull()
    })

    it('empty under the market chips: says "no creators match" when the campaign has people elsewhere, "no applicants" when it has none', async () => {
      vi.mocked(strategyApi.creatorCandidates)
        .mockResolvedValueOnce({ data: [], meta: meta(0) })
        .mockResolvedValueOnce({ data: [makeCandidate(1)], meta: meta(1) })
      await openPicker(makeCreatorsData([sk(1), makeCreatorMarket('RO', [])]))
      expect(overlay('[data-test="creator-picker-empty"]')?.textContent).toContain(
        'No creators match',
      )
      expect(vi.mocked(strategyApi.creatorCandidates).mock.calls[1]?.[1]).toMatchObject({
        countries: [],
        per_page: 1,
      })
    })

    it('replace: single select, one creator, the replace call and not the add call', async () => {
      vi.mocked(strategyApi.creatorCandidates).mockResolvedValue({
        data: [makeCandidate(1), makeCandidate(2)],
        meta: meta(2),
      })
      vi.mocked(strategyApi.replaceCreator).mockResolvedValue({
        data: makeCreatorsData([sk(1)]),
      } as never)
      const w = await mountStep(makeCreatorsData([sk(1), makeCreatorMarket('RO', [])]))
      await w.get(`[data-test="creator-replace-${SLOT_ID(1)}"]`).trigger('click')
      await flushPromises()

      await click(`[data-test="picker-card-${CREATOR_ID(101)}"]`)
      await click(`[data-test="picker-card-${CREATOR_ID(102)}"]`)
      expect(overlay('[data-test="creator-picker-selected"]')?.textContent).toContain('1')
      expect(overlay('[data-test="creator-picker-persona"]')).toBeNull()

      await click('[data-test="creator-picker-submit"]')
      expect(strategyApi.replaceCreator).toHaveBeenCalledWith(
        expect.anything(),
        SLOT_ID(1),
        CREATOR_ID(102),
      )
      expect(strategyApi.addCreators).not.toHaveBeenCalled()
    })

    it('open, close and quickly reopen: nothing ticked, nothing typed, one fresh read', async () => {
      vi.mocked(strategyApi.creatorCandidates).mockResolvedValue({
        data: [makeCandidate(1)],
        meta: meta(1),
      })
      const w = await openPicker(makeCreatorsData([sk(1), makeCreatorMarket('RO', [])]))
      await click(`[data-test="picker-card-${CREATOR_ID(101)}"]`)
      type('[data-test="creator-picker-search"] input', 'anna')
      await click('[data-test="creator-picker-cancel"]')
      await w.get('[data-test="creators-add"]').trigger('click')
      await flushPromises()

      expect(overlay('[data-test="creator-picker-selected"]')?.textContent).toContain('0')
      expect((overlay('[data-test="creator-picker-search"] input') as HTMLInputElement).value).toBe(
        '',
      )
    })

    it('a refused add keeps the picker open and says why (already in the wave, with the creator still ticked)', async () => {
      vi.mocked(strategyApi.creatorCandidates).mockResolvedValue({
        data: [makeCandidate(1)],
        meta: meta(1),
      })
      vi.mocked(strategyApi.addCreators).mockRejectedValue(
        new ApiError({ status: 422, code: 'wave_creator.already_in_wave', message: 'x' }),
      )
      await openPicker(makeCreatorsData([sk(1), makeCreatorMarket('RO', [])]))
      await click(`[data-test="picker-card-${CREATOR_ID(101)}"]`)
      await click('[data-test="creator-picker-submit"]')

      expect(overlay('[data-test="creator-picker-error"]')).not.toBeNull()
      expect(overlay('[data-test="creator-picker"]')).not.toBeNull()
    })
  })

  describe('decisions', () => {
    it('the client decision: staff cannot record one; admin can', async () => {
      const data = makeCreatorsData([sk(1), makeCreatorMarket('RO', [])])
      const staff = await mountStep(data, 'agency_staff')
      expect(staff.find(`[data-test="creator-decide-${SLOT_ID(1)}"]`).exists()).toBe(false)
      staff.unmount()
      const admin = await mountStep(data)
      expect(admin.find(`[data-test="creator-decide-${SLOT_ID(1)}"]`).exists()).toBe(true)
    })

    it('Approved needs a name; Suggest another needs a name and a comment; nothing is sent until both are there', async () => {
      const data = makeCreatorsData([sk(1), makeCreatorMarket('RO', [])])
      vi.mocked(strategyApi.decideCreator).mockResolvedValue({
        data,
        meta: { card_moved: true, card_not_moved: null },
      })
      const w = await mountStep(data)
      await w.get(`[data-test="creator-decide-${SLOT_ID(1)}"]`).trigger('click')
      await flushPromises()

      await click('[data-test="creator-decision-save"]')
      expect(strategyApi.decideCreator).not.toHaveBeenCalled()

      type('[data-test="creator-decision-name"] input', 'Jana Nováková')
      await click('[data-test="creator-decision-save"]')
      expect(strategyApi.decideCreator).toHaveBeenCalledTimes(1)
      expect(vi.mocked(strategyApi.decideCreator).mock.calls[0]?.[2]).toMatchObject({
        status: 'approved',
        client_name: 'Jana Nováková',
      })
    })

    it('Suggest another needs a comment as well as the name; the request carries both', async () => {
      const data = makeCreatorsData([sk(1), makeCreatorMarket('RO', [])])
      vi.mocked(strategyApi.decideCreator).mockResolvedValue({
        data,
        meta: { card_moved: false, card_not_moved: null },
      })
      const w = await mountStep(data)
      await w.get(`[data-test="creator-decide-${SLOT_ID(1)}"]`).trigger('click')
      await flushPromises()

      await click('[data-test="creator-decision-status"] [data-selected="false"]')
      type('[data-test="creator-decision-name"] input', 'Jana Nováková')
      await click('[data-test="creator-decision-save"]')
      expect(strategyApi.decideCreator).not.toHaveBeenCalled()

      type('[data-test="creator-decision-comment"] textarea', 'Someone closer to the brand')
      await click('[data-test="creator-decision-save"]')
      expect(vi.mocked(strategyApi.decideCreator).mock.calls[0]?.[2]).toMatchObject({
        status: 'suggest_another',
        client_name: 'Jana Nováková',
        comment: 'Someone closer to the brand',
      })
    })

    it('the card shows the decision as plain text, and a hostile name or comment is not markup', async () => {
      const market = makeCreatorMarket('SK', [
        makeSlot(1, {
          decision_count: 1,
          decision: {
            id: 'D1',
            status: 'suggest_another',
            client_name: HOSTILE,
            note: null,
            comment: HOSTILE,
            recorded_by: 'Ada',
            recorded_at: '2026-11-02T09:00:00.000000Z',
          },
        }),
      ])
      const w = await mountStep(makeCreatorsData([market, makeCreatorMarket('RO', [])]))

      const box = w.get(`[data-test="creator-decision-${SLOT_ID(1)}"]`)
      expect(box.text()).toContain(HOSTILE)
      expect(w.find('img[src="x"]').exists()).toBe(false)
    })

    it('history reads on each open and renders plain text', async () => {
      const market = makeCreatorMarket('SK', [makeSlot(1, { decision_count: 1 })])
      vi.mocked(strategyApi.creatorDecisions).mockResolvedValue({
        data: [
          {
            id: 'D1',
            status: 'approved',
            client_name: HOSTILE,
            note: HOSTILE,
            comment: null,
            recorded_by: 'Ada',
            recorded_at: '2026-11-02T09:00:00.000000Z',
          },
        ],
      })
      const w = await mountStep(makeCreatorsData([market, makeCreatorMarket('RO', [])]))
      await w.get(`[data-test="creator-history-${SLOT_ID(1)}"]`).trigger('click')
      await flushPromises()

      expect(overlay('[data-test="creator-history-dialog"]')?.textContent).toContain(HOSTILE)
      expect(document.body.querySelector('img[src="x"]')).toBeNull()
      expect(strategyApi.creatorDecisions).toHaveBeenCalledTimes(1)
    })
  })
})
