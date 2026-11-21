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
    listConcepts: vi.fn(),
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
  makeConcept,
  makeSyncedAngle,
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
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(strategyApi.listConcepts).mockResolvedValue({ data: [], meta: { market: null } })
  })
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
    vi.mocked(strategyApi.listPersonas).mockResolvedValue({ data: [] })
    vi.mocked(strategyApi.removeUsp).mockResolvedValue(undefined as never)
    await w.findAll('[data-test="usp-card"]')[2]?.get('[data-test="usp-menu-btn"]').trigger('click')
    await flushPromises()
    await click('[data-test="usp-menu-delete"]')
    // The dialog always reads what uses the USP when it opens (D1): the page's counts can be older than the server.
    expect(strategyApi.listPersonas).toHaveBeenCalledTimes(1)
    expect(overlayText()).toContain('No persona uses this USP.')
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
    expect(list?.textContent).toContain('Slovakia · SKA · Persona 1')
    expect(list?.textContent).toContain('Quick for busy parents')
    expect(list?.textContent).toContain('Romania · ROB · Persona 2')
    expect(list?.textContent).toContain('Cheap for students')
    expect(list?.textContent).not.toContain('Persona 3')
    expect(overlay('[data-test="confirm-ok"]')?.textContent).toContain('Delete and remove 2 angles')

    await click('[data-test="confirm-ok"]')
    expect(strategyApi.removeUsp).toHaveBeenCalledWith(SCOPE, used.id, 2)
    w.unmount()
  })

  it('W7 (PL-2.2): a USP used by cells says so on the card and lists each cell once, with its level, in the dialog', async () => {
    const used = makeUsp(2, { angle_count: 1, cell_count: 1 })
    vi.mocked(strategyApi.listPersonas).mockResolvedValue({
      data: [
        makePersona(1, 'SK', {
          angles: [makeSyncedAngle(used, 'Lead line')],
          matrix_cells: [{ usp: used.id, level: 'priority', message_line: 'Lead line' }],
        }),
        makePersona(2, 'RO'),
      ],
    })
    vi.mocked(strategyApi.removeUsp).mockResolvedValue(undefined as never)
    const w = await mountStep([makeUsp(1), used, makeUsp(3), makeUsp(4)])
    const card = w.findAll('[data-test="usp-card"]')[1]
    expect(card?.get('[data-test="usp-angle-count"]').text()).toBe('Used by 1 angle and 1 cell')

    await card?.get('[data-test="usp-menu-btn"]').trigger('click')
    await flushPromises()
    await click('[data-test="usp-menu-delete"]')
    await flushPromises()
    const list = overlay('[data-test="usp-delete-affected"]')
    expect(list?.querySelectorAll('li')).toHaveLength(1)
    expect(list?.textContent).toContain('Slovakia · SKA · Persona 1')
    expect(list?.textContent).toContain('Priority')
    expect(overlay('[data-test="confirm-ok"]')?.textContent).toContain('Delete and clear 1 item')
    await click('[data-test="confirm-ok"]')
    expect(strategyApi.removeUsp).toHaveBeenCalledWith(SCOPE, used.id, 1)
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
                  kind: 'angle',
                  level: null,
                  persona: 'p1',
                  market: 'SK',
                  code: 'SKA',
                  title: 'Persona 1',
                  explanation: 'First',
                },
                {
                  kind: 'angle',
                  level: null,
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

  describe.each([
    ['D1-a: cells only', { cell_count: 2 }],
    ['D1-b: angles only', { angle_count: 2 }],
    ['D1-c: cells and angles', { angle_count: 1, cell_count: 2 }],
  ])('%s', (_name, counts) => {
    it('staff: Delete is disabled with the reason, a click sends nothing', async () => {
      const usps = [makeUsp(1, counts), ...makeUsps(4).slice(1)]
      const staff = await mountStep(usps, 'agency_staff')
      await staff
        .findAll('[data-test="usp-card"]')[0]
        ?.get('[data-test="usp-menu-btn"]')
        .trigger('click')
      await flushPromises()
      expect(overlay('[data-test="usp-menu-delete"]')?.className).toContain('v-list-item--disabled')
      expect(overlay('[data-test="usp-delete-reason"]')).not.toBeNull()
      await click('[data-test="usp-menu-delete"]')
      expect(strategyApi.listPersonas).not.toHaveBeenCalled() // the dialog never opened
      expect(overlay('[data-test="confirm-ok"]')).toBeNull()
      expect(strategyApi.removeUsp).not.toHaveBeenCalled()
      staff.unmount()
    })

    it('admin and manager: Delete is offered', async () => {
      for (const role of ['agency_admin', 'agency_manager'] as const) {
        const usps = [makeUsp(1, counts), ...makeUsps(4).slice(1)]
        const w = await mountStep(usps, role)
        await w
          .findAll('[data-test="usp-card"]')[0]
          ?.get('[data-test="usp-menu-btn"]')
          .trigger('click')
        await flushPromises()
        expect(overlay('[data-test="usp-menu-delete"]')?.className).not.toContain(
          'v-list-item--disabled',
        )
        w.unmount()
        document.body.innerHTML = ''
      }
    })
  })

  it('D1: staff on a page that showed the USP as unused: the dialog reads the truth, says why and offers no Delete', async () => {
    const usps = makeUsps(4)
    const target = usps[0] ?? makeUsp(1)
    vi.mocked(strategyApi.listPersonas).mockResolvedValue({
      data: [
        makePersona(1, 'SK', {
          angles: [makeSyncedAngle(target, 'Lead line')],
          matrix_cells: [{ usp: target.id, level: 'priority', message_line: 'Lead line' }],
        }),
      ],
    })
    const staff = await mountStep(usps, 'agency_staff')
    await staff
      .findAll('[data-test="usp-card"]')[0]
      ?.get('[data-test="usp-menu-btn"]')
      .trigger('click')
    await flushPromises()
    expect(overlay('[data-test="usp-menu-delete"]')?.className).not.toContain(
      'v-list-item--disabled',
    )
    await click('[data-test="usp-menu-delete"]')
    await flushPromises()
    expect(overlay('[data-test="usp-delete-staff-reason"]')).not.toBeNull()
    expect(overlay('[data-test="confirm-ok"]')).toBeNull()
    expect(strategyApi.removeUsp).not.toHaveBeenCalled()
    staff.unmount()
  })

  it('D1: if the server refuses staff (the list was older still), the dialog says why and the page reloads its list', async () => {
    vi.mocked(strategyApi.listPersonas).mockResolvedValue({ data: [] })
    vi.mocked(strategyApi.removeUsp).mockRejectedValue(
      new ApiError({ status: 403, code: 'forbidden', message: 'x' }),
    )
    const staff = await mountStep(makeUsps(4), 'agency_staff')
    await staff
      .findAll('[data-test="usp-card"]')[0]
      ?.get('[data-test="usp-menu-btn"]')
      .trigger('click')
    await flushPromises()
    await click('[data-test="usp-menu-delete"]')
    await flushPromises()
    await click('[data-test="confirm-ok"]')
    await flushPromises()
    expect(overlay('[data-test="confirm-error"]')?.textContent).toContain(
      'Only an admin or manager can delete a USP that personas use.',
    )
    expect(strategyApi.listUsps).toHaveBeenCalledTimes(2)
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

  it('PL-2.3: deleting a USP lists the concepts that carry it as a chip, with the note, and counts them', async () => {
    const used = makeUsp(2)
    vi.mocked(strategyApi.listPersonas).mockResolvedValue({ data: [] })
    vi.mocked(strategyApi.listConcepts).mockImplementation(async (_s, market) => ({
      data:
        market === 'SK'
          ? [
              makeConcept(7, makePersona(1, 'SK'), {
                title: HOSTILE,
                usps: [{ id: used.id, title: used.attributes.title, color_key: 'violet' }],
              }),
              makeConcept(8, makePersona(1, 'SK')),
            ]
          : [],
      meta: { market: null },
    }))
    vi.mocked(strategyApi.removeUsp).mockResolvedValue(undefined as never)
    const w = await mountStep([makeUsp(1), used, makeUsp(3), makeUsp(4)])
    await w.findAll('[data-test="usp-card"]')[1]?.get('[data-test="usp-menu-btn"]').trigger('click')
    await flushPromises()
    await click('[data-test="usp-menu-delete"]')
    await flushPromises()
    const list = overlay('[data-test="usp-delete-affected"]')
    expect(list?.querySelectorAll('li')).toHaveLength(1)
    expect(list?.textContent).toContain('Concept 7')
    expect(list?.textContent).toContain(HOSTILE)
    expect(list?.querySelector('img')).toBeNull()
    expect(overlay('[data-test="usp-delete-concepts-note"]')).not.toBeNull()
    expect(overlay('[data-test="confirm-ok"]')?.textContent).toContain('Delete and clear 1 item')
    w.unmount()
  })
})
