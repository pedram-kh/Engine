/**
 * PL-2.1 W4-W7 — step 1, USPs: counter and maximum, warning under four, add with an explicit Save (partial PATCH on
 * edit), reorder sends the full list, delete with the in-use confirmation that lists what is affected, and hostile
 * text rendered as plain text.
 */
import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/strategy.api', () => ({
  strategyApi: {
    listUsps: vi.fn(),
    listPersonas: vi.fn(),
    createUsp: vi.fn(),
    updateUsp: vi.fn(),
    reorderUsps: vi.fn(),
    removeUsp: vi.fn(),
    setAudienceTitle: vi.fn(),
  },
}))

import { strategyApi } from '../api/strategy.api'
import {
  AGENCY_ULID,
  BRAND_ULID,
  makeAngle,
  makePersona,
  makeSummary,
  makeUsp,
  makeUsps,
  mountStrategy,
  overlay,
  overlayText,
  WAVE_ULID,
} from '../strategyTestKit'
import UspsStep from './UspsStep.vue'

const SCOPE = { agencyId: AGENCY_ULID, brandId: BRAND_ULID, waveId: WAVE_ULID }
const HOSTILE = '<img src=x onerror=alert(1)>'

async function mountStep(
  usps = makeUsps(3),
  role: 'agency_admin' | 'agency_manager' | 'agency_staff' = 'agency_admin',
) {
  vi.mocked(strategyApi.listUsps).mockResolvedValue({ data: usps })
  const w = await mountStrategy(
    UspsStep,
    { summary: makeSummary({ usp_count: usps.length }) },
    { role },
  )
  await flushPromises()
  return w
}

function type(selector: string, value: string): void {
  const input = overlay(selector)
  if (input === null) throw new Error(`no ${selector}`)
  const el = input as HTMLInputElement | HTMLTextAreaElement
  el.value = value
  el.dispatchEvent(new Event('input', { bubbles: true }))
}

async function click(selector: string): Promise<void> {
  const el = overlay(selector)
  if (el === null) throw new Error(`no ${selector}`)
  el.click()
  await flushPromises()
}

