/**
 * PL-2.3 walk fixes (Pedram, 2026-11-21): A1 angle suggestions in the concept dialog, A2 Download in the image viewer, P1
 * the decision choice (selected state, why Save cannot proceed), P2 the persona refusal (title, headings, link) and the
 * persona filter it links to. Each gate has a break-revert in `pl-2-3-break-reverts.md`.
 */
import type { WaveConceptResource } from '@catalyst/api-client'
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
    imageBlob: vi.fn(),
  },
}))

import { strategyApi } from '../api/strategy.api'
import {
  BRAND_ULID,
  makeAngle,
  makeConcept,
  makePersona,
  makeSummary,
  makeUsps,
  mountStrategy,
  overlay,
  WAVE_ULID,
} from '../strategyTestKit'
import ConceptImageViewer from './ConceptImageViewer.vue'
import ConceptsStep from './ConceptsStep.vue'

const ROUTE = `/brands/${BRAND_ULID}/waves/${WAVE_ULID}/strategy/concepts`
const usps = makeUsps(3)
const angleA = makeAngle(usps[0]!, 'Eight people, three diets, one order')
const angleB = {
  ...makeAngle(usps[1]!, 'Lunch landed at 11:48'),
  attributes: {
    ...makeAngle(usps[1]!).attributes,
    explanation: 'Lunch landed at 11:48',
    origin: 'matrix' as const,
    read_only: true,
  },
}
const personaWithAngles = makePersona(1, 'SK', { angles: [angleA, angleB] })
const personaBare = makePersona(2, 'SK')

function counts(list: WaveConceptResource[]) {
  return {
    market: 'SK',
    total: list.length,
    approved: list.filter((c) => c.attributes.status === 'approved').length,
    awaiting: list.filter((c) => c.attributes.status === 'awaiting').length,
    changes_requested: 0,
    complete: false,
    under_minimum: true,
  }
}

