/**
 * PL-2.3 — step 4, Concepts: the market banner and its states, the under-three warning, the 30 ceiling, the card in each
 * status, the menu by role, the confirmation before an Approved concept goes back to Awaiting (A1: edit, image, link;
 * Cancel changes nothing), references (uploads refused before any request, https-only links with a safe anchor), the
 * decision dialog, and hostile text rendered as plain text.
 */
import type { ConceptMarketCounts, WaveConceptResource } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/strategy.api', () => ({
  strategyApi: {
    listUsps: vi.fn(),
    listPersonas: vi.fn(),
    listConcepts: vi.fn(),
    createConcept: vi.fn(),
    updateConcept: vi.fn(),
    duplicateConcept: vi.fn(),
    removeConcept: vi.fn(),
    decideConcept: vi.fn(),
    initImage: vi.fn(),
    completeImage: vi.fn(),
    removeImage: vi.fn(),
    imageBlob: vi.fn(),
    addLink: vi.fn(),
    updateLink: vi.fn(),
    removeLink: vi.fn(),
  },
}))

import { strategyApi } from '../api/strategy.api'
import {
  AGENCY_ULID,
  BRAND_ULID,
  makeConcept,
  makePersona,
  makeSummary,
  makeUsps,
  mountStrategy,
  overlay,
  WAVE_ULID,
  mkt,
} from '../strategyTestKit'
import ConceptsStep from './ConceptsStep.vue'

const SCOPE = { agencyId: AGENCY_ULID, brandId: BRAND_ULID, waveId: WAVE_ULID }
const ROUTE = `/brands/${BRAND_ULID}/waves/${WAVE_ULID}/strategy/concepts`
const HOSTILE = '<img src=x onerror=alert(1)>'
const persona = makePersona(1, 'SK')
const personaB = makePersona(2, 'SK')
const decision = (
  over: Partial<NonNullable<WaveConceptResource['attributes']['decision']>> = {},
) => ({
  id: 'D1',
  status: 'approved' as const,
  client_name: 'Jana Nováková',
  note: null,
  comment: null,
  source: 'agency',
  recorded_at: '2026-11-02T09:00:00.000000Z',
  recorded_by: { id: 'U1', name: 'Ada' },
  ...over,
})

function counts(list: WaveConceptResource[]): ConceptMarketCounts {
  const n = (s: string) => list.filter((c) => c.attributes.status === s).length
  return {
    market: 'SK',
    total: list.length,
    approved: n('approved'),
    awaiting: n('awaiting'),
    changes_requested: n('changes_requested'),
    complete: list.length > 0 && n('approved') === list.length,
    under_minimum: list.length > 0 && list.length < 3,
  }
}

