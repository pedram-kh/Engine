/**
 * Component tests for the job detail + apply surface (AH-056, D3/D4/D5).
 *
 * The apply flow's whole design is "the server decides, the page renders the
 * answer", so these tests are mostly about the answers: a 404 reads as "no
 * longer available" rather than an error to retry, each 409 code gets its own
 * copy, and a rejection kills the Apply button permanently.
 *
 * The D3 subset gets a render-level check too — `website_url` is the third and
 * last brand field allowed to reach a creator, and it is detail-only.
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enCreator from '@/core/i18n/locales/en/creator.json'

vi.mock('../jobs.api', () => ({
  creatorJobsApi: {
    list: vi.fn(),
    show: vi.fn(),
    apply: vi.fn(),
  },
}))

import { creatorJobsApi } from '../jobs.api'
import CreatorJobDetailPage from './CreatorJobDetailPage.vue'

const mockApi = vi.mocked(creatorJobsApi)

function detail(overrides: Record<string, unknown> = {}) {
  return {
    data: {
      id: '01JOB1',
      type: 'creator_job',
      attributes: {
        name: 'Autumn UGC push',
        listing_fee: '€300 per video',
        listing_duration: '4 weeks',
        applicant_count: 3,
        listed_at: new Date().toISOString(),
        application_status: null,
        assignment_state: null,
        description: 'Three short-form videos per month.',
        listing_languages: ['en', 'pt'],
        listing_regions: ['PT', 'ES'],
        listing_examples_url: 'https://examples.test/work',
        assignment_ulid: null,
        brand: {
          name: 'Northwind Coffee',
          logo_url: 'https://cdn.test/logo.png',
          website_url: 'https://northwind.test',
        },
        ...overrides,
      },
    },
  }
}

function apiError(status: number, code = 'http.unknown_error'): ApiError {
  return new ApiError({
    status,
    code,
    message: 'refused',
    details: [{ code, status: String(status) }],
  })
}

/**
 * `VDialog` teleports to `document.body`, which puts the apply form outside the
 * wrapper's DOM tree. Stubbing it inline (the ReinviteDialog precedent) keeps
 * the dialog's own contents — the note field and the two buttons, which are
 * what these tests are about — inside the wrapper.
 */
const VDialogStub = {
  name: 'VDialog',
  props: ['modelValue'],
  template: '<div class="vdialog-stub"><slot /></div>',
}

/** VSnackbar teleports to the body, so its copy is read from there. */
function snackbarText(): string {
  return document.body.querySelector('[data-testid="creator-job-snackbar"]')?.textContent ?? ''
}

async function mountPage() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/creator/jobs', name: 'creator.jobs', component: { template: '<div />' } },
      {
        path: '/creator/jobs/:ulid',
        name: 'creator.job.detail',
        component: { template: '<div />' },
      },
      {
        path: '/creator/assignments/:ulid',
        name: 'creator.assignment.detail',
        component: { template: '<div />' },
      },
    ],
  })
  await router.push('/creator/jobs/01JOB1')
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en: enCreator } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const wrapper = mount(CreatorJobDetailPage, {
    global: {
      plugins: [
        router,
        i18n,
        createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives }),
      ],
      stubs: { VDialog: VDialogStub },
    },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

