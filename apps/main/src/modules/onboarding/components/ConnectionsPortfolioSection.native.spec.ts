/**
 * AH-100d — the portfolio as the creator app draws it (Figma frame 04): an
 * Image / Video / Link pill on each tile, an "N items" count beside the
 * heading, and no second file row for an upload that is already a tile.
 * Web polarity beside it: none of the native markup, every queue row kept.
 */

import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

const platform = vi.hoisted(() => ({ native: false }))
vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
}))

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: { bootstrap: vi.fn() },
}))

const queue = vi.hoisted(() => ({ items: [] as Array<Record<string, unknown>> }))
vi.mock('../composables/usePortfolioUpload', async (importOriginal) => {
  const { ref, computed } = await import('vue')
  const actual = await importOriginal<typeof import('../composables/usePortfolioUpload')>()
  return {
    ...actual,
    usePortfolioUpload: () => ({
      items: ref(queue.items),
      hasError: computed(() => false),
      inFlightCount: computed(() => 0),
      remainingSlots: computed(() => 20),
      enqueue: vi.fn(),
      addLink: vi.fn(),
      remove: vi.fn(),
      reset: vi.fn(),
    }),
  }
})

import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import ConnectionsPortfolioSection from './ConnectionsPortfolioSection.vue'

let teardown: (() => void) | null = null

function tile(id: string, kind: 'image' | 'video' | 'link', title: string) {
  return {
    id,
    kind,
    title,
    description: null,
    view_url: kind === 'image' ? `https://cdn.example.test/${id}.jpg` : null,
    thumbnail_view_url: null,
    external_url: kind === 'link' ? 'https://example.test/reel' : null,
    download_url: null,
    processing_status: 'ready',
  }
}

function creator(portfolio: Array<Record<string, unknown>>): never {
  return {
    data: {
      id: '01',
      type: 'creators',
      attributes: {
        display_name: 'Sofia',
        application_status: 'incomplete',
        social_accounts: [],
        portfolio,
      },
      wizard: { next_step: 'social', is_submitted: false, steps: [], weights: {}, flags: {} },
    },
  } as never
}

const THREE = [
  tile('a', 'image', 'Summer lookbook'),
  tile('b', 'video', 'Product unboxing'),
  tile('c', 'link', 'Reel'),
]

async function mountIt(native: boolean, portfolio = THREE) {
  platform.native = native
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue(creator(portfolio))
  const mounted = await mountAuthPage(ConnectionsPortfolioSection, {
    initialRoute: { path: '/' },
    beforeMount: async () => {
      await useOnboardingStore().bootstrap()
    },
  })
  teardown = mounted.unmount
  await flushPromises()
  return mounted.wrapper
}

function queued(id: string, status: string) {
  return {
    id,
    file: new File(['x'], `${id}.jpg`, { type: 'image/jpeg' }),
    kind: 'image',
    status,
    progress: status === 'done' ? 100 : 0,
    errorKey: null,
    errorValues: {},
    portfolioId: status === 'done' ? id : null,
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  queue.items = []
})

afterEach(() => {
  teardown?.()
  teardown = null
  platform.native = false
})

describe('portfolio — native tiles (frame 04)', () => {
  it('puts an Image / Video / Link pill on each tile', async () => {
    const wrapper = await mountIt(true)

    expect(wrapper.find('[data-testid="portfolio-gallery-kind-a"]').text()).toBe('Image')
    expect(wrapper.find('[data-testid="portfolio-gallery-kind-b"]').text()).toBe('Video')
    expect(wrapper.find('[data-testid="portfolio-gallery-kind-c"]').text()).toBe('Link')
  })

  it('shows the heading with its item count', async () => {
    const wrapper = await mountIt(true)

    expect(wrapper.find('.portfolio-step__gallery-head h4').text()).toBe('Your portfolio')
    expect(wrapper.find('[data-testid="portfolio-step-count"]').text()).toBe('3 items')
  })

  it('says "1 item" for a single tile and shows no count for an empty gallery', async () => {
    const one = await mountIt(true, [THREE[0] as (typeof THREE)[number]])
    expect(one.find('[data-testid="portfolio-step-count"]').text()).toBe('1 item')
    teardown?.()

    const none = await mountIt(true, [])
    expect(none.find('[data-testid="portfolio-step-count"]').exists()).toBe(false)
  })

  it('does not list a finished upload a second time, but keeps one still in flight', async () => {
    queue.items = [
      queued('done-1', 'done'),
      queued('busy-1', 'uploading'),
      queued('bad-1', 'error'),
    ]
    const wrapper = await mountIt(true)

    expect(wrapper.find('[data-testid="portfolio-upload-item-done"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="portfolio-upload-item-uploading"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="portfolio-upload-item-error"]').exists()).toBe(true)
  })

  it('hides the whole queue list once every row is done', async () => {
    queue.items = [queued('done-1', 'done'), queued('done-2', 'done')]
    const wrapper = await mountIt(true)

    expect(wrapper.find('[data-testid="portfolio-upload-list"]').exists()).toBe(false)
  })
})

describe('portfolio — web is unchanged', () => {
  it('draws no pill and no count, and keeps the plain heading', async () => {
    const wrapper = await mountIt(false)

    expect(wrapper.find('.portfolio-gallery__kind').exists()).toBe(false)
    expect(wrapper.find('[data-testid="portfolio-step-count"]').exists()).toBe(false)
    expect(wrapper.find('.portfolio-step__gallery-head').exists()).toBe(false)
    expect(wrapper.find('.portfolio-step__gallery > h4').text()).toBe('Your portfolio')
  })

  it('keeps every queue row, finished ones included', async () => {
    queue.items = [queued('done-1', 'done'), queued('busy-1', 'uploading')]
    const wrapper = await mountIt(false)

    expect(wrapper.find('[data-testid="portfolio-upload-item-done"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="portfolio-upload-item-uploading"]').exists()).toBe(true)
  })
})
