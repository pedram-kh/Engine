/**
 * Vitest coverage for `useListQueryState` — the composable that keeps a list
 * page's browse context (page + search + filters) in the URL so it survives the
 * round trip into a detail page.
 *
 * Two things carry the weight here:
 *
 *   - VALIDATION. These values come out of a URL the operator can hand-edit and
 *     go straight into API requests, so every codec is tested with junk input,
 *     not just the happy value.
 *   - REPLACE, not push. Paging and typing must not stack history entries the
 *     operator has to click back through.
 *
 * Mounted through a real component + memory router rather than called bare:
 * the composable's whole job is a route side effect, and `useRoute` needs the
 * router's injection context anyway.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, type Ref } from 'vue'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'

import {
  isoDateParam,
  oneOfParam,
  oneOfParamWithFallback,
  pageParam,
  perPageParam,
  textParam,
  useListQueryState,
} from './useListQueryState'

const COUNTRIES = ['GB', 'PT', 'DE'] as const
const STATUSES = ['all', 'roster', 'prospect'] as const

type Harness = {
  state: {
    page: Ref<number>
    per_page: Ref<number>
    q: Ref<string | null>
    country: Ref<(typeof COUNTRIES)[number] | null>
    status: Ref<(typeof STATUSES)[number]>
    from: Ref<string | null>
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
    ],
  })
  await router.push(url)
  await router.isReady()

  let captured: Harness['state'] | null = null

  const Host = defineComponent({
    setup() {
      captured = useListQueryState({
        page: pageParam,
        per_page: perPageParam([10, 25, 50], 25),
        q: textParam,
        country: oneOfParam(COUNTRIES),
        status: oneOfParamWithFallback(STATUSES, 'all'),
        from: isoDateParam,
      })
      return () => null
    },
  })

  const wrapper = mount(Host, { global: { plugins: [router] } })
  await flushPromises()

  if (captured === null) throw new Error('composable did not run')
  return { state: captured, router, unmount: () => wrapper.unmount() }
}

describe('useListQueryState', () => {
  it('seeds every param from the URL, so a list re-entered from a detail page is where it was left', async () => {
    const h = await mountHarness(
      '/list?page=3&per_page=50&q=ada&country=PT&status=roster&from=2026-08-01',
    )

    expect(h.state.page.value).toBe(3)
    expect(h.state.per_page.value).toBe(50)
    expect(h.state.q.value).toBe('ada')
    expect(h.state.country.value).toBe('PT')
    expect(h.state.status.value).toBe('roster')
    expect(h.state.from.value).toBe('2026-08-01')

    h.unmount()
  })

  it('falls back to defaults for an absent query', async () => {
    const h = await mountHarness('/list')

    expect(h.state.page.value).toBe(1)
    expect(h.state.per_page.value).toBe(25)
    expect(h.state.q.value).toBeNull()
    expect(h.state.country.value).toBeNull()
    expect(h.state.status.value).toBe('all')
    expect(h.state.from.value).toBeNull()

    h.unmount()
  })

  it('rejects hand-typed junk rather than threading it into an API request', async () => {
    const h = await mountHarness(
      '/list?page=notanumber&per_page=100000&country=ZZ&status=bogus&from=17-08-2026',
    )

    expect(h.state.page.value).toBe(1)
    expect(h.state.per_page.value).toBe(25)
    expect(h.state.country.value).toBeNull()
    expect(h.state.status.value).toBe('all')
    expect(h.state.from.value).toBeNull()

    h.unmount()
  })

  it('rejects a zero, negative, or fractional page', async () => {
    for (const raw of ['0', '-2', '1.5']) {
      const h = await mountHarness(`/list?page=${raw}`)
      expect(h.state.page.value).toBe(1)
      h.unmount()
    }
  })

  it('writes changed params back to the URL', async () => {
    const h = await mountHarness('/list')

    h.state.page.value = 4
    h.state.country.value = 'GB'
    await flushPromises()

    expect(h.router.currentRoute.value.query).toEqual({ page: '4', country: 'GB' })

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

    expect(replace).toHaveBeenCalledTimes(2)
    expect(push).not.toHaveBeenCalled()

    h.unmount()
  })

  it('omits a param sitting at its default, keeping an untouched list on a clean URL', async () => {
    const h = await mountHarness('/list?page=3&country=GB')

    h.state.page.value = 1
    h.state.country.value = null
    await flushPromises()

    expect(h.router.currentRoute.value.query).toEqual({})
    expect(h.router.currentRoute.value.fullPath).toBe('/list')

    h.unmount()
  })

  it('trims free text and drops it when only whitespace is left', async () => {
    const h = await mountHarness('/list')

    h.state.q.value = '  ada  '
    await flushPromises()
    expect(h.router.currentRoute.value.query.q).toBe('ada')

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

  it('stops writing once the page unmounts, so navigating away cannot rewrite the URL', async () => {
    const h = await mountHarness('/list')
    const replace = vi.spyOn(h.router, 'replace')

    h.unmount()
    h.state.page.value = 9
    await flushPromises()

    expect(replace).not.toHaveBeenCalled()
  })
})