describe('CreatorJobDetailPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    // VSnackbar/VDialog teleport into the body and survive the wrapper, so a
    // previous test's toast would otherwise still be readable here.
    document.body.innerHTML = ''
    mockApi.show.mockResolvedValue(detail() as never)
    mockApi.apply.mockResolvedValue({
      data: {
        id: '01APP',
        type: 'campaign_application',
        attributes: { status: 'pending', note: null, created_at: '2026-07-27T00:00:00+00:00' },
      },
    } as never)
  })

  // ── Rendering, including the D3 subset ────────────────────────────────────

  it('renders the job copy, the brand name and the detail-only fields', async () => {
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="creator-job-detail-name"]').text()).toBe('Autumn UGC push')
    expect(wrapper.find('[data-testid="creator-job-detail-brand"]').text()).toBe('Northwind Coffee')
    expect(wrapper.find('[data-testid="creator-job-detail-description"]').text()).toContain(
      'Three short-form videos per month.',
    )
    expect(wrapper.find('[data-testid="creator-job-examples"]').attributes('href')).toBe(
      'https://examples.test/work',
    )
  })

  it('frames the job in a card, and leaves the failure states unframed (AH-057)', async () => {
    const wrapper = await mountPage()

    // The card is the framing the list page's job cards led the creator to
    // expect; the back link stays outside it, page-level.
    expect(wrapper.find('[data-testid="creator-job-detail-card"]').exists()).toBe(true)
    expect(
      wrapper
        .find('[data-testid="creator-job-detail-card"] [data-testid="creator-job-back"]')
        .exists(),
    ).toBe(false)

    // A tonal alert inside a bordered card reads as a double frame, so the two
    // failure states render at page level instead.
    mockApi.show.mockRejectedValue(apiError(404, 'job.not_found'))
    const missing = await mountPage()
    expect(missing.find('[data-testid="creator-job-not-found"]').exists()).toBe(true)
    expect(missing.find('[data-testid="creator-job-detail-card"]').exists()).toBe(false)
  })

  it('links the brand website with rel="noopener" on the external anchors', async () => {
    const wrapper = await mountPage()

    const website = wrapper.find('[data-testid="creator-job-website"]')
    expect(website.attributes('href')).toBe('https://northwind.test')
    expect(website.attributes('rel')).toContain('noopener')
    expect(wrapper.find('[data-testid="creator-job-examples"]').attributes('rel')).toContain(
      'noopener',
    )
  })

  it('renders happily when the brand has no website (the field is optional)', async () => {
    mockApi.show.mockResolvedValue(
      detail({ brand: { name: 'Northwind Coffee', logo_url: null, website_url: null } }) as never,
    )
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="creator-job-website"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="creator-job-detail-brand"]').text()).toBe('Northwind Coffee')
  })

  // ── Fail-closed loads ─────────────────────────────────────────────────────

  it('renders a 404 as "no longer available", not as a retryable error', async () => {
    mockApi.show.mockRejectedValue(apiError(404))
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="creator-job-not-found"]').exists()).toBe(true)
    // No retry affordance: retrying a fail-closed 404 can only fail again.
    expect(wrapper.find('[data-testid="creator-job-load-failed"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="creator-job-apply"]').exists()).toBe(false)
  })

  it('offers a retry for a transport failure', async () => {
    mockApi.show.mockRejectedValue(new Error('network'))
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="creator-job-load-failed"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="creator-job-not-found"]').exists()).toBe(false)
  })

  // ── Apply ─────────────────────────────────────────────────────────────────

  it('applies with no note when the creator just taps send', async () => {
    const wrapper = await mountPage()

    await wrapper.find('[data-testid="creator-job-apply"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-testid="creator-job-apply-submit"]').trigger('click')
    await flushPromises()

    expect(mockApi.apply).toHaveBeenCalledWith('01JOB1', {})
    // Re-read after the write: the row the server created is the truth, and it
    // also refreshes the applicant count this application just moved.
    expect(mockApi.show).toHaveBeenCalledTimes(2)
  })

  it('sends a trimmed note when one is written', async () => {
    const wrapper = await mountPage()

    await wrapper.find('[data-testid="creator-job-apply"]').trigger('click')
    await flushPromises()
    await wrapper
      .find('[data-testid="creator-job-apply-note"] textarea')
      .setValue('  I shoot food. ')
    await wrapper.find('[data-testid="creator-job-apply-submit"]').trigger('click')
    await flushPromises()

    expect(mockApi.apply).toHaveBeenCalledWith('01JOB1', { note: 'I shoot food.' })
  })

  it('treats a whitespace-only note as no note at all', async () => {
    const wrapper = await mountPage()

    await wrapper.find('[data-testid="creator-job-apply"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-testid="creator-job-apply-note"] textarea').setValue('   ')
    await wrapper.find('[data-testid="creator-job-apply-submit"]').trigger('click')
    await flushPromises()

    expect(mockApi.apply).toHaveBeenCalledWith('01JOB1', {})
  })

  // ── The states the server can hand back ───────────────────────────────────

  it('replaces Apply with a notice once the creator has applied', async () => {
    mockApi.show.mockResolvedValue(detail({ application_status: 'pending' }) as never)
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="creator-job-applied-notice"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="creator-job-apply"]').exists()).toBe(false)
  })

  it('kills Apply permanently after a rejection (D1 — no re-apply)', async () => {
    mockApi.show.mockResolvedValue(detail({ application_status: 'rejected' }) as never)
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="creator-job-rejected-notice"]').text()).toContain(
      "You weren't selected",
    )
    expect(wrapper.find('[data-testid="creator-job-apply"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="creator-job-applied-notice"]').exists()).toBe(false)
  })

  // ── The D7 bridge (AH-058) — the third state ──────────────────────────────

  it('links an accepted applicant to the offer waiting for them', async () => {
    // `assignment_state: null` is what routes this to branch 3 and is now spelled
    // out rather than inherited from the fixture: with D5 shipped, a real accepted
    // applicant carries a state and takes branch 1 instead. This case pins the
    // RETAINED fallback (Q4: no branch removed, no key deleted).
    mockApi.show.mockResolvedValue(
      detail({
        application_status: 'accepted',
        assignment_state: null,
        assignment_ulid: '01ASSIGN',
      }) as never,
    )
    const wrapper = await mountPage()

    const notice = wrapper.find('[data-testid="creator-job-accepted-notice"]')
    expect(notice.text()).toContain("You've been accepted")

    const link = wrapper.find('[data-testid="creator-job-accepted-link"]')
    expect(link.attributes('href')).toBe('/creator/assignments/01ASSIGN')

    // The three states are mutually exclusive: an accepted applicant sees
    // neither the "applied" holding notice nor a live Apply button.
    expect(wrapper.find('[data-testid="creator-job-applied-notice"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="creator-job-rejected-notice"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="creator-job-apply"]').exists()).toBe(false)
  })

  it('degrades to the notice alone when there is no assignment to link (C6)', async () => {
    // Accepted, but the assignment is gone. The page must not offer a link into
    // a 404 — the notice still tells the creator the truth about the decision.
    mockApi.show.mockResolvedValue(
      detail({ application_status: 'accepted', assignment_ulid: null }) as never,
    )
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="creator-job-accepted-notice"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="creator-job-accepted-link"]').exists()).toBe(false)
  })

  it('never shows the accepted notice for a pending application', async () => {
    mockApi.show.mockResolvedValue(
      // A stale ULID next to a pending status must not promote the state: the
      // notice branches on the STATUS, and only its link branches on the ULID.
      detail({ application_status: 'pending', assignment_ulid: '01ASSIGN' }) as never,
    )
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="creator-job-applied-notice"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="creator-job-accepted-notice"]').exists()).toBe(false)
  })

  // ── D1 + D5: the footer's branch table (AH-059) ────────────────────────────
  //
  // Branch 1 was inserted above the retained branches, so these cases assert both
  // halves of every claim: what now renders, AND what stopped.

  it('D1 case 1 — rejected + NO engagement: "Not selected" is RETAINED (§5.34)', async () => {
    mockApi.show.mockResolvedValue(
      detail({ application_status: 'rejected', assignment_state: null }) as never,
    )
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="creator-job-rejected-notice"]').text()).toContain(
      "You weren't selected",
    )
    expect(wrapper.find('[data-testid="creator-job-lifecycle-notice"]').exists()).toBe(false)
  })

  it('D1 case 2 — rejected + LIVE invitation: the stage replaces it (the eyes-on bug)', async () => {
    mockApi.show.mockResolvedValue(
      detail({
        application_status: 'rejected',
        assignment_state: 'in_progress',
        assignment_ulid: '01ASSIGN',
      }) as never,
    )
    const wrapper = await mountPage()

    const notice = wrapper.find('[data-testid="creator-job-lifecycle-notice"]')
    expect(notice.text()).toContain('This job is under way')

    // Dead, both as an element and as a string anywhere on the page.
    expect(wrapper.find('[data-testid="creator-job-rejected-notice"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain("You weren't selected")

    // And the creator can reach the engagement the notice is about.
    expect(wrapper.find('[data-testid="creator-job-lifecycle-link"]').attributes('href')).toBe(
      '/creator/assignments/01ASSIGN',
    )
  })

  it('D1 case 3 — rejected + ENDED engagement: "Ended", still not "Not selected" (Q2a)', async () => {
    mockApi.show.mockResolvedValue(
      detail({ application_status: 'rejected', assignment_state: 'ended' }) as never,
    )
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="creator-job-lifecycle-notice"]').text()).toContain(
      'This job has ended',
    )
    expect(wrapper.find('[data-testid="creator-job-rejected-notice"]').exists()).toBe(false)
  })

  it('D1 case 4 — accepted + engagement: the stage replaces the accepted notice', async () => {
    mockApi.show.mockResolvedValue(
      detail({
        application_status: 'accepted',
        assignment_state: 'completed',
        assignment_ulid: '01ASSIGN',
      }) as never,
    )
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="creator-job-lifecycle-notice"]').text()).toContain(
      'This job is complete',
    )
    expect(wrapper.find('[data-testid="creator-job-accepted-notice"]').exists()).toBe(false)
  })

  it('renders each stage, and Apply is dead in all three', async () => {
    // BREAK-REVERT ANCHOR (§5.35): move branch 1 below the rejected branch and
    // cases 2–3 above redden.
    for (const [state, copy] of [
      ['in_progress', 'This job is under way'],
      ['completed', 'This job is complete'],
      ['ended', 'This job has ended'],
    ] as const) {
      mockApi.show.mockResolvedValue(detail({ assignment_state: state }) as never)
      const wrapper = await mountPage()

      expect(wrapper.find('[data-testid="creator-job-lifecycle-notice"]').text()).toContain(copy)
      // An engagement of any stage means the job is answered: Apply is gone even
      // for the `ended` case, where the row that would block a re-apply is the
      // application's, not the assignment's.
      expect(wrapper.find('[data-testid="creator-job-apply"]').exists()).toBe(false)
    }
  })

  it('renders the stage with NO link when the bridge is absent', async () => {
    mockApi.show.mockResolvedValue(
      detail({ assignment_state: 'in_progress', assignment_ulid: null }) as never,
    )
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="creator-job-lifecycle-notice"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="creator-job-lifecycle-link"]').exists()).toBe(false)
  })

  it('surfaces the two 409 codes with distinct copy', async () => {
    mockApi.apply.mockRejectedValue(apiError(409, 'job.already_applied'))
    const already = await mountPage()
    await already.find('[data-testid="creator-job-apply"]').trigger('click')
    await flushPromises()
    await already.find('[data-testid="creator-job-apply-submit"]').trigger('click')
    await flushPromises()
    expect(snackbarText()).toContain('already applied')

    already.unmount()
    document.body.innerHTML = ''

    mockApi.apply.mockRejectedValue(apiError(409, 'job.application_rejected'))
    const rejected = await mountPage()
    await rejected.find('[data-testid="creator-job-apply"]').trigger('click')
    await flushPromises()
    await rejected.find('[data-testid="creator-job-apply-submit"]').trigger('click')
    await flushPromises()
    expect(snackbarText()).toContain("weren't selected")
  })

  it('handles a job that vanished between render and click (the apply-time 404)', async () => {
    mockApi.apply.mockRejectedValue(apiError(404))
    const wrapper = await mountPage()

    await wrapper.find('[data-testid="creator-job-apply"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-testid="creator-job-apply-submit"]').trigger('click')
    await flushPromises()

    expect(snackbarText()).toContain('no longer available')
  })
})