async function mountStep(
  list: WaveConceptResource[],
  role: 'agency_admin' | 'agency_manager' | 'agency_staff' = 'agency_admin',
) {
  vi.mocked(strategyApi.listPersonas).mockResolvedValue({ data: [persona, personaB] })
  vi.mocked(strategyApi.listUsps).mockResolvedValue({ data: makeUsps(3) })
  vi.mocked(strategyApi.listConcepts).mockResolvedValue({
    data: list,
    meta: { market: counts(list) },
  })
  const w = await mountStrategy(
    ConceptsStep,
    {
      summary: makeSummary({
        persona_count: 2,
        markets: [mkt('SK', 2), mkt('RO', 0)],
      }),
      brandName: 'Acme Corp',
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

async function pick(input: HTMLInputElement, files: File[]): Promise<void> {
  Object.defineProperty(input, 'files', { value: files, configurable: true })
  input.dispatchEvent(new Event('change', { bubbles: true }))
  await flushPromises()
}

describe('ConceptsStep (PL-2.3)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(strategyApi.imageBlob).mockRejectedValue(new Error('no image'))
  })
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('banner: amber with the counts, green when every concept is approved, none with no concept (the empty state)', async () => {
    const mixed = await mountStep([
      makeConcept(1, persona, { decision: decision() }, 'approved'),
      makeConcept(2, persona),
      makeConcept(
        3,
        persona,
        { decision: decision({ status: 'changes_requested', comment: 'Warmer' }) },
        'changes_requested',
      ),
    ])
    const banner = mixed.get('[data-test="concepts-banner"]')
    expect(banner.attributes('data-state')).toBe('amber')
    expect(banner.text()).toContain('1 of 3 approved · 1 awaiting client · 1 changes requested')
    expect(mixed.find('[data-test="concepts-under-minimum"]').exists()).toBe(false)
    mixed.unmount()

    const green = await mountStep([
      makeConcept(1, persona, { decision: decision() }, 'approved'),
      makeConcept(2, persona, { decision: decision() }, 'approved'),
    ])
    expect(green.get('[data-test="concepts-banner"]').attributes('data-state')).toBe('green')
    expect(green.get('[data-test="concepts-banner"]').text()).toContain(
      'Every concept approved (2)',
    )
    // Under three is a warning, never a block: Add stays on.
    expect(green.get('[data-test="concepts-under-minimum"]').text()).toContain('2 of the 3 or more')
    expect(green.get('[data-test="concept-add"]').attributes('disabled')).toBeUndefined()
    green.unmount()

    const empty = await mountStep([])
    expect(empty.find('[data-test="concepts-banner"]').exists()).toBe(false)
    expect(empty.find('[data-test="concepts-under-minimum"]').exists()).toBe(false)
    expect(empty.find('[data-test="concepts-empty"]').exists()).toBe(true)
    expect(empty.find('[data-test="concepts-empty-cta"]').exists()).toBe(true)
    empty.unmount()
  })

  it('a market with no persona says to add personas first and offers no Add', async () => {
    vi.mocked(strategyApi.listPersonas).mockResolvedValue({ data: [] })
    vi.mocked(strategyApi.listUsps).mockResolvedValue({ data: [] })
    vi.mocked(strategyApi.listConcepts).mockResolvedValue({ data: [], meta: { market: null } })
    const w = await mountStrategy(
      ConceptsStep,
      { summary: makeSummary(), markets: ['SK'], brandName: 'Acme' },
      { route: ROUTE },
    )
    await flushPromises()
    expect(w.find('[data-test="concepts-empty-personas"]').exists()).toBe(true)
    expect(w.find('[data-test="concepts-empty-cta"]').exists()).toBe(false)
    expect(w.get('[data-test="concept-add"]').attributes('disabled')).toBeDefined()
    w.unmount()
  })

  it('30 ceiling: at 30 both Add controls are off and the tile says so', async () => {
    const list = Array.from({ length: 30 }, (_, i) => makeConcept(i + 1, persona))
    const w = await mountStep(list)
    expect(w.get('[data-test="concept-add"]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-test="concept-tile"]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-test="concept-tile-text"]').text()).toBe(
      'Maximum reached: 30 of 30 concepts',
    )
    w.unmount()
  })

  it('cards: Approved shows who and when; Changes requested shows the comment and is marked; both draw text, never HTML', async () => {
    const w = await mountStep([
      makeConcept(
        1,
        persona,
        {
          title: HOSTILE,
          hook: HOSTILE,
          key_points: [HOSTILE],
          decision: decision({ client_name: HOSTILE, note: HOSTILE }),
        },
        'approved',
      ),
      makeConcept(
        2,
        persona,
        {
          decision: decision({
            status: 'changes_requested',
            client_name: 'Tomáš',
            comment: HOSTILE,
          }),
        },
        'changes_requested',
      ),
    ])
    const cards = w.findAll('[data-test^="concept-card-"]')
    expect(cards[0]?.attributes('data-status')).toBe('approved')
    expect(cards[0]?.get('[data-test="concept-footer-approved"]').text()).toContain(
      `Approved by ${HOSTILE} ·`,
    )
    expect(cards[1]?.attributes('data-status')).toBe('changes_requested')
    expect(cards[1]?.classes()).toContain('concept-card--changes_requested')
    expect(cards[1]?.get('[data-test="concept-comment"]').text()).toBe(HOSTILE)
    expect(cards[1]?.get('[data-test="concept-footer-changes"]').text()).toContain(
      'Tomáš · Acme Corp ·',
    )
    expect(w.find('img[src="x"]').exists()).toBe(false)
    expect(w.find('[data-test="concepts-grid"] img').exists()).toBe(false)
    expect(w.get('[data-test="concept-title"]').text()).toBe(HOSTILE)
    w.unmount()
  })

  it('menu by role: staff cannot record a decision and cannot delete a decided concept', async () => {
    const list = [
      makeConcept(1, persona, { decision: decision() }, 'approved'),
      makeConcept(2, persona),
    ]
    const staff = await mountStep(list, 'agency_staff')
    expect(staff.find('[data-test="concepts-record-decision"]').exists()).toBe(false)
    await staff.findAll('[data-test="concept-menu"]')[0]?.trigger('click')
    await flushPromises()
    expect(overlay('[data-test="concept-menu-decide"]')).toBeNull()
    expect(overlay('[data-test="concept-menu-delete"]')?.className).toContain(
      'v-list-item--disabled',
    )
    staff.unmount()
    document.body.innerHTML = ''

    const admin = await mountStep(list, 'agency_manager')
    expect(admin.find('[data-test="concepts-record-decision"]').exists()).toBe(true)
    await admin.findAll('[data-test="concept-menu"]')[0]?.trigger('click')
    await flushPromises()
    expect(overlay('[data-test="concept-menu-decide"]')).not.toBeNull()
    expect(overlay('[data-test="concept-menu-delete"]')?.className).not.toContain(
      'v-list-item--disabled',
    )
    admin.unmount()
  })

  it('A1: editing an Approved concept asks first; Cancel changes nothing; OK saves', async () => {
    const approved = makeConcept(1, persona, { decision: decision() }, 'approved')
    vi.mocked(strategyApi.updateConcept).mockResolvedValue({
      data: makeConcept(1, persona, { title: 'New' }),
    })
    const w = await mountStep([approved])
    await w.get('[data-test="concept-menu"]').trigger('click')
    await flushPromises()
    await click('[data-test="concept-menu-edit"]')
    type('[data-test="concept-field-title"] input', 'New title')
    await click('[data-test="concept-dialog-save"]')

    expect(overlay('[data-test="concept-reset-dialog"]')).not.toBeNull()
    expect(overlay('[data-test="concept-reset-dialog"]')?.textContent).toContain(
      "clears the client's approval",
    )
    await click('[data-test="concept-reset-dialog"] [data-test="confirm-cancel"]')
    expect(strategyApi.updateConcept).not.toHaveBeenCalled()
    expect(overlay('[data-test="concept-dialog"]')).not.toBeNull()

    await click('[data-test="concept-dialog-save"]')
    await click('[data-test="concept-reset-dialog"] [data-test="confirm-ok"]')
    expect(strategyApi.updateConcept).toHaveBeenCalledTimes(1)
    expect(vi.mocked(strategyApi.updateConcept).mock.calls[0]?.[2]).toEqual({ title: 'New title' })
    w.unmount()
  })

  it('A1: an edit that changes nothing neither asks nor writes; an Awaiting concept is saved without asking', async () => {
    const approved = makeConcept(1, persona, { decision: decision() }, 'approved')
    const w = await mountStep([approved])
    await w.get('[data-test="concept-menu"]').trigger('click')
    await flushPromises()
    await click('[data-test="concept-menu-edit"]')
    await click('[data-test="concept-dialog-save"]')
    expect(overlay('[data-test="concept-reset-dialog"]')).toBeNull()
    expect(strategyApi.updateConcept).not.toHaveBeenCalled()
    w.unmount()
    document.body.innerHTML = ''

    vi.mocked(strategyApi.updateConcept).mockResolvedValue({ data: makeConcept(1, persona) })
    const aw = await mountStep([makeConcept(1, persona)])
    await aw.get('[data-test="concept-menu"]').trigger('click')
    await flushPromises()
    await click('[data-test="concept-menu-edit"]')
    type('[data-test="concept-field-title"] input', 'Changed')
    await click('[data-test="concept-dialog-save"]')
    expect(overlay('[data-test="concept-reset-dialog"]')).toBeNull()
    expect(strategyApi.updateConcept).toHaveBeenCalledTimes(1)
    aw.unmount()
  })

  it('A1: removing a link or an image from an Approved concept asks first; Cancel removes nothing', async () => {
    const approved = makeConcept(
      1,
      persona,
      {
        decision: decision(),
        links: [{ id: 'L1', url: 'https://example.com/a', title: 'Brief', domain: 'example.com' }],
        images: [
          {
            id: 'I1',
            original_filename: 'a.jpg',
            mime_type: 'image/jpeg',
            size_bytes: 100,
            has_thumbnail: false,
            file_url: '/api/v1/x',
            thumbnail_url: null,
          },
        ],
      },
      'approved',
    )
    vi.mocked(strategyApi.removeLink).mockResolvedValue({ meta: { reset_to_awaiting: true } })
    vi.mocked(strategyApi.removeImage).mockResolvedValue({ meta: { reset_to_awaiting: true } })
    const w = await mountStep([approved])
    await w.get('[data-test="concept-link-remove"]').trigger('click')
    await flushPromises()
    expect(overlay('[data-test="concept-reset-dialog"]')).not.toBeNull()
    await click('[data-test="concept-reset-dialog"] [data-test="confirm-cancel"]')
    expect(strategyApi.removeLink).not.toHaveBeenCalled()

    await w.get('[data-test="concept-image-remove"]').trigger('click')
    await flushPromises()
    expect(strategyApi.removeImage).not.toHaveBeenCalled()
    await click('[data-test="concept-reset-dialog"] [data-test="confirm-ok"]')
    expect(strategyApi.removeImage).toHaveBeenCalledTimes(1)
    w.unmount()
  })

  it('A1: adding a link or an image to an Approved concept asks first; Cancel opens and uploads nothing', async () => {
    const w = await mountStep([makeConcept(1, persona, { decision: decision() }, 'approved')])
    await w.get('[data-test="concept-link-add"]').trigger('click')
    await flushPromises()
    expect(overlay('[data-test="concept-reset-dialog"]')).not.toBeNull()
    await click('[data-test="concept-reset-dialog"] [data-test="confirm-cancel"]')
    expect(overlay('[data-test="concept-link-dialog"]')).toBeNull()

    const input = w.get('[data-test="concept-image-input"]').element as HTMLInputElement
    const picked = pick(input, [new File(['x'], 'ok.jpg', { type: 'image/jpeg' })])
    await flushPromises()
    expect(overlay('[data-test="concept-reset-dialog"]')).not.toBeNull()
    await click('[data-test="concept-reset-dialog"] [data-test="confirm-cancel"]')
    await picked
    expect(strategyApi.initImage).not.toHaveBeenCalled()
    w.unmount()
  })

  it('links: https opens in a new tab with noopener noreferrer; a stored non-https address is text, never an href', async () => {
    const w = await mountStep([
      makeConcept(1, persona, {
        links: [
          { id: 'L1', url: 'https://example.com/a', title: 'Brief', domain: 'example.com' },
          { id: 'L2', url: 'javascript:alert(1)', title: HOSTILE, domain: '' },
        ],
      }),
    ])
    const anchors = w.findAll('[data-test="concept-link-anchor"]')
    expect(anchors).toHaveLength(1)
    expect(anchors[0]?.attributes('target')).toBe('_blank')
    expect(anchors[0]?.attributes('rel')).toBe('noopener noreferrer')
    expect(anchors[0]?.attributes('href')).toBe('https://example.com/a')
    expect(w.get('[data-test="concept-link-text"]').text()).toBe(HOSTILE)
    expect(w.find('a[href^="javascript"]').exists()).toBe(false)
    w.unmount()
  })

  it('add link: http and javascript: are refused before any request; https is sent trimmed', async () => {
    vi.mocked(strategyApi.addLink).mockResolvedValue({
      data: { id: 'L1', url: 'https://example.com/x', title: 'T', domain: 'example.com' },
      meta: { reset_to_awaiting: false },
    })
    const w = await mountStep([makeConcept(1, persona)])
    await w.get('[data-test="concept-link-add"]').trigger('click')
    await flushPromises()
    type('[data-test="concept-link-title"] input', 'Title')
    for (const bad of ['http://example.com/x', 'javascript:alert(1)', 'example.com']) {
      type('[data-test="concept-link-url"] input', bad)
      await click('[data-test="concept-link-save"]')
      expect(overlay('[data-test="concept-link-dialog"]')?.textContent).toMatch(/https|valid/)
      expect(strategyApi.addLink).not.toHaveBeenCalled()
    }
    type('[data-test="concept-link-url"] input', '  https://example.com/x  ')
    await click('[data-test="concept-link-save"]')
    expect(strategyApi.addLink).toHaveBeenCalledTimes(1)
    expect(vi.mocked(strategyApi.addLink).mock.calls[0]?.[2]).toEqual({
      title: 'Title',
      url: 'https://example.com/x',
    })
    w.unmount()
  })

  it('uploads: a non-image, an empty file and files over the count never reach the server; the limits are shown first', async () => {
    const w = await mountStep([
      makeConcept(1, persona, {
        images: Array.from({ length: 9 }, (_, i) => ({
          id: `I${i}`,
          original_filename: `p${i}.jpg`,
          mime_type: 'image/jpeg',
          size_bytes: 1024 * 1024,
          has_thumbnail: false,
          file_url: '/x',
          thumbnail_url: null,
        })),
      }),
    ])
    expect(w.get('[data-test="concept-image-count"]').text()).toBe('9 of 10 images · 9 of 300 MB')
    const input = w.get('[data-test="concept-image-input"]').element as HTMLInputElement
    await pick(input, [
      new File(['x'], 'notes.pdf', { type: 'application/pdf' }),
      new File([], 'empty.jpg', { type: 'image/jpeg' }),
      new File(['x'], 'ok.jpg', { type: 'image/jpeg' }),
      new File(['x'], 'one-too-many.png', { type: 'image/png' }),
    ])
    const notices = w.get('[data-test="concept-upload-notices"]').text()
    expect(notices).toContain('notes.pdf: only JPG, PNG and WebP')
    expect(notices).toContain('empty.jpg: the file is empty')
    expect(notices).toContain('one-too-many.png: a concept holds at most 10 images')
    // Only ok.jpg started (init is called once, for it).
    expect(strategyApi.initImage).toHaveBeenCalledTimes(1)
    expect(vi.mocked(strategyApi.initImage).mock.calls[0]?.[2]).toMatchObject({
      mime_type: 'image/jpeg',
      original_filename: 'ok.jpg',
    })
    w.unmount()
  })

  it('drop line: shown only while there are no references; a dropped non-image is refused before any request', async () => {
    const w = await mountStep([makeConcept(1, persona)])
    expect(w.find('[data-test="concept-drop"]').exists()).toBe(true)
    const drop = new Event('drop', { bubbles: true, cancelable: true })
    Object.defineProperty(drop, 'dataTransfer', {
      value: { files: [new File(['x'], 'notes.pdf', { type: 'application/pdf' })] },
    })
    w.get('[data-test="concept-drop"]').element.dispatchEvent(drop)
    await flushPromises()
    expect(w.get('[data-test="concept-upload-notices"]').text()).toContain('notes.pdf: only JPG')
    expect(strategyApi.initImage).not.toHaveBeenCalled()
    w.unmount()

    const withLink = await mountStep([
      makeConcept(1, persona, {
        links: [{ id: 'L1', url: 'https://example.com/a', title: 'Brief', domain: 'example.com' }],
      }),
    ])
    expect(withLink.find('[data-test="concept-drop"]').exists()).toBe(false)
    withLink.unmount()
  })

  it('uploads: files over the 300 MB total are refused before any byte moves', async () => {
    const w = await mountStep([makeConcept(1, persona)])
    const big = (name: string, mb: number): File => {
      const f = new File(['x'], name, { type: 'image/png' })
      Object.defineProperty(f, 'size', { value: mb * 1024 * 1024 })
      return f
    }
    const input = w.get('[data-test="concept-image-input"]').element as HTMLInputElement
    await pick(input, [big('a.png', 200), big('b.png', 150)])
    expect(w.get('[data-test="concept-upload-notices"]').text()).toContain(
      'b.png: the images of a concept add up to at most 300 MB',
    )
    expect(strategyApi.initImage).toHaveBeenCalledTimes(1)
    w.unmount()
  })

  it('decision: Approved needs a name (note optional); Changes requested needs a name and a comment; hostile text is sent as written', async () => {
    vi.mocked(strategyApi.decideConcept).mockResolvedValue({ data: makeConcept(1, persona) })
    const w = await mountStep([makeConcept(1, persona)])
    await w.get('[data-test="concepts-record-decision"]').trigger('click')
    await flushPromises()
    await click('[data-test="concept-decision-save"]')
    expect(strategyApi.decideConcept).not.toHaveBeenCalled()
    expect(overlay('[data-test="concept-decision-dialog"]')?.textContent).toContain('required')

    await click('[data-test="concept-decision-changes"]')
    type('[data-test="concept-decision-name"] input', HOSTILE)
    await click('[data-test="concept-decision-save"]')
    expect(strategyApi.decideConcept).not.toHaveBeenCalled()
    type('[data-test="concept-decision-comment-field"] textarea', HOSTILE)
    await click('[data-test="concept-decision-save"]')
    expect(strategyApi.decideConcept).toHaveBeenCalledTimes(1)
    expect(vi.mocked(strategyApi.decideConcept).mock.calls[0]?.[2]).toEqual({
      status: 'changes_requested',
      client_name: HOSTILE,
      comment: HOSTILE,
    })
    w.unmount()
  })

  it('tabs: the market is in the address; a market switch loads that market', async () => {
    const w = await mountStep([makeConcept(1, persona)])
    expect(strategyApi.listConcepts).toHaveBeenCalledWith(SCOPE, 'SK')
    await w.get('[data-test="market-tab-RO"]').trigger('click')
    await flushPromises()
    await new Promise((r) => setTimeout(r, 50))
    expect(w.vm.$router.currentRoute.value.query.market).toBe('RO')
    expect(strategyApi.listConcepts).toHaveBeenCalledWith(SCOPE, 'RO')
    w.unmount()
  })
})