describe('UspsStep (PL-2.1)', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('W4: counter, maximum reached disables Add, and the warning shows only under four', async () => {
    const three = await mountStep(makeUsps(3))
    expect(three.get('[data-test="usp-counter"]').text()).toBe('3 of 6 USPs')
    expect(three.find('[data-test="usp-warning"]').exists()).toBe(true)
    expect(three.get('[data-test="usp-add"]').attributes('disabled')).toBeUndefined()
    three.unmount()

    const four = await mountStep(makeUsps(4))
    expect(four.find('[data-test="usp-warning"]').exists()).toBe(false)
    four.unmount()

    const six = await mountStep(makeUsps(6))
    expect(six.get('[data-test="usp-counter"]').text()).toBe('6 of 6 USPs · maximum reached')
    expect(six.get('[data-test="usp-add"]').attributes('disabled')).toBeDefined()
    expect(six.findAll('[data-test="usp-card"]')).toHaveLength(6)
    expect(six.findAll('[data-test="usp-number"]').map((n) => n.text())).toEqual([
      '01',
      '02',
      '03',
      '04',
      '05',
      '06',
    ])
    six.unmount()
  })

  it('W4: no USPs shows the empty state with its own Add', async () => {
    const w = await mountStep([])
    expect(w.find('[data-test="usps-empty"]').exists()).toBe(true)
    expect(w.find('[data-test="usps-grid"]').exists()).toBe(false)
    expect(w.find('[data-test="usp-add-empty"]').exists()).toBe(true)
    w.unmount()
  })

  it('W5: Add sends nothing until Save; an empty title is refused on the page; then it creates and reloads', async () => {
    const w = await mountStep(makeUsps(3))
    await w.get('[data-test="usp-add"]').trigger('click')
    await flushPromises()
    expect(overlay('[data-test="usp-dialog"]')).not.toBeNull()
    expect(strategyApi.createUsp).not.toHaveBeenCalled()

    await click('[data-test="usp-dialog-save"]')
    expect(strategyApi.createUsp).not.toHaveBeenCalled()
    expect(overlayText()).toContain('Enter a title.')

    type('[data-test="usp-field-title"] input', ' Fast delivery ')
    vi.mocked(strategyApi.createUsp).mockResolvedValue({ data: makeUsp(4) })
    vi.mocked(strategyApi.listUsps).mockResolvedValue({ data: makeUsps(4) })
    await click('[data-test="usp-dialog-save"]')
    expect(strategyApi.createUsp).toHaveBeenCalledTimes(1)
    expect(vi.mocked(strategyApi.createUsp).mock.calls[0]?.[1]).toMatchObject({
      title: 'Fast delivery',
    })
    expect(w.findAll('[data-test="usp-card"]')).toHaveLength(4)
    expect(w.emitted('changed')).toBeTruthy()
    w.unmount()
  })

  it('W5: Edit sends only what changed (a partial PATCH), and nothing when nothing changed', async () => {
    const w = await mountStep(makeUsps(3))
    const card = w.findAll('[data-test="usp-card"]')[1]
    if (card === undefined) throw new Error('no card')
    await card.get('[data-test="usp-menu-btn"]').trigger('click')
    await flushPromises()
    await click('[data-test="usp-menu-edit"]')
    expect(overlay('[data-test="usp-dialog"]')).not.toBeNull()

    await click('[data-test="usp-dialog-save"]')
    expect(strategyApi.updateUsp).not.toHaveBeenCalled()

    await card.get('[data-test="usp-menu-btn"]').trigger('click')
    await flushPromises()
    await click('[data-test="usp-menu-edit"]')
    type('[data-test="usp-field-explainer"] textarea', 'Under 30 minutes')
    vi.mocked(strategyApi.updateUsp).mockResolvedValue({ data: makeUsp(2) })
    await click('[data-test="usp-dialog-save"]')
    expect(strategyApi.updateUsp).toHaveBeenCalledWith(SCOPE, makeUsp(2).id, {
      explainer: 'Under 30 minutes',
    })
    w.unmount()
  })

  it('D3: a save refused because the card was removed shows the alert outside the scrolling body, and Close reloads the grid', async () => {
    const w = await mountStep(makeUsps(3))
    const card = w.findAll('[data-test="usp-card"]')[1]
    if (card === undefined) throw new Error('no card')
    await card.get('[data-test="usp-menu-btn"]').trigger('click')
    await flushPromises()
    await click('[data-test="usp-menu-edit"]')
    type('[data-test="usp-field-explainer"] textarea', 'Under 30 minutes')
    vi.mocked(strategyApi.updateUsp).mockRejectedValue(
      new ApiError({ status: 404, code: 'usp.not_found', message: 'gone' }),
    )
    await click('[data-test="usp-dialog-save"]')

    const alert = overlay('.strategy-dialog-error')
    expect(alert).not.toBeNull()
    // Pinned between the scrolling body and the actions: never inside the body that scrolls and cuts it off.
    expect(alert?.closest('.v-card-text')).toBeNull()
    expect(alert?.closest('.v-dialog--scrollable')).not.toBeNull()
    expect(alert?.textContent).toContain('removed by someone else')

    vi.mocked(strategyApi.listUsps).mockClear()
    await click('[data-test="usp-dialog-cancel"]')
    expect(strategyApi.listUsps).toHaveBeenCalled()
    w.unmount()
  })

  it('W5: hostile text in a title and an explainer is shown as plain text, never as markup', async () => {
    const usps = [makeUsp(1, { title: HOSTILE, explainer: HOSTILE }), ...makeUsps(4).slice(1)]
    const w = await mountStep(usps)
    const title = w.get('[data-test="usp-title"]')
    expect(title.text()).toBe(HOSTILE)
    expect(w.get('[data-test="usp-explainer"]').text()).toContain(HOSTILE)
    expect(w.find('img').exists()).toBe(false)
    expect(document.body.querySelector('img[src="x"]')).toBeNull()
    w.unmount()
  })

  it('W6: Move later sends the FULL ordered list', async () => {
    const usps = makeUsps(4)
    const w = await mountStep(usps)
    vi.mocked(strategyApi.reorderUsps).mockResolvedValue({
      data: [usps[1], usps[0], usps[2], usps[3]].filter((u) => u !== undefined),
    })
    await w.findAll('[data-test="usp-card"]')[0]?.get('[data-test="usp-menu-btn"]').trigger('click')
    await flushPromises()
    await click('[data-test="usp-menu-later"]')
    expect(strategyApi.reorderUsps).toHaveBeenCalledWith(SCOPE, [
      usps[1]?.id,
      usps[0]?.id,
      usps[2]?.id,
      usps[3]?.id,
    ])
    w.unmount()
  })

  it('W6: a refused order shows the message and reloads the grid', async () => {
    const usps = makeUsps(4)
    const w = await mountStep(usps)
    vi.mocked(strategyApi.reorderUsps).mockRejectedValue(
      new ApiError({ status: 422, code: 'usp.order_mismatch', message: 'x' }),
    )
    await w.findAll('[data-test="usp-card"]')[0]?.get('[data-test="usp-menu-btn"]').trigger('click')
    await flushPromises()
    await click('[data-test="usp-menu-later"]')
    expect(w.get('[data-test="usp-notice"]').text()).toContain(
      'USPs changed since this page loaded',
    )
    expect(strategyApi.listUsps).toHaveBeenCalledTimes(2)
    w.unmount()
  })

  it('W7: deleting a USP no angle uses is a plain confirm and sends no angle count', async () => {
    const w = await mountStep(makeUsps(4))
    vi.mocked(strategyApi.removeUsp).mockResolvedValue(undefined as never)
    await w.findAll('[data-test="usp-card"]')[2]?.get('[data-test="usp-menu-btn"]').trigger('click')
    await flushPromises()
    await click('[data-test="usp-menu-delete"]')
    expect(overlayText()).toContain('No persona uses this USP.')
    expect(strategyApi.listPersonas).not.toHaveBeenCalled()
    await click('[data-test="confirm-ok"]')
    expect(strategyApi.removeUsp).toHaveBeenCalledWith(SCOPE, makeUsp(3).id, undefined)
    w.unmount()
  })

  it('W7: deleting a USP in use lists what is affected and sends the count it showed', async () => {
    const used = makeUsp(2, { angle_count: 2 })
    const usps = [makeUsp(1), used, makeUsp(3), makeUsp(4)]
    vi.mocked(strategyApi.listPersonas).mockResolvedValue({
      data: [
        makePersona(1, 'SK', { angles: [makeAngle(used, 'Quick for busy parents')] }),
        makePersona(2, 'RO', { angles: [makeAngle(used, 'Cheap for students')] }),
        makePersona(3, 'RO'),
      ],
    })
    vi.mocked(strategyApi.removeUsp).mockResolvedValue(undefined as never)
    const w = await mountStep(usps)
    await w.findAll('[data-test="usp-card"]')[1]?.get('[data-test="usp-menu-btn"]').trigger('click')
    await flushPromises()
    await click('[data-test="usp-menu-delete"]')
    await flushPromises()

    const list = overlay('[data-test="usp-delete-affected"]')
    expect(list).not.toBeNull()
    expect(list?.textContent).toContain('SK · SKA · Persona 1')
    expect(list?.textContent).toContain('Quick for busy parents')
    expect(list?.textContent).toContain('RO · ROB · Persona 2')
    expect(list?.textContent).toContain('Cheap for students')
    expect(list?.textContent).not.toContain('Persona 3')
    expect(overlay('[data-test="confirm-ok"]')?.textContent).toContain('Delete and remove 2 angles')

    await click('[data-test="confirm-ok"]')
    expect(strategyApi.removeUsp).toHaveBeenCalledWith(SCOPE, used.id, 2)
    w.unmount()
  })

  it('W7: if the server counts differently it answers 409 and the dialog shows the fresh list and asks again', async () => {
    const used = makeUsp(2, { angle_count: 1 })
    vi.mocked(strategyApi.listPersonas).mockResolvedValue({
      data: [makePersona(1, 'SK', { angles: [makeAngle(used, 'First')] })],
    })
    vi.mocked(strategyApi.removeUsp).mockRejectedValue(
      new ApiError({
        status: 409,
        code: 'usp.in_use',
        message: 'x',
        details: [
          {
            code: 'usp.in_use',
            meta: {
              count: 2,
              affected: [
                {
                  persona: 'p1',
                  market: 'SK',
                  code: 'SKA',
                  title: 'Persona 1',
                  explanation: 'First',
                },
                {
                  persona: 'p2',
                  market: 'RO',
                  code: 'ROA',
                  title: 'Persona 2',
                  explanation: 'Second',
                },
              ],
            },
          },
        ],
      }),
    )
    const w = await mountStep([makeUsp(1), used, makeUsp(3), makeUsp(4)])
    await w.findAll('[data-test="usp-card"]')[1]?.get('[data-test="usp-menu-btn"]').trigger('click')
    await flushPromises()
    await click('[data-test="usp-menu-delete"]')
    await flushPromises()
    await click('[data-test="confirm-ok"]')
    expect(overlay('[data-test="usp-delete-refreshed"]')).not.toBeNull()
    expect(overlay('[data-test="usp-delete-affected"]')?.textContent).toContain('Second')
    expect(overlay('[data-test="confirm-ok"]')?.textContent).toContain('Delete and remove 2 angles')
    w.unmount()
  })

  it('W7: staff cannot delete a USP that personas use, and the card says why', async () => {
    const usps = [makeUsp(1, { angle_count: 1 }), ...makeUsps(4).slice(1)]
    const staff = await mountStep(usps, 'agency_staff')
    await staff
      .findAll('[data-test="usp-card"]')[0]
      ?.get('[data-test="usp-menu-btn"]')
      .trigger('click')
    await flushPromises()
    const item = overlay('[data-test="usp-menu-delete"]')
    expect(item?.className).toContain('v-list-item--disabled')
    expect(overlayText()).toContain('Only an admin or manager can delete a USP that personas use.')
    staff.unmount()
  })

  it('the audience title shows as text, and an unset one invites adding', async () => {
    vi.mocked(strategyApi.listUsps).mockResolvedValue({ data: makeUsps(4) })
    const set = await mountStrategy(UspsStep, {
      summary: makeSummary({ audience_title: HOSTILE }),
    })
    await flushPromises()
    expect(set.get('[data-test="audience-title"]').text()).toBe(HOSTILE)
    expect(set.find('img').exists()).toBe(false)
    set.unmount()
    const unset = await mountStep(makeUsps(4))
    expect(unset.get('[data-test="audience-title-empty"]').text()).toBe('Add an audience title')
    unset.unmount()
  })
})