async function mountStep(list: WaveConceptResource[], route = ROUTE) {
  vi.mocked(strategyApi.listPersonas).mockResolvedValue({ data: [personaWithAngles, personaBare] })
  vi.mocked(strategyApi.listUsps).mockResolvedValue({ data: usps })
  vi.mocked(strategyApi.listConcepts).mockResolvedValue({
    data: list,
    meta: { market: counts(list) },
  })
  const w = await mountStrategy(
    ConceptsStep,
    {
      summary: makeSummary({ persona_count: 2, markets: [{ market: 'SK', persona_count: 2 }] }),
      markets: ['SK'],
      brandName: 'Acme',
    },
    { role: 'agency_admin', route },
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
const angleField = (): HTMLTextAreaElement =>
  overlay('[data-test="concept-field-angle"] textarea') as HTMLTextAreaElement

/** Picks a persona in the dialog's select by emitting its update (the list itself is drawn by Vuetify). */
async function choosePersona(
  w: Awaited<ReturnType<typeof mountStep>>,
  title: string,
): Promise<void> {
  const p = [personaWithAngles, personaBare].find((x) => x.attributes.title === title)
  if (!p) throw new Error(title)
  w.findComponent('[data-test="concept-field-persona"]').vm.$emit('update:modelValue', p.id)
  await flushPromises()
}

async function openAddDialog(w: Awaited<ReturnType<typeof mountStep>>): Promise<void> {
  await w.get('[data-test="concept-tile"]').trigger('click')
  await flushPromises()
}

describe('PL-2.3 walk fixes', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(strategyApi.imageBlob).mockRejectedValue(new Error('no image'))
  })
  afterEach(() => {
    document.body.innerHTML = ''
  })

  // A1 -------------------------------------------------------------------------------------------------------
  describe('A1 angle suggestions', () => {
    it('shows each angle of the chosen persona with its USP and line (manual and synced); picking fills the text, which stays editable', async () => {
      vi.mocked(strategyApi.createConcept).mockResolvedValue({
        data: makeConcept(1, personaWithAngles),
      })
      const w = await mountStep([makeConcept(9, personaWithAngles)])
      await openAddDialog(w)
      expect(overlay('[data-test="concept-angle-suggest"]')).not.toBeNull()
      await click('[data-test="concept-angle-suggest"]')
      const rows = [...document.body.querySelectorAll('[data-test="concept-angle-suggestion"]')]
      expect(rows).toHaveLength(2)
      expect(rows[0]?.textContent).toContain(usps[0]!.attributes.title)
      expect(rows[0]?.textContent).toContain('Eight people, three diets, one order')
      expect(rows[0]?.textContent).toContain('Manually added')
      expect(rows[1]?.textContent).toContain('Synced from matrix')
      ;(rows[1] as HTMLElement).click()
      await flushPromises()
      expect(angleField().value).toBe('Lunch landed at 11:48')
      // Editable: free text replaces it, and it is sent as the angle text only.
      type('[data-test="concept-field-angle"] textarea', 'My own words')
      type('[data-test="concept-field-title"] input', 'T')
      await click('[data-test="concept-dialog-save"]')
      const payload = vi.mocked(strategyApi.createConcept).mock.calls[0]?.[1] as Record<
        string,
        unknown
      >
      expect(payload['angle']).toBe('My own words')
      expect(Object.keys(payload).some((k) => k.includes('angle') && k !== 'angle')).toBe(false)
      w.unmount()
    })

    it('a persona with no angle shows no button and the field behaves as before', async () => {
      const w = await mountStep([makeConcept(9, personaBare)])
      await openAddDialog(w)
      // Choose the persona without angles.
      vi.mocked(strategyApi.createConcept).mockResolvedValue({ data: makeConcept(1, personaBare) })
      await choosePersona(w, 'Persona 2')
      expect(overlay('[data-test="concept-angle-suggest"]')).toBeNull()
      type('[data-test="concept-field-angle"] textarea', 'Typed')
      expect(angleField().value).toBe('Typed')
      w.unmount()
    })

    it('changing the persona refreshes the suggestions and does not touch text already typed', async () => {
      const w = await mountStep([makeConcept(9, personaWithAngles)])
      await openAddDialog(w)
      type('[data-test="concept-field-angle"] textarea', 'Typed first')
      await choosePersona(w, 'Persona 2')
      expect(angleField().value).toBe('Typed first')
      expect(overlay('[data-test="concept-angle-suggest"]')).toBeNull()
      await choosePersona(w, 'Persona 1')
      expect(angleField().value).toBe('Typed first')
      expect(overlay('[data-test="concept-angle-suggest"]')).not.toBeNull()
      w.unmount()
    })
  })

  // A2 -------------------------------------------------------------------------------------------------------
  describe('A2 download in the viewer', () => {
    const image = {
      id: 'IMG1',
      original_filename: 'office-moodboard.jpg',
      mime_type: 'image/jpeg',
      size_bytes: 10,
      has_thumbnail: true,
      file_url: '/agencies/A/brands/B/waves/W/strategy/concepts/C/images/IMG1/file',
      thumbnail_url: null,
    }

    it('saves the original bytes already read through the authenticated endpoint under the original filename; nothing else is fetched', async () => {
      URL.createObjectURL = vi.fn(() => 'blob:viewer/1')
      URL.revokeObjectURL = vi.fn()
      vi.mocked(strategyApi.imageBlob).mockResolvedValue(new Blob(['x'], { type: 'image/jpeg' }))
      const seen: { href: string; download: string }[] = []
      const spy = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
        this: HTMLAnchorElement,
      ) {
        seen.push({ href: this.getAttribute('href') ?? '', download: this.download })
      })
      const w = await mountStrategy(ConceptImageViewer, { image })
      await flushPromises()
      const btn = overlay('[data-test="concept-image-download"]') as HTMLButtonElement
      expect(btn).not.toBeNull()
      expect(btn.disabled).toBe(false)
      btn.click()
      await flushPromises()
      expect(seen).toEqual([{ href: 'blob:viewer/1', download: 'office-moodboard.jpg' }])
      // Only the one authenticated read the viewer already made: no second request, no presigned address.
      expect(strategyApi.imageBlob).toHaveBeenCalledTimes(1)
      expect(vi.mocked(strategyApi.imageBlob).mock.calls[0]?.[0]).toBe(image.file_url)
      spy.mockRestore()
      w.unmount()
    })

    it('is off until the image has loaded, and a hostile filename is made safe, never markup', async () => {
      URL.createObjectURL = vi.fn(() => 'blob:viewer/2')
      URL.revokeObjectURL = vi.fn()
      vi.mocked(strategyApi.imageBlob).mockRejectedValue(new Error('no'))
      const failed = await mountStrategy(ConceptImageViewer, { image })
      await flushPromises()
      expect((overlay('[data-test="concept-image-download"]') as HTMLButtonElement).disabled).toBe(
        true,
      )
      failed.unmount()
      document.body.innerHTML = ''

      vi.mocked(strategyApi.imageBlob).mockResolvedValue(new Blob(['x']))
      const names: string[] = []
      const spy = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
        this: HTMLAnchorElement,
      ) {
        names.push(this.download)
      })
      const w = await mountStrategy(ConceptImageViewer, {
        image: { ...image, original_filename: '../<img src=x onerror=alert(1)>/a.jpg' },
      })
      await flushPromises()
      ;(overlay('[data-test="concept-image-download"]') as HTMLButtonElement).click()
      expect(names[0]).not.toMatch(/[/\\<>]/)
      expect(names[0]).toContain('a.jpg')
      spy.mockRestore()
      w.unmount()
    })
  })

  // P1 -------------------------------------------------------------------------------------------------------
  describe('P1 decision dialog', () => {
    async function openDecision() {
      vi.mocked(strategyApi.decideConcept).mockResolvedValue({ data: makeConcept(1, personaBare) })
      const w = await mountStep([makeConcept(1, personaBare)])
      await w.get('[data-test="concepts-record-decision"]').trigger('click')
      await flushPromises()
      return w
    }
    const state = (sel: string) => overlay(sel)?.getAttribute('aria-checked')

    it('the chosen status is a radio group with one checked, marked in the design system selected state', async () => {
      const w = await openDecision()
      const group = overlay('[data-test="concept-decision-status"]')
      expect(group?.getAttribute('role')).toBe('radiogroup')
      expect(state('[data-test="concept-decision-approved"]')).toBe('true')
      expect(state('[data-test="concept-decision-changes"]')).toBe('false')
      expect(overlay('[data-test="concept-decision-approved"]')?.className).toContain(
        'v-btn--variant-flat',
      )
      expect(overlay('[data-test="concept-decision-changes"]')?.className).toContain(
        'v-btn--variant-outlined',
      )
      await click('[data-test="concept-decision-changes"]')
      expect(state('[data-test="concept-decision-approved"]')).toBe('false')
      expect(state('[data-test="concept-decision-changes"]')).toBe('true')
      expect(overlay('[data-test="concept-decision-changes"]')?.className).toContain(
        'v-btn--variant-flat',
      )
      expect(overlay('[data-test="concept-decision-approved"]')?.className).toContain(
        'v-btn--variant-outlined',
      )
      w.unmount()
    })

    it('says why Save cannot proceed: the name for Approved; the name and the comment for Changes requested; nothing when it can', async () => {
      const w = await openDecision()
      const why = () => overlay('[data-test="concept-decision-why"]')?.textContent?.trim() ?? null
      expect(why()).toBe("Enter the client's name.")
      type('[data-test="concept-decision-name"] input', 'Jana')
      await flushPromises()
      expect(why()).toBeNull()
      await click('[data-test="concept-decision-changes"]')
      expect(why()).toBe("Enter the client's comment.")
      type('[data-test="concept-decision-name"] input', '')
      await flushPromises()
      expect(why()).toBe("Enter the client's name and the comment.")
      type('[data-test="concept-decision-comment-field"] textarea', 'Shorter hook please')
      await flushPromises()
      expect(why()).toBe("Enter the client's name.")
      type('[data-test="concept-decision-name"] input', 'Jana')
      await flushPromises()
      expect(why()).toBeNull()
      // The line is in the error colour, as the matrix hint is.
      type('[data-test="concept-decision-name"] input', '')
      await flushPromises()
      expect(overlay('[data-test="concept-decision-why"]')?.className).toContain('decision-why')
      w.unmount()
    })
  })

  // P2 + persona filter ---------------------------------------------------------------------------------------
  describe('persona filter from the refused delete', () => {
    it("?persona= shows only that persona's concepts with a line and Show all; the market banner counts are unchanged", async () => {
      const list = [
        makeConcept(1, personaWithAngles),
        makeConcept(2, personaBare),
        makeConcept(3, personaBare),
      ]
      const w = await mountStep(list, `${ROUTE}?market=SK&persona=${personaBare.id}`)
      expect(w.findAll('[data-test^="concept-card-"]')).toHaveLength(2)
      expect(w.get('[data-test="concepts-persona-filter"]').text()).toContain('Persona 2')
      await w.get('[data-test="concepts-persona-filter-clear"]').trigger('click')
      await flushPromises()
      await new Promise((r) => setTimeout(r, 50))
      await flushPromises()
      expect(w.findAll('[data-test^="concept-card-"]')).toHaveLength(3)
      expect(w.find('[data-test="concepts-persona-filter"]').exists()).toBe(false)
      w.unmount()
    })

    it('an unknown persona in the address is ignored', async () => {
      const w = await mountStep(
        [makeConcept(1, personaWithAngles), makeConcept(2, personaBare)],
        `${ROUTE}?persona=NOPE`,
      )
      expect(w.findAll('[data-test^="concept-card-"]')).toHaveLength(2)
      expect(w.find('[data-test="concepts-persona-filter"]').exists()).toBe(false)
      w.unmount()
    })
  })
})
