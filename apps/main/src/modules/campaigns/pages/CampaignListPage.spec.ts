/**
 * Sprint 8 Chunk 1 — Vitest coverage for the campaign list page. List LOGIC
 * (renders rows from the {data,meta} envelope, empty state, the create
 * affordance) is unit-tested here with light Vuetify; full DOM + navigation
 * is Playwright.
 */

import { ApiError } from '@catalyst/api-client'
import type { CampaignResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { createI18n } from 'vue-i18n'

import enApp from '@/core/i18n/locales/en/app.json'
import { useAgencyStore } from '@/core/stores/useAgencyStore'

import CampaignListPage from './CampaignListPage.vue'
import pageSource from './CampaignListPage.vue?raw'

vi.mock('../api/campaigns.api', () => ({
  campaignsApi: { list: vi.fn(), update: vi.fn() },
}))
vi.mock('@/modules/brands/api/brands.api', () => ({
  brandsApi: { list: vi.fn(), listOptions: vi.fn() },
}))

import { campaignsApi } from '../api/campaigns.api'
import { brandsApi } from '@/modules/brands/api/brands.api'

const localStorageStore: Record<string, string> = {}
Object.defineProperty(globalThis, 'localStorage', {
  value: {
    getItem: (k: string): string | null => localStorageStore[k] ?? null,
    setItem: (k: string, v: string): void => {
      localStorageStore[k] = v
    },
    removeItem: (k: string): void => {
      delete localStorageStore[k]
    },
  },
  writable: true,
})

function makeCampaign(overrides: Partial<CampaignResource['attributes']> = {}): CampaignResource {
  return {
    id: '01HZA1B2C3D4E5F6G7H8J9K0M1',
    type: 'campaigns',
    attributes: {
      name: 'Summer launch',
      description: null,
      objective: 'awareness',
      status: 'active',
      budget_minor_units: 250000,
      budget_currency: 'EUR',
      starts_at: null,
      ends_at: null,
      posting_window_starts_at: null,
      posting_window_ends_at: null,
      brief: null,
      target_creator_count: null,
      requires_per_campaign_contract: false,
      creator_posts_content: true,
      is_marketplace_visible: false,
      listed_on_jobs_board: false,
      listing_duration: null,
      listing_fee: null,
      listing_languages: null,
      listing_regions: null,
      listing_examples_url: null,
      published_at: null,
      completed_at: null,
      assignment_count: 0,
      created_at: '2026-06-01T10:00:00.000000Z',
      updated_at: '2026-06-01T10:00:00.000000Z',
      ...overrides,
    },
    relationships: {
      brand: { data: { id: 'brand-ulid', type: 'brands', name: 'Acme' } },
      agency: { data: { id: 'agency-ulid', type: 'agencies' } },
    },
  }
}

async function mountList(
  campaigns: CampaignResource[] = [],
  total: number = campaigns.length,
): Promise<{ wrapper: ReturnType<typeof mount>; cleanup: () => void }> {
  const pinia = createPinia()
  setActivePinia(pinia)

  vi.mocked(campaignsApi.list).mockResolvedValue({
    data: campaigns,
    meta: { total, page: 1, per_page: 25, last_page: 1 },
  })
  vi.mocked(brandsApi.listOptions).mockResolvedValue({ data: [] })

  const agency = useAgencyStore()
  agency.initFromUser([
    { agency_id: 'agency-ulid', agency_name: 'Test Agency', role: 'agency_admin' },
  ])

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/campaigns', name: 'campaigns.list', component: { template: '<div />' } },
      { path: '/campaigns/new', name: 'campaigns.create', component: { template: '<div />' } },
      { path: '/campaigns/:ulid', name: 'campaigns.detail', component: { template: '<div />' } },
    ],
  })
  await router.push('/campaigns')
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })

  const wrapper = mount(CampaignListPage, {
    global: { plugins: [pinia, router, i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
  await flushPromises()

  return {
    wrapper,
    cleanup: () => {
      wrapper.unmount()
      Object.keys(localStorageStore).forEach((k) => delete localStorageStore[k])
    },
  }
}

/** The row's Job board switch, as the underlying input (AH-059, D3). */
function toggleOf(wrapper: ReturnType<typeof mount>, id: string) {
  return wrapper.find<HTMLInputElement>(`[data-test="campaign-job-board-toggle-${id}"] input`)
}

/**
 * Vuetify dialogs teleport to `document.body`, so they sit outside the wrapper's
 * tree and cannot be reached through it. Every dialog assertion goes through
 * these two.
 */
function bodyEl(testId: string): HTMLElement | null {
  return document.body.querySelector<HTMLElement>(`[data-test="${testId}"]`)
}

async function clickInBody(testId: string): Promise<void> {
  const el = bodyEl(testId)
  expect(el, `[data-test="${testId}"] was not rendered`).not.toBeNull()
  el?.click()
  await flushPromises()
}

describe('CampaignListPage (Sprint 8 Chunk 1)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  it('renders the empty state when there are no campaigns', async () => {
    const harness = await mountList([])
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="campaign-empty-state"]').exists()).toBe(true)
  })

  it('renders campaign rows from the {data,meta} envelope', async () => {
    const harness = await mountList([makeCampaign({ name: 'Summer launch' })])
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="campaign-table"]').exists()).toBe(true)
    expect(harness.wrapper.text()).toContain('Summer launch')
  })

  it('always shows the create affordance (backend enforces the admin/manager gate)', async () => {
    const harness = await mountList([makeCampaign()])
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="campaign-create-btn"]').exists()).toBe(true)
  })

  it('marks a listed campaign on the jobs board, and only that one (AH-057)', async () => {
    const listed = makeCampaign({ name: 'On the board', listed_on_jobs_board: true })
    const unlisted = {
      ...makeCampaign({ name: 'Not on the board' }),
      id: 'SECONDCAMPAIGNULID000000001',
    }

    const harness = await mountList([listed, unlisted])
    cleanup = harness.cleanup
    const w = harness.wrapper

    // AH-059 (D3): the read-only chip became a switch, so the claim is now read
    // off the control's state rather than its text. The claim itself is unchanged
    // — listing is independent of lifecycle status: both rows are `active`, and
    // only the listed one reads ON.
    expect(toggleOf(w, listed.id).element.checked).toBe(true)
    expect(toggleOf(w, unlisted.id).element.checked).toBe(false)
    expect(w.text()).toContain('Job board')
  })

  it('threads the status filter to the API when a chip is selected', async () => {
    const harness = await mountList([makeCampaign()])
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { statusFilter: string }).statusFilter = 'draft'
    await flushPromises()
    expect(vi.mocked(campaignsApi.list).mock.calls.at(-1)?.[1]).toMatchObject({ status: 'draft' })
  })

  // ── D3: the interactive Job board toggle (AH-059) ─────────────────────────

  /** A campaign whose listing floor is complete — the only kind that can list. */
  function listableCampaign(
    overrides: Partial<CampaignResource['attributes']> = {},
  ): CampaignResource {
    return makeCampaign({
      description: 'Three short-form videos per month.',
      listing_duration: '4 weeks',
      listing_fee: '€300 per video',
      listing_languages: ['en'],
      listing_regions: ['IE'],
      ...overrides,
    })
  }

  it('OFF is immediate and ungated — no confirmation stands between a listing and its removal', async () => {
    const campaign = listableCampaign({ listed_on_jobs_board: true })
    const harness = await mountList([campaign])
    cleanup = harness.cleanup
    const w = harness.wrapper

    vi.mocked(campaignsApi.update).mockResolvedValue({
      data: { ...campaign, attributes: { ...campaign.attributes, listed_on_jobs_board: false } },
    } as never)

    await toggleOf(w, campaign.id).setValue(false)
    await flushPromises()

    // Delisting is reversible and it is what someone reaches for when a listing
    // is wrong. Asking twice would be in the way.
    expect(bodyEl('campaign-listing-confirm-submit')).toBeNull()
    // ⚠ A SINGLE-KEY PATCH. Every other field is preserved by its own absence
    // under the endpoint's `sometimes` rules, which is what makes a table row
    // safe to write from at all.
    expect(vi.mocked(campaignsApi.update).mock.calls.at(-1)?.[2]).toEqual({
      listed_on_jobs_board: false,
    })
  })

  it('ON asks first, names the campaign, and warns that creators may be notified', async () => {
    const campaign = listableCampaign({ name: 'Autumn UGC push' })
    const harness = await mountList([campaign])
    cleanup = harness.cleanup
    const w = harness.wrapper

    await toggleOf(w, campaign.id).setValue(true)
    await flushPromises()

    // Nothing has been written yet: this is the direction with the irreversible
    // side effect (the once-only fan-out to every rostered creator).
    expect(vi.mocked(campaignsApi.update)).not.toHaveBeenCalled()

    const body = document.body.textContent ?? ''
    expect(body).toContain('Autumn UGC push')
    expect(body).toContain('may be notified')
  })

  it('confirming sends the single-key PATCH and replaces the row from the response', async () => {
    const campaign = listableCampaign()
    const harness = await mountList([campaign])
    cleanup = harness.cleanup
    const w = harness.wrapper

    vi.mocked(campaignsApi.update).mockResolvedValue({
      data: { ...campaign, attributes: { ...campaign.attributes, listed_on_jobs_board: true } },
    } as never)

    await toggleOf(w, campaign.id).setValue(true)
    await flushPromises()
    await clickInBody('campaign-listing-confirm-submit')

    expect(vi.mocked(campaignsApi.update).mock.calls.at(-1)?.[2]).toEqual({
      listed_on_jobs_board: true,
    })
    // The row comes from the server's answer, not from a local boolean patch:
    // the flip also stamps `listed_at`.
    expect(toggleOf(w, campaign.id).element.checked).toBe(true)
  })

  it('declining writes NOTHING and re-reads, so the switch cannot lie', async () => {
    const campaign = listableCampaign()
    const harness = await mountList([campaign])
    cleanup = harness.cleanup
    const w = harness.wrapper

    const listCallsBefore = vi.mocked(campaignsApi.list).mock.calls.length

    await toggleOf(w, campaign.id).setValue(true)
    await flushPromises()
    await clickInBody('campaign-listing-confirm-cancel')

    expect(vi.mocked(campaignsApi.update)).not.toHaveBeenCalled()
    // Vuetify already flipped the control on click, so the only honest way back is
    // to ask the server what it holds — not to assign the boolean back.
    expect(vi.mocked(campaignsApi.list).mock.calls.length).toBeGreaterThan(listCallsBefore)
  })

  // ── The refusals are EXPLICIT (D3's whole point) ─────────────────────────

  it('refuses an incomplete listing by NAMING every missing field', async () => {
    // `makeCampaign()` has an empty floor: no description, duration, fee,
    // languages or regions.
    const campaign = makeCampaign({ name: 'Half-built' })
    const harness = await mountList([campaign])
    cleanup = harness.cleanup

    await toggleOf(harness.wrapper, campaign.id).setValue(true)
    await flushPromises()

    const body = document.body.textContent ?? ''
    // Not a silently-reverted switch — that reads as a broken control. Each
    // missing field is named, in the same words the Settings tab uses.
    expect(body).toContain('Half-built')
    for (const field of [
      'description',
      'listing duration',
      'listed fee',
      'content languages',
      'regions',
    ]) {
      expect(body).toContain(field)
    }

    // And nothing was attempted: the shared floor mirror answered locally.
    expect(vi.mocked(campaignsApi.update)).not.toHaveBeenCalled()
  })

  it('refuses a terminal campaign with the STATUS reason, not the floor list', async () => {
    const campaign = listableCampaign({ name: 'Wrapped up', status: 'completed' })
    const harness = await mountList([campaign])
    cleanup = harness.cleanup

    await toggleOf(harness.wrapper, campaign.id).setValue(true)
    await flushPromises()

    const body = document.body.textContent ?? ''
    expect(body).toContain('completed or cancelled')
    // The floor is complete here, so naming fields would be a lie about why.
    expect(body).not.toContain('is missing:')
    expect(vi.mocked(campaignsApi.update)).not.toHaveBeenCalled()
  })

  it('explains a server 422 with the same dialog — the local gates are a courtesy, not the rule', async () => {
    // The stale-row case: the floor looked complete when this page rendered, and
    // the server disagrees. This is why the local checks can never be the
    // authority, and it is the same code path Settings relies on.
    const campaign = listableCampaign({ name: 'Raced' })
    const harness = await mountList([campaign])
    cleanup = harness.cleanup

    vi.mocked(campaignsApi.update).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'validation_failed',
        message: 'Unprocessable',
        details: [
          {
            status: '422',
            code: 'validation_failed',
            detail: 'The listing is incomplete.',
            source: { pointer: '/data/attributes/listed_on_jobs_board' },
          },
        ],
      }),
    )

    await toggleOf(harness.wrapper, campaign.id).setValue(true)
    await flushPromises()
    await clickInBody('campaign-listing-confirm-submit')

    // The floor mirror says the row LOOKED complete, so the refusal falls through
    // to the status reason rather than inventing a field list.
    expect(bodyEl('campaign-listing-refusal-body')?.textContent ?? '').toContain('Raced')
  })

  // ── AH-114 (S3.1): the restyled list ────────────────────────────────────────────────────
  describe('agency restyle (AH-114, S3.1)', () => {
    it('reads the header summary off the list total, and not before the first response', async () => {
      const harness = await mountList([makeCampaign()], 24)
      cleanup = harness.cleanup
      expect(harness.wrapper.get('[data-test="campaign-list-summary"]').text()).toBe('24 campaigns')
      expect(harness.wrapper.get('[data-test="campaign-list-heading"]').text()).toBe('Campaigns')
    })

    it('uses the singular for one campaign', async () => {
      const harness = await mountList([makeCampaign()], 1)
      cleanup = harness.cleanup
      expect(harness.wrapper.get('[data-test="campaign-list-summary"]').text()).toBe('1 campaign')
    })

    it('draws no summary when the load failed', async () => {
      vi.mocked(campaignsApi.list).mockRejectedValue(new Error('down'))
      vi.mocked(brandsApi.listOptions).mockResolvedValue({ data: [] })
      const pinia = createPinia()
      setActivePinia(pinia)
      useAgencyStore().initFromUser([
        { agency_id: 'agency-ulid', agency_name: 'Test Agency', role: 'agency_admin' },
      ])
      const router = createRouter({
        history: createMemoryHistory(),
        routes: [
          { path: '/campaigns', name: 'campaigns.list', component: { template: '<div />' } },
          { path: '/campaigns/new', name: 'campaigns.create', component: { template: '<div />' } },
          {
            path: '/campaigns/:ulid',
            name: 'campaigns.detail',
            component: { template: '<div />' },
          },
        ],
      })
      await router.push('/campaigns')
      const i18n = createI18n({
        legacy: false,
        locale: 'en',
        fallbackLocale: 'en',
        availableLocales: ['en'],
        messages: { en: enApp } as never,
      })
      const wrapper = mount(CampaignListPage, {
        global: {
          plugins: [
            pinia,
            router,
            i18n,
            createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives }),
          ],
        },
      })
      await flushPromises()
      expect(wrapper.find('[data-test="campaign-list-summary"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="campaign-list-footer"]').exists()).toBe(false)
      wrapper.unmount()
    })

    it('every status renders the agency pill with its table tone, and no chip', async () => {
      const statuses = ['draft', 'active', 'paused', 'completed', 'cancelled'] as const
      const tones = ['neutral', 'success', 'warning', 'info', 'danger']
      const rows = statuses.map((status, i) => ({
        ...makeCampaign({ status, name: `Row ${status}` }),
        id: `STATUSROW00000000000000000${i}`,
      }))
      const harness = await mountList(rows)
      cleanup = harness.cleanup
      statuses.forEach((_, i) => {
        const pill = harness.wrapper.get(`[data-test="campaign-status-${rows[i]?.id}"]`)
        expect(pill.classes()).toContain('agency-status-pill')
        expect(pill.attributes('data-tone')).toBe(tones[i])
      })
      expect(harness.wrapper.find('[data-test="campaign-table"] .v-chip').exists()).toBe(false)
    })

    it('the page keeps no colour map of its own for statuses', () => {
      expect(pageSource).not.toMatch(/statusColor/)
      expect(pageSource).not.toMatch(/color="\$\{|:color="statusColor/)
    })

    it('shows the start date line only for a campaign that has one', async () => {
      const dated = makeCampaign({ name: 'Dated', starts_at: '2026-10-08T00:00:00Z' })
      const undated = {
        ...makeCampaign({ name: 'Undated', starts_at: null }),
        id: 'UNDATEDCAMPAIGN00000000001',
      }
      const harness = await mountList([dated, undated])
      cleanup = harness.cleanup
      expect(harness.wrapper.get(`[data-test="campaign-starts-${dated.id}"]`).text()).toMatch(
        /^Starts .*2026$/,
      )
      expect(harness.wrapper.find(`[data-test="campaign-starts-${undated.id}"]`).exists()).toBe(
        false,
      )
    })

    it('draws the status segments without counts or check marks', async () => {
      const harness = await mountList([makeCampaign()], 24)
      cleanup = harness.cleanup
      const segments = harness.wrapper.get('[data-test="campaign-status-filter"]')
      expect(segments.text()).not.toMatch(/\d/)
      expect(segments.findAll('.v-chip')).toHaveLength(6)
    })

    it('the footer says how far the page reaches, and Previous is off on the first page', async () => {
      const harness = await mountList([makeCampaign(), makeCampaign()], 24)
      cleanup = harness.cleanup
      expect(harness.wrapper.get('[data-test="campaign-list-showing"]').text()).toBe(
        'Showing 2 of 24',
      )
      expect(
        harness.wrapper.get<HTMLButtonElement>('[data-test="campaign-page-previous"]').element
          .disabled,
      ).toBe(true)
    })

    it('Next asks for the following page and Previous comes back', async () => {
      const harness = await mountList([makeCampaign()], 60)
      cleanup = harness.cleanup
      const callsBefore = vi.mocked(campaignsApi.list).mock.calls.length

      await harness.wrapper.get('[data-test="campaign-page-next"]').trigger('click')
      await flushPromises()
      expect(vi.mocked(campaignsApi.list).mock.calls.length).toBe(callsBefore + 1)
      expect(vi.mocked(campaignsApi.list).mock.calls.at(-1)?.[1]).toMatchObject({ page: 2 })

      await harness.wrapper.get('[data-test="campaign-page-previous"]').trigger('click')
      await flushPromises()
      expect(vi.mocked(campaignsApi.list).mock.calls.at(-1)?.[1]).toMatchObject({ page: 1 })
    })

    it('Next is off when every campaign is already shown', async () => {
      const harness = await mountList([makeCampaign()], 1)
      cleanup = harness.cleanup
      expect(
        harness.wrapper.get<HTMLButtonElement>('[data-test="campaign-page-next"]').element.disabled,
      ).toBe(true)
    })

    it('marks a filter that holds a value as applied', async () => {
      const harness = await mountList([makeCampaign()])
      cleanup = harness.cleanup
      const from = harness.wrapper.get('[data-test="campaign-filter-starts-from"]')
      expect(from.classes()).toContain('agency-filter')
      expect(from.classes()).not.toContain('agency-filter--applied')
      await from.get('input').setValue('2026-10-01')
      expect(from.classes()).toContain('agency-filter--applied')
    })
  })
})
