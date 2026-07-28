/**
 * Component tests for the creator jobs board (AH-056, D4/D9).
 *
 * The page renders what the server sends and filters nothing, so what is worth
 * pinning is the rendering itself: the brand subset appears, the applicant
 * count appears, the recency chip is computed from `listed_at` (and stays
 * ABSENT when that is null rather than inventing a date), the caller's own
 * application status shows on the card, and an empty board is an empty state
 * rather than an error.
 */

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
import CreatorJobsPage from './CreatorJobsPage.vue'

const mockApi = vi.mocked(creatorJobsApi)

function card(overrides: Record<string, unknown> = {}) {
  return {
    id: '01JOB1',
    type: 'creator_job',
    attributes: {
      name: 'Autumn UGC push',
      listing_fee: '€300 per video',
      listing_duration: '4 weeks',
      applicant_count: 3,
      listed_at: new Date().toISOString(),
      application_status: null,
      brand: { name: 'Northwind Coffee', logo_url: 'https://cdn.test/logo.png' },
      ...overrides,
    },
  }
}

function envelope(data: unknown[], lastPage = 1) {
  return { data, meta: { total: data.length, page: 1, per_page: 12, last_page: lastPage } }
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
    ],
  })
  await router.push('/creator/jobs')
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en: enCreator } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const wrapper = mount(CreatorJobsPage, {
    global: {
      plugins: [
        router,
        i18n,
        createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives }),
      ],
    },
  })
  await flushPromises()
  return wrapper
}

describe('CreatorJobsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockApi.list.mockResolvedValue(envelope([card()]) as never)
  })

  it('renders a card with the brand subset, fee, duration and applicant count', async () => {
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="creator-job-brand-01JOB1"]').text()).toBe('Northwind Coffee')
    expect(wrapper.find('[data-testid="creator-job-fee-01JOB1"]').text()).toContain(
      '€300 per video',
    )
    expect(wrapper.find('[data-testid="creator-job-applicants-01JOB1"]').text()).toBe(
      '3 applicants',
    )
    expect(wrapper.find('[data-testid="creator-job-logo-01JOB1"]').exists()).toBe(true)
  })

  it('pluralizes a single applicant', async () => {
    mockApi.list.mockResolvedValue(envelope([card({ applicant_count: 1 })]) as never)
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="creator-job-applicants-01JOB1"]').text()).toBe('1 applicant')
  })

  it('renders "Listed today" for a job listed today', async () => {
    const wrapper = await mountPage()
    expect(wrapper.find('[data-testid="creator-job-recency-01JOB1"]').text()).toBe('Listed today')
  })

  it('counts back the days for an older listing', async () => {
    const threeDaysAgo = new Date(Date.now() - 3 * 24 * 60 * 60 * 1000).toISOString()
    mockApi.list.mockResolvedValue(envelope([card({ listed_at: threeDaysAgo })]) as never)
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="creator-job-recency-01JOB1"]').text()).toBe(
      'Listed 3 days ago',
    )
  })

  it('renders NO recency chip when listed_at is null — no invented date', async () => {
    mockApi.list.mockResolvedValue(envelope([card({ listed_at: null })]) as never)
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="creator-job-recency-01JOB1"]').exists()).toBe(false)
    // The rest of the card still renders — a missing stamp is not a broken card.
    expect(wrapper.find('[data-testid="creator-job-01JOB1"]').exists()).toBe(true)
  })

  it("shows the caller's own application status when they have applied", async () => {
    mockApi.list.mockResolvedValue(envelope([card({ application_status: 'pending' })]) as never)
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="creator-job-applied-01JOB1"]').text()).toBe('Applied')
  })

  it('renders a rejected application as "Not selected"', async () => {
    mockApi.list.mockResolvedValue(envelope([card({ application_status: 'rejected' })]) as never)
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="creator-job-applied-01JOB1"]').text()).toBe('Not selected')
  })

  it('renders an accepted application as "Accepted" (AH-058 D7 — a c3 chip, pinned)', async () => {
    // The accepted chip is a side effect of the c3 chip being status-keyed, and
    // chunk 4 is the release that can actually produce the state. Asserted here
    // rather than rebuilt: the card's job is to get the creator to the detail
    // page, which owns the link to the offer.
    mockApi.list.mockResolvedValue(envelope([card({ application_status: 'accepted' })]) as never)
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="creator-job-applied-01JOB1"]').text()).toBe('Accepted')
  })

  it('shows the empty state — an empty board is a state, not an error', async () => {
    mockApi.list.mockResolvedValue(envelope([]) as never)
    const wrapper = await mountPage()

    expect(wrapper.find('[data-test="creator-jobs-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="creator-jobs-list"]').exists()).toBe(false)
  })

  it('falls back to the empty state when the request fails', async () => {
    mockApi.list.mockRejectedValue(new Error('network'))
    const wrapper = await mountPage()

    expect(wrapper.find('[data-test="creator-jobs-empty"]').exists()).toBe(true)
  })

  it('hides pagination on a single page and shows it on more', async () => {
    const single = await mountPage()
    expect(single.find('[data-testid="creator-jobs-pagination"]').exists()).toBe(false)

    mockApi.list.mockResolvedValue(envelope([card()], 4) as never)
    const many = await mountPage()
    expect(many.find('[data-testid="creator-jobs-pagination"]').exists()).toBe(true)
  })

  it('requests the first page on mount', async () => {
    await mountPage()
    expect(mockApi.list).toHaveBeenCalledWith({ page: 1 })
  })
})
