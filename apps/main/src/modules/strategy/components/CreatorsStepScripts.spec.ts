/**
 * PL-3.2a — step 5 when the creator has scripts: Remove asks what happens to them (archive by default; delete only for an admin or
 * a manager) and sends the choice; Replace asks (carry over by default, or archive and start fresh) and sends it; with no scripts
 * nothing extra is asked or sent; the server's `scripts_choice_required` race reads the counts again so the dialog then asks.
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
    scripts: vi.fn(),
  },
}))
vi.mock('@/modules/waves/api/waves.api', () => ({
  wavesApi: { campaignOptions: vi.fn(), update: vi.fn(), createCampaign: vi.fn() },
}))

import { strategyApi } from '../api/strategy.api'
import {
  CREATOR_ID,
  makeCandidate,
  makeCreatorMarket,
  makeCreatorsData,
  makeSlot,
  SLOT_ID,
} from '../creatorsTestKit'
import { makeOverview, makeRailCreator } from '../scriptsTestKit'
import {
  BRAND_ULID,
  makePersona,
  makeSummary,
  makeWave,
  mkt,
  mountStrategy,
  overlay,
  WAVE_ULID,
} from '../strategyTestKit'
import CreatorPickerDialog from './CreatorPickerDialog.vue'
import CreatorsStep from './CreatorsStep.vue'

const ROUTE = `/brands/${BRAND_ULID}/waves/${WAVE_ULID}/strategy/creators`
type Role = 'agency_admin' | 'agency_manager' | 'agency_staff'

const Host = defineComponent({
  props: { summary: { type: Object, required: true }, wave: { type: Object, required: true } },
  setup(props) {
    return () =>
      h(VLayout, null, () =>
        h(CreatorsStep, { summary: props.summary as never, wave: props.wave as never }),
      )
  },
})

const data = (): WaveCreatorsData =>
  makeCreatorsData([
    makeCreatorMarket('SK', [makeSlot(1), makeSlot(2)]),
    makeCreatorMarket('RO', []),
  ])

/** `counts[n]` = scripts of slot n (slot ids are those of the creators fixtures). */
async function mountStep(counts: Record<number, number>, role: Role = 'agency_admin') {
  vi.mocked(strategyApi.listPersonas).mockResolvedValue({ data: [makePersona(1, 'SK')] })
  vi.mocked(strategyApi.creators).mockResolvedValue({ data: data() })
  const creators = Object.entries(counts).map(([n, c]) =>
    makeRailCreator(
      Number(n),
      Array.from({ length: c }, (_, i) => Number(n) * 10 + i),
      { id: SLOT_ID(Number(n)) },
    ),
  )
  vi.mocked(strategyApi.scripts).mockResolvedValue({ data: makeOverview('SK', creators) })
  const w = await mountStrategy(
    Host,
    { summary: makeSummary({ markets: [mkt('SK', 1), mkt('RO', 0)] }), wave: makeWave({}) },
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

describe('CreatorsStep — scripts choices (PL-3.2a)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(strategyApi.creatorCandidates).mockResolvedValue({
      data: [makeCandidate(1)],
      meta: {
        total: 1,
        page: 1,
        per_page: 30,
        last_page: 1,
        market: 'SK',
        market_countries: ['SK'],
      },
    })
    vi.mocked(strategyApi.removeCreator).mockResolvedValue({ data: data() } as never)
    vi.mocked(strategyApi.replaceCreator).mockResolvedValue({ data: data() } as never)
  })
  afterEach(() => {
    document.body.innerHTML = ''
  })

  describe('Remove', () => {
    it('a creator with scripts: the dialog counts them and archive is the default; Remove sends "archive"', async () => {
      const w = await mountStep({ 1: 2, 2: 0 })
      await w.get(`[data-test="creator-remove-${SLOT_ID(1)}"]`).trigger('click')
      await flushPromises()
      const group = overlay('[data-test="creator-remove-scripts"]')
      expect(group).not.toBeNull()
      expect(group?.textContent).toContain('2')
      expect(
        (overlay('[data-test="creator-remove-scripts-archive"] input') as HTMLInputElement).checked,
      ).toBe(true)
      await click('[data-test="creator-remove-dialog"] [data-test="confirm-ok"]')
      expect(strategyApi.removeCreator).toHaveBeenCalledWith(
        expect.anything(),
        SLOT_ID(1),
        'archive',
      )
    })

    it('admin and manager can choose to delete; staff are not offered it', async () => {
      for (const role of ['agency_admin', 'agency_manager'] as Role[]) {
        const w = await mountStep({ 1: 1 }, role)
        await w.get(`[data-test="creator-remove-${SLOT_ID(1)}"]`).trigger('click')
        await flushPromises()
        await click('[data-test="creator-remove-scripts-delete"] input')
        await click('[data-test="creator-remove-dialog"] [data-test="confirm-ok"]')
        expect(strategyApi.removeCreator).toHaveBeenLastCalledWith(
          expect.anything(),
          SLOT_ID(1),
          'delete',
        )
        w.unmount()
        document.body.innerHTML = ''
      }
      const staff = await mountStep({ 1: 1 }, 'agency_staff')
      await staff.get(`[data-test="creator-remove-${SLOT_ID(1)}"]`).trigger('click')
      await flushPromises()
      expect(overlay('[data-test="creator-remove-scripts-archive"]')).not.toBeNull()
      expect(overlay('[data-test="creator-remove-scripts-delete"]')).toBeNull()
    })

    it('a creator with no scripts: no question, and the request carries no scripts choice', async () => {
      const w = await mountStep({ 1: 0 })
      await w.get(`[data-test="creator-remove-${SLOT_ID(1)}"]`).trigger('click')
      await flushPromises()
      expect(overlay('[data-test="creator-remove-scripts"]')).toBeNull()
      await click('[data-test="creator-remove-dialog"] [data-test="confirm-ok"]')
      expect(strategyApi.removeCreator).toHaveBeenCalledWith(
        expect.anything(),
        SLOT_ID(1),
        undefined,
      )
    })

    it('the choice does not leak: delete chosen, closed, reopened is archive again; counts unknown asks nothing', async () => {
      const w = await mountStep({ 1: 1 })
      await w.get(`[data-test="creator-remove-${SLOT_ID(1)}"]`).trigger('click')
      await flushPromises()
      await click('[data-test="creator-remove-scripts-delete"] input')
      await click('[data-test="creator-remove-dialog"] [data-test="confirm-cancel"]')
      await w.get(`[data-test="creator-remove-${SLOT_ID(1)}"]`).trigger('click')
      await flushPromises()
      expect(
        (overlay('[data-test="creator-remove-scripts-archive"] input') as HTMLInputElement).checked,
      ).toBe(true)
    })

    it('the race: scripts appeared after the counts were read; the 422 shows, the counts are read again and the dialog now asks', async () => {
      const w = await mountStep({ 1: 0 })
      await w.get(`[data-test="creator-remove-${SLOT_ID(1)}"]`).trigger('click')
      await flushPromises()
      vi.mocked(strategyApi.removeCreator).mockRejectedValueOnce(
        new ApiError({ status: 422, code: 'wave_creator.scripts_choice_required', message: 'x' }),
      )
      vi.mocked(strategyApi.scripts).mockResolvedValue({
        data: makeOverview('SK', [makeRailCreator(1, [11], { id: SLOT_ID(1) })]),
      })
      await click('[data-test="creator-remove-dialog"] [data-test="confirm-ok"]')
      expect(overlay('[data-test="creator-remove-scripts"]')).not.toBeNull()
      await click('[data-test="creator-remove-dialog"] [data-test="confirm-ok"]')
      expect(strategyApi.removeCreator).toHaveBeenLastCalledWith(
        expect.anything(),
        SLOT_ID(1),
        'archive',
      )
    })
  })

  describe('The picker in add mode', () => {
    it('never asks about scripts, even when it is handed a script count (the question belongs to Replace only)', async () => {
      await mountStrategy(CreatorPickerDialog, {
        modelValue: true,
        scope: { agencyId: 'A', brandId: BRAND_ULID, waveId: WAVE_ULID },
        market: { id: 'SK', market: 'SK', name: 'Slovakia', countries: ['SK'] } as never,
        marketName: () => 'Slovakia',
        personas: [],
        campaignId: null,
        campaignName: null,
        mode: 'add',
        replacing: null,
        roomMarket: 5,
        roomWave: 5,
        perMarket: 5,
        perWave: 5,
        scriptCount: 2,
      })
      await flushPromises()
      await click(`[data-test="picker-card-${CREATOR_ID(101)}"]`)
      expect(overlay('[data-test="creator-picker-names"]')).not.toBeNull()
      expect(overlay('[data-test="creator-picker-scripts"]')).toBeNull()
    })
  })

  describe('Replace', () => {
    async function openReplace(counts: Record<number, number>) {
      const w = await mountStep(counts)
      await w.get(`[data-test="creator-replace-${SLOT_ID(1)}"]`).trigger('click')
      await flushPromises()
      await click(`[data-test="picker-card-${CREATOR_ID(101)}"]`)
      return w
    }

    it('with scripts: the choice shows with the count, carry over is the default and is sent', async () => {
      await openReplace({ 1: 2 })
      const group = overlay('[data-test="creator-picker-scripts"]')
      expect(group).not.toBeNull()
      expect(group?.textContent).toContain('2')
      expect(
        (overlay('[data-test="creator-picker-scripts-carry"] input') as HTMLInputElement).checked,
      ).toBe(true)
      await click('[data-test="creator-picker-submit"]')
      expect(strategyApi.replaceCreator).toHaveBeenCalledWith(
        expect.anything(),
        SLOT_ID(1),
        CREATOR_ID(101),
        'carry_over',
      )
    })

    it('"archive and start fresh" is sent when chosen', async () => {
      await openReplace({ 1: 1 })
      await click('[data-test="creator-picker-scripts-archive"] input')
      await click('[data-test="creator-picker-submit"]')
      expect(strategyApi.replaceCreator).toHaveBeenCalledWith(
        expect.anything(),
        SLOT_ID(1),
        CREATOR_ID(101),
        'archive',
      )
    })

    it('no scripts: no question and no choice in the request', async () => {
      await openReplace({ 1: 0 })
      expect(overlay('[data-test="creator-picker-scripts"]')).toBeNull()
      await click('[data-test="creator-picker-submit"]')
      expect(strategyApi.replaceCreator).toHaveBeenCalledWith(
        expect.anything(),
        SLOT_ID(1),
        CREATOR_ID(101),
        undefined,
      )
    })

    it('adding creators (not replacing) never asks about scripts', async () => {
      const w = await mountStep({ 1: 2 })
      await w.get('[data-test="creators-add"]').trigger('click')
      await flushPromises()
      expect(overlay('[data-test="creator-picker-scripts"]')).toBeNull()
    })
  })
})
