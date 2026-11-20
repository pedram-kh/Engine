/**
 * PL-2.1 W8-W11 — step 2, Personas: market tabs from the wave's markets, "n of 8 used in [market]", warning under
 * two, pronoun-driven labels, angles with the USP dropdown ("Linked" / "Manually added"), copy from another market
 * with a tick list, delete with the angle count, and hostile text rendered as plain text.
 */
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/strategy.api', () => ({
  strategyApi: {
    listUsps: vi.fn(),
    listPersonas: vi.fn(),
    createPersona: vi.fn(),
    updatePersona: vi.fn(),
    removePersona: vi.fn(),
    copyPersonas: vi.fn(),
    addAngle: vi.fn(),
    updateAngle: vi.fn(),
    removeAngle: vi.fn(),
  },
}))

import { strategyApi } from '../api/strategy.api'
import {
  AGENCY_ULID,
  BRAND_ULID,
  makeAngle,
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
import PersonasStep from './PersonasStep.vue'

const SCOPE = { agencyId: AGENCY_ULID, brandId: BRAND_ULID, waveId: WAVE_ULID }
const HOSTILE = '<img src=x onerror=alert(1)>'
const ROUTE = `/brands/${BRAND_ULID}/waves/${WAVE_ULID}/strategy/personas`

function many(count: number, market: string, from = 1) {
  return Array.from({ length: count }, (_, i) => makePersona(from + i, market))
}

async function mountStep(
  personas: ReturnType<typeof makePersona>[],
  usps = makeUsps(4),
  route = ROUTE,
  markets = ['SK', 'RO'],
) {
  vi.mocked(strategyApi.listPersonas).mockResolvedValue({ data: personas })
  vi.mocked(strategyApi.listUsps).mockResolvedValue({ data: usps })
  const w = await mountStrategy(
    PersonasStep,
    {
      summary: makeSummary({ markets: markets.map((m) => ({ market: m, persona_count: 0 })) }),
      markets,
    },
    { route },
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

describe('PersonasStep (PL-2.1)', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('W8: one tab per market of the wave, in order, with counts and a dot under two', async () => {
    const w = await mountStep([...many(2, 'SK'), ...many(1, 'RO', 3)])
    const tabs = w.findAll('[role="tab"]')
    expect(tabs.map((t) => t.attributes('data-test'))).toEqual(['market-tab-SK', 'market-tab-RO'])
    expect(w.get('[data-test="market-count-SK"]').text()).toBe('2')
    expect(w.get('[data-test="market-count-RO"]').text()).toBe('1')
    expect(w.find('[data-test="market-dot-SK"]').exists()).toBe(false)
    expect(w.find('[data-test="market-dot-RO"]').exists()).toBe(true)
    expect(w.get('[data-test="market-tab-SK"]').attributes('aria-selected')).toBe('true')
    w.unmount()
  })

  it('W8: the market comes from the address, an unknown one falls back to the first, a tab click writes it', async () => {
    const ro = await mountStep(
      many(2, 'SK').concat(many(2, 'RO', 3)),
      makeUsps(4),
      `${ROUTE}?market=RO`,
    )
    expect(ro.get('[data-test="market-tab-RO"]').attributes('aria-selected')).toBe('true')
    expect(ro.findAll('[data-test="persona-card"]')).toHaveLength(2)
    expect(ro.get('[data-test="persona-code"]').text()).toBe('ROC')
    ro.unmount()

    vi.mocked(strategyApi.listPersonas).mockClear()
    const bad = await mountStep(many(2, 'SK'), makeUsps(4), `${ROUTE}?market=XX`)
    expect(bad.get('[data-test="market-tab-SK"]').attributes('aria-selected')).toBe('true')
    await bad.get('[data-test="market-tab-RO"]').trigger('click')
    await flushPromises()
    await new Promise((r) => setTimeout(r, 50))
    expect(bad.vm.$router.currentRoute.value.query.market).toBe('RO')
    expect(strategyApi.listPersonas).toHaveBeenCalledTimes(1)
    bad.unmount()
  })

  it('W9: the tile counts n of 8 in the market, the warning shows under two, and Add is off at eight', async () => {
    const one = await mountStep(many(1, 'SK'))
    expect(one.get('[data-test="persona-tile-text"]').text()).toBe('1 of 8 used in Slovakia')
    expect(one.get('[data-test="persona-warning"]').text()).toBe(
      'Add at least 2 personas for Slovakia.',
    )
    one.unmount()

    const three = await mountStep(many(3, 'SK'))
    expect(three.find('[data-test="persona-warning"]').exists()).toBe(false)
    three.unmount()

    const eight = await mountStep(many(8, 'SK'))
    expect(eight.get('[data-test="persona-tile-text"]').text()).toBe(
      '8 of 8 used in Slovakia · maximum reached',
    )
    expect(eight.get('[data-test="persona-add"]').attributes('disabled')).toBeDefined()
    expect(eight.get('[data-test="persona-tile"]').attributes('disabled')).toBeDefined()
    expect(eight.get('[data-test="persona-copy"]').attributes('disabled')).toBeDefined()
    eight.unmount()
  })

  it('W9: labels follow the pronoun', async () => {
    const people = [
      makePersona(1, 'SK', { pronoun: 'she', self_talk: ['a'], need: 'n' }),
      makePersona(2, 'SK', { pronoun: 'he', self_talk: ['a'], need: 'n' }),
      makePersona(3, 'SK', { pronoun: 'they', self_talk: ['a'], need: 'n' }),
    ]
    const w = await mountStep(people)
    const text = w.findAll('[data-test="persona-card"]').map((c) => c.text())
    expect(text[0]).toContain('What she tells herself')
    expect(text[0]).toContain('What she needs')
    expect(text[1]).toContain('What he tells himself')
    expect(text[1]).toContain('What he needs')
    expect(text[2]).toContain('What they tell themselves')
    expect(text[2]).toContain('What they need')
    w.unmount()
  })

  it('W9: add persona opens a dialog and sends nothing until Save; an empty title and name are refused', async () => {
    const w = await mountStep(many(2, 'SK'))
    await w.get('[data-test="persona-add"]').trigger('click')
    await flushPromises()
    expect(overlay('[data-test="persona-dialog"]')).not.toBeNull()
    await click('[data-test="persona-dialog-save"]')
    expect(strategyApi.createPersona).not.toHaveBeenCalled()
    expect(overlayText()).toContain('Enter a title.')
    expect(overlayText()).toContain('Enter a name.')
    w.unmount()
  })

  it('W10: angles show Manually added; the USP list marks a linked USP and offers the others', async () => {
    const usps = makeUsps(3)
    const first = usps[0] ?? makeUsp(1)
    const w = await mountStep(
      [
        makePersona(1, 'SK', { angles: [makeAngle(first, 'Fast for busy parents')] }),
        ...many(1, 'SK', 2),
      ],
      usps,
    )
    const card = w.findAll('[data-test="persona-card"]')[0]
    if (card === undefined) throw new Error('no card')
    expect(card.get('[data-test="angle-origin"]').text()).toBe('Manually added')
    expect(card.get('[data-test="angle-explanation"]').text()).toBe('Fast for busy parents')

    await card.get('[data-test="angle-link-btn"]').trigger('click')
    await flushPromises()
    expect(document.body.querySelectorAll('[data-test="angle-usp-linked"]')).toHaveLength(1)
    expect(document.body.querySelectorAll('[data-test="angle-usp-option"]')).toHaveLength(2)
    expect(document.body.querySelector('[data-test="angle-usp-linked"]')?.textContent).toContain(
      'Linked',
    )
    w.unmount()
  })

  it('W10: choosing a USP opens an inline row; Add sends the USP and the text, and an empty line is refused', async () => {
    const usps = makeUsps(3)
    const w = await mountStep(many(2, 'SK'), usps)
    const card = w.findAll('[data-test="persona-card"]')[0]
    if (card === undefined) throw new Error('no card')
    await card.get('[data-test="angle-link-btn"]').trigger('click')
    await flushPromises()
    await click('[data-test="angle-usp-option"]')
    expect(card.find('[data-test="angle-add-row"]').exists()).toBe(true)

    await card.get('[data-test="angle-add"]').trigger('click')
    await flushPromises()
    expect(strategyApi.addAngle).not.toHaveBeenCalled()
    expect(card.text()).toContain('Enter a line.')

    const field = card.get('[data-test="angle-text"] textarea, [data-test="angle-text"] input')
    await field.setValue('Saves them an evening')
    vi.mocked(strategyApi.addAngle).mockResolvedValue({ data: makeAngle(makeUsp(1)) })
    await card.get('[data-test="angle-add"]').trigger('click')
    await flushPromises()
    expect(strategyApi.addAngle).toHaveBeenCalledWith(
      SCOPE,
      makePersona(1, 'SK').id,
      makeUsp(1).id,
      'Saves them an evening',
    )
    w.unmount()
  })

  it('W10: with no USPs the list says to add them in step 1', async () => {
    const w = await mountStep(many(2, 'SK'), [])
    await w
      .findAll('[data-test="persona-card"]')[0]
      ?.get('[data-test="angle-link-btn"]')
      .trigger('click')
    await flushPromises()
    expect(document.body.querySelector('[data-test="angle-no-usps"]')?.textContent).toContain(
      'Add USPs in step 1 first.',
    )
    w.unmount()
  })

  it('W11: copy shows the live line, unticking changes it, over the maximum it is off', async () => {
    const w = await mountStep([...many(3, 'RO'), ...many(1, 'SK', 4)])
    await w.get('[data-test="persona-copy"]').trigger('click')
    await flushPromises()
    expect(overlay('[data-test="copy-dialog"]')).not.toBeNull()
    expect(overlay('[data-test="copy-line"]')?.textContent?.trim()).toBe(
      'Copying 3 → Slovakia will have 4 of 8',
    )
    expect(document.body.querySelectorAll('[data-test="copy-item"]')).toHaveLength(3)
    w.unmount()

    const full = await mountStep([...many(6, 'SK'), ...many(3, 'RO', 7)])
    await full.get('[data-test="persona-copy"]').trigger('click')
    await flushPromises()
    expect(overlay('[data-test="copy-line"]')?.textContent).toContain('would have 9 of 8')
    expect(overlay('[data-test="copy-submit"]')?.hasAttribute('disabled')).toBe(true)
    full.unmount()
  })

  it('W11: copy sends all personas of the source when every one is ticked', async () => {
    const w = await mountStep([...many(2, 'RO'), ...many(1, 'SK', 3)])
    vi.mocked(strategyApi.copyPersonas).mockResolvedValue({ data: [] })
    await w.get('[data-test="persona-copy"]').trigger('click')
    await flushPromises()
    await click('[data-test="copy-submit"]')
    expect(strategyApi.copyPersonas).toHaveBeenCalledWith(SCOPE, {
      from_market: 'RO',
      to_market: 'SK',
    })
    w.unmount()
  })

  it('W11: delete says how many angles go with the persona, and sends the delete only on confirm', async () => {
    const usps = makeUsps(3)
    const first = usps[0] ?? makeUsp(1)
    const second = usps[1] ?? makeUsp(2)
    const people = [
      makePersona(1, 'SK', { angles: [makeAngle(first), makeAngle(second)] }),
      ...many(2, 'SK', 2),
    ]
    const w = await mountStep(people, usps)
    vi.mocked(strategyApi.removePersona).mockResolvedValue(undefined as never)
    await w
      .findAll('[data-test="persona-card"]')[0]
      ?.get('[data-test="persona-menu-btn"]')
      .trigger('click')
    await flushPromises()
    await click('[data-test="persona-menu-delete"]')
    expect(overlayText()).toContain('The persona and its 2 angles are removed.')
    expect(strategyApi.removePersona).not.toHaveBeenCalled()
    await click('[data-test="confirm-ok"]')
    expect(strategyApi.removePersona).toHaveBeenCalledWith(SCOPE, people[0]?.id)
    w.unmount()

    const plain = await mountStep(many(3, 'SK'))
    await plain
      .findAll('[data-test="persona-card"]')[0]
      ?.get('[data-test="persona-menu-btn"]')
      .trigger('click')
    await flushPromises()
    await click('[data-test="persona-menu-delete"]')
    expect(overlayText()).toContain('The persona is removed.')
    plain.unmount()
  })

  it('hostile text in every persona text field is shown as plain text, never as markup', async () => {
    const hostile = makePersona(1, 'SK', {
      title: HOSTILE,
      name: HOSTILE,
      summary: HOSTILE,
      core_job: HOSTILE,
      need: HOSTILE,
      drivers: [HOSTILE],
      goals: [HOSTILE],
      self_talk: [HOSTILE],
      barriers: [HOSTILE],
      angles: [makeAngle(makeUsp(1), HOSTILE)],
    })
    const w = await mountStep([hostile, ...many(1, 'SK', 2)])
    const card = w.findAll('[data-test="persona-card"]')[0]
    expect(card?.get('[data-test="persona-title"]').text()).toBe(HOSTILE)
    expect(card?.text()).toContain(HOSTILE)
    expect(w.find('img').exists()).toBe(false)
    expect(document.body.querySelector('img[src="x"]')).toBeNull()
    w.unmount()
  })

  it('a failed load shows the error and turns Add off', async () => {
    vi.mocked(strategyApi.listPersonas).mockRejectedValue(new Error('boom'))
    vi.mocked(strategyApi.listUsps).mockRejectedValue(new Error('boom'))
    const w = await mountStrategy(
      PersonasStep,
      { summary: makeSummary(), markets: ['SK', 'RO'] },
      { route: ROUTE },
    )
    await flushPromises()
    expect(w.get('[data-test="personas-error"]').text()).toBe('Could not load this. Try again.')
    expect(w.get('[data-test="persona-add"]').attributes('disabled')).toBeDefined()
    w.unmount()
  })

  it('W12 (PL-2.2): a synced angle says "Synced from matrix", is read-only, links to the matrix and is plain text', async () => {
    const usps = makeUsps(3)
    const first = usps[0] ?? makeUsp(1)
    const w = await mountStep(
      [
        makePersona(1, 'SK', {
          angles: [makeSyncedAngle(first, HOSTILE), makeAngle(usps[1] ?? makeUsp(2), 'By hand')],
        }),
        ...many(1, 'SK', 2),
      ],
      usps,
    )
    const card = w.findAll('[data-test="persona-card"]')[0]
    if (card === undefined) throw new Error('no card')
    const origins = card.findAll('[data-test="angle-origin"]')
    expect(origins[0]?.text()).toBe('Synced from matrix')
    expect(origins[1]?.text()).toBe('Manually added')
    expect(card.findAll('[data-test="angle-explanation"]')[0]?.text()).toBe(HOSTILE)
    expect(card.find('img').exists()).toBe(false)
    expect(card.findAll('[data-test="angle-matrix-link"]')).toHaveLength(1)
    expect(card.findAll('[data-test="angle-menu-btn"]')).toHaveLength(1)
    w.unmount()
  })
})
