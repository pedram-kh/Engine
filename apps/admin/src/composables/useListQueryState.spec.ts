/**
 * Vitest coverage for the admin `useListQueryState` (AH-117, S1) — the
 * composable that keeps a list page's browse context in the URL so it survives
 * the round trip into a detail page.
 *
 * Mirror of `apps/main/src/composables/useListQueryState.spec.ts`, plus the
 * four divergences the composable documents: two-way re-seed, the page-1 reset
 * rule, the scroll to the top on a route-driven re-seed, and the trimmed codec
 * set.
 *
 * Mounted through a real component + memory router rather than called bare:
 * the composable's whole job is a route side effect.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, nextTick, type Ref } from 'vue'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'

import {
  exceedsLastPage,
  oneOfParamWithFallback,
  pageParam,
  perPageParam,
  textParam,
  useListQueryState,
} from './useListQueryState'

const STATUSES = ['pending', 'approved', 'rejected', 'all'] as const
const KYC = ['all', 'pending', 'verified'] as const

type Harness = {
  state: {
    page: Ref<number>
    per_page: Ref<number>
    q: Ref<string | null>
    status: Ref<(typeof STATUSES)[number]>
    kyc_status: Ref<(typeof KYC)[number]>
  }
  router: Router
  unmount: () => void
}

async function mountHarness(url = '/list'): Promise<Harness> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div />' } },
      { path: '/list', name: 'list', component: { template: '<div />' } },
      { path: '/detail', name: 'detail', component: { template: '<div />' } },
    ],
  })
  await router.push(url)
  await router.isReady()

  let captured: Harness['state'] | null = null

  const Host = defineComponent({
    setup() {
      captured = useListQueryState({
        page: pageParam,
        per_page: perPageParam([10, 25, 50, 100], 25),
        q: textParam,
        status: oneOfParamWithFallback(STATUSES, 'pending'),
        kyc_status: oneOfParamWithFallback(KYC, 'all'),
      })
      return () => null
    },
  })

  const wrapper = mount(Host, { global: { plugins: [router] } })
  await flushPromises()

  if (captured === null) throw new Error('composable did not run')
  return { state: captured, router, unmount: () => wrapper.unmount() }
}

describe('useListQueryState (admin, AH-117)', () => {
  const scrollTo = vi.mocked(window.scrollTo)

  beforeEach(() => {
    scrollTo.mockClear()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  // ── seeding and validation (the §5.34 negatives: each junk value is valid in every respect but one)

  it('seeds every param from the URL, so a list re-entered from a detail page is where it was left', async () => {
    const h = await mountHarness(
      '/list?page=3&per_page=50&q=acme&status=approved&kyc_status=verified',
    )

    expect(h.state.page.value).toBe(3)
    expect(h.state.per_page.value).toBe(50)
    expect(h.state.q.value).toBe('acme')
    expect(h.state.status.value).toBe('approved')
    expect(h.state.kyc_status.value).toBe('verified')
    // Setup-time seeding does not scroll: back onto a list is `scrollBehavior`'s.
    expect(scrollTo).not.toHaveBeenCalled()

    h.unmount()
  })

  it('falls back to each default for an absent query', async () => {
    const h = await mountHarness('/list')

    expect(h.state.page.value).toBe(1)
    expect(h.state.per_page.value).toBe(25)
    expect(h.state.q.value).toBeNull()
    expect(h.state.status.value).toBe('pending')
    expect(h.state.kyc_status.value).toBe('all')

    h.unmount()
  })

  it('rejects a zero, negative, fractional or non-numeric page', async () => {
    for (const raw of ['0', '-2', '2.5', 'abc']) {
      const h = await mountHarness(`/list?page=${raw}`)
      expect(h.state.page.value).toBe(1)
      h.unmount()
    }
  })

  it('accepts only the page sizes the table offers (10, 25, 50, 100), and reads anything else as 25', async () => {
    for (const ok of [10, 25, 50, 100]) {
      const h = await mountHarness(`/list?per_page=${ok}`)
      expect(h.state.per_page.value).toBe(ok)
      h.unmount()
    }
    // 100000 from the URL bar, Vuetify's "All" (-1), a size the table does not offer, and junk.
    for (const bad of ['100000', '-1', '30', '0', 'abc']) {
      const h = await mountHarness(`/list?per_page=${bad}`)
      expect(h.state.per_page.value).toBe(25)
      h.unmount()
    }
  })

  it('reads a status outside the chip vocabulary as the default', async () => {
    const h = await mountHarness('/list?status=bogus&kyc_status=nope')

    expect(h.state.status.value).toBe('pending')
    expect(h.state.kyc_status.value).toBe('all')

    h.unmount()
  })

  it('reads the first value of a repeated param', async () => {
    const h = await mountHarness('/list?status=approved&status=rejected&page=2&page=5')

    expect(h.state.status.value).toBe('approved')
    expect(h.state.page.value).toBe(2)

    h.unmount()
  })

  it('reads an empty q as unset', async () => {
    const h = await mountHarness('/list?q=')
    expect(h.state.q.value).toBeNull()
    h.unmount()
  })

  // ── writing

  it('writes changed params back to the URL', async () => {
    const h = await mountHarness('/list')

    h.state.page.value = 4
    h.state.status.value = 'rejected'
    await flushPromises()

    // A page change made in the same tick as a chip change is kept (see the reset-rule cases).
    expect(h.router.currentRoute.value.query).toEqual({ page: '4', status: 'rejected' })

    h.unmount()
  })

  it('REPLACES rather than pushes, so paging leaves no history to unwind', async () => {
    const h = await mountHarness('/list')
    const replace = vi.spyOn(h.router, 'replace')
    const push = vi.spyOn(h.router, 'push')

    h.state.page.value = 2
    await flushPromises()
    h.state.page.value = 3
    await flushPromises()
    h.state.per_page.value = 50
    await flushPromises()

    expect(replace).toHaveBeenCalledTimes(3)
    expect(push).not.toHaveBeenCalled()

    h.unmount()
  })

  it('omits a param sitting at its default, keeping an untouched list on a clean URL', async () => {
    const h = await mountHarness('/list?page=3&status=approved&kyc_status=verified')

    h.state.page.value = 1
    h.state.status.value = 'pending'
    h.state.kyc_status.value = 'all'
    await flushPromises()

    expect(h.router.currentRoute.value.fullPath).toBe('/list')

    h.unmount()
  })

  it('trims free text and drops it when only whitespace is left', async () => {
    const h = await mountHarness('/list')

    h.state.q.value = '  acme  '
    await flushPromises()
    expect(h.router.currentRoute.value.query.q).toBe('acme')

    // Vuetify's `clearable` writes null, not ''.
    h.state.q.value = null
    await flushPromises()
    expect(h.router.currentRoute.value.query.q).toBeUndefined()

    h.unmount()
  })

  it('carries through query params it does not own', async () => {
    const h = await mountHarness('/list?highlight=01ABC')

    h.state.page.value = 2
    await flushPromises()

    expect(h.router.currentRoute.value.query).toEqual({ highlight: '01ABC', page: '2' })

    h.unmount()
  })

  it('does not write when the serialized query already equals the URL', async () => {
    const h = await mountHarness('/list?page=3')
    const replace = vi.spyOn(h.router, 'replace')

    h.state.q.value = '   ' // serializes to "unset", the same as now
    await flushPromises()

    expect(replace).not.toHaveBeenCalled()

    h.unmount()
  })

  // ── the page-1 reset rule

  it('sends the page back to 1 when a chip changes from page 3, in the same write', async () => {
    const h = await mountHarness('/list?status=approved&page=3')
    const replace = vi.spyOn(h.router, 'replace')

    h.state.status.value = 'rejected'
    await flushPromises()

    expect(h.state.page.value).toBe(1)
    expect(h.router.currentRoute.value.query).toEqual({ status: 'rejected' })
    expect(replace).toHaveBeenCalledTimes(1)

    h.unmount()
  })

  it('keeps a page change made in the same tick as a chip change', async () => {
    const h = await mountHarness('/list')

    h.state.status.value = 'approved'
    h.state.page.value = 2
    await flushPromises()

    expect(h.router.currentRoute.value.query).toEqual({ status: 'approved', page: '2' })

    h.unmount()
  })

  it('does not reset the page when only the page size changes', async () => {
    const h = await mountHarness('/list?page=3')

    h.state.per_page.value = 50
    await flushPromises()

    expect(h.state.page.value).toBe(3)
    expect(h.router.currentRoute.value.query).toEqual({ page: '3', per_page: '50' })

    h.unmount()
  })

  it('resets the page on a search term change too', async () => {
    const h = await mountHarness('/list?page=2')

    h.state.q.value = 'acme'
    await flushPromises()

    expect(h.state.page.value).toBe(1)
    expect(h.router.currentRoute.value.query).toEqual({ q: 'acme' })

    h.unmount()
  })

  // ── two-way: the re-seed

  it('RE-SEEDS every ref to its default when the route goes to the bare URL (a sidebar click)', async () => {
    const h = await mountHarness('/list?status=approved&page=3&per_page=50&q=acme')

    await h.router.replace({ path: '/list' })
    await flushPromises()

    expect(h.state.status.value).toBe('pending')
    expect(h.state.page.value).toBe(1)
    expect(h.state.per_page.value).toBe(25)
    expect(h.state.q.value).toBeNull()
    expect(h.router.currentRoute.value.fullPath).toBe('/list')

    h.unmount()
  })

  it('RE-SEEDS to a linked state and KEEPS its page (the reset rule does not fire on a route-driven change)', async () => {
    const h = await mountHarness('/list')

    await h.router.replace({ path: '/list', query: { status: 'approved', page: '3' } })
    await flushPromises()

    expect(h.state.status.value).toBe('approved')
    expect(h.state.page.value).toBe(3)
    expect(h.router.currentRoute.value.query).toEqual({ status: 'approved', page: '3' })

    h.unmount()
  })

  it('RE-SEEDS from one linked state to another and keeps the page, writing nothing back', async () => {
    const h = await mountHarness('/list?status=approved&page=3')
    const replace = vi.spyOn(h.router, 'replace')

    // Only the chip differs; the page is the same 3. A re-seed must not read that as a user chip click.
    await h.router.push({ path: '/list', query: { status: 'rejected', page: '3' } })
    await flushPromises()

    expect(h.state.status.value).toBe('rejected')
    expect(h.state.page.value).toBe(3)
    expect(h.router.currentRoute.value.query).toEqual({ status: 'rejected', page: '3' })
    expect(replace).not.toHaveBeenCalled()

    h.unmount()
  })

  it('does not loop: one user change is one replace, and the re-seed that follows sets nothing', async () => {
    const h = await mountHarness('/list')
    const replace = vi.spyOn(h.router, 'replace')

    h.state.status.value = 'approved'
    await flushPromises()

    expect(replace).toHaveBeenCalledTimes(1)
    expect(h.state.status.value).toBe('approved')
    expect(scrollTo).not.toHaveBeenCalled()

    h.unmount()
  })

  it('does not eat a trailing space typed into a text ref (the URL holds the trimmed text)', async () => {
    const h = await mountHarness('/list')

    h.state.q.value = 'acme '
    await flushPromises()

    expect(h.state.q.value).toBe('acme ')
    expect(h.router.currentRoute.value.query.q).toBe('acme')

    h.unmount()
  })

  it('ends on the LAST of two quick writes, and an in-flight write is not re-seeded away', async () => {
    const h = await mountHarness('/list')

    h.state.status.value = 'approved'
    await Promise.resolve()
    h.state.status.value = 'rejected'
    await flushPromises()

    expect(h.state.status.value).toBe('rejected')
    expect(h.router.currentRoute.value.query).toEqual({ status: 'rejected' })

    h.unmount()
  })

  it('lets an outside navigation that supersedes an in-flight write win', async () => {
    const h = await mountHarness('/list?status=approved&page=3')

    h.state.status.value = 'rejected'
    await nextTick() // the composable's write is now in flight
    // A sidebar click to the bare URL supersedes it:
    const outside = h.router.replace({ path: '/list' })
    await outside
    await flushPromises()

    expect(h.state.status.value).toBe('pending')
    expect(h.state.page.value).toBe(1)
    expect(h.router.currentRoute.value.fullPath).toBe('/list')

    h.unmount()
  })

  // ── scroll to the top on a route-driven re-seed (P3)

  it('scrolls to the top once when a route-driven re-seed moves a ref', async () => {
    const h = await mountHarness('/list?status=approved&page=3')

    await h.router.replace({ path: '/list' })
    await flushPromises()

    expect(scrollTo).toHaveBeenCalledTimes(1)
    expect(scrollTo).toHaveBeenCalledWith({ top: 0 })

    h.unmount()
  })

  it('does not scroll when the route changes to what the refs already hold', async () => {
    const h = await mountHarness('/list')

    await h.router.replace({ path: '/list', query: { foo: 'bar' } })
    await flushPromises()

    expect(scrollTo).not.toHaveBeenCalled()

    h.unmount()
  })

  it('does not scroll on a user edit', async () => {
    const h = await mountHarness('/list?page=2')

    h.state.page.value = 3
    h.state.status.value = 'approved'
    await flushPromises()

    expect(scrollTo).not.toHaveBeenCalled()

    h.unmount()
  })

  // ── bound to its own route

  it('neither re-seeds nor writes once the route has left the list (a row opened a detail page)', async () => {
    const h = await mountHarness('/list?status=approved&page=3')
    const replace = vi.spyOn(h.router, 'replace')

    await h.router.push('/detail')
    await flushPromises()

    expect(h.state.status.value).toBe('approved')
    expect(h.state.page.value).toBe(3)
    expect(scrollTo).not.toHaveBeenCalled()

    h.state.page.value = 9
    await flushPromises()

    expect(replace).not.toHaveBeenCalled()
    expect(h.router.currentRoute.value.fullPath).toBe('/detail')

    h.unmount()
  })

  it('stops writing and re-seeding once the page unmounts', async () => {
    const h = await mountHarness('/list')
    const replace = vi.spyOn(h.router, 'replace')

    h.unmount()
    h.state.page.value = 9
    await flushPromises()
    await h.router.replace({ path: '/list', query: { status: 'approved' } })
    await flushPromises()

    expect(replace).toHaveBeenCalledTimes(1) // only the test's own call
    expect(h.state.page.value).toBe(9)
    expect(h.state.status.value).toBe('pending')
  })
})

describe('codecs', () => {
  it('pageParam serializes only a page above 1', () => {
    expect(pageParam.serialize(1)).toBeNull()
    expect(pageParam.serialize(2)).toBe('2')
  })

  it('perPageParam omits the fallback and writes the others', () => {
    const codec = perPageParam([10, 25, 50, 100], 25)
    expect(codec.serialize(25)).toBeNull()
    expect(codec.serialize(50)).toBe('50')
  })

  it('oneOfParamWithFallback omits the fallback and writes the others', () => {
    const codec = oneOfParamWithFallback(STATUSES, 'pending')
    expect(codec.serialize('pending')).toBeNull()
    expect(codec.serialize('all')).toBe('all')
  })

  it('textParam keeps what was typed on parse', () => {
    expect(textParam.parse(null)).toBeNull()
    expect(textParam.parse('acme')).toBe('acme')
  })
})

describe('exceedsLastPage', () => {
  it('is true only when the page is past a real last page', () => {
    expect(exceedsLastPage(9, 3)).toBe(true)
    expect(exceedsLastPage(3, 3)).toBe(false)
    expect(exceedsLastPage(1, 0)).toBe(false)
    expect(exceedsLastPage(5, 0)).toBe(false)
    expect(exceedsLastPage(1, 1)).toBe(false)
  })
})
