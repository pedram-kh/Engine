/**
 * Vitest coverage for `useBackToList` — the detail-page "← Back" button.
 *
 * The distinction under test is the whole point of the helper: coming FROM the
 * list means unwinding history (which restores the list's own URL, and with it
 * the page + filters it was holding); arriving any other way means there is no
 * context to restore and a plain push is correct.
 *
 * These mount on `createWebHistory`, not the memory history the page specs use:
 * the helper reads `history.state.back`, and only the browser history records
 * it. jsdom's session history is the real thing, so each test resets the
 * window location first to keep the entries from leaking between cases.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import { createRouter, createWebHistory, type Router } from 'vue-router'

import { useBackToList } from './useBackToList'

interface Harness {
  back: () => void
  router: Router
  unmount: () => void
}

/** Walk the given paths in order, then mount the helper on the last one. */
async function mountHarness(...path: string[]): Promise<Harness> {
  const router = createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div />' } },
      { path: '/discover', name: 'discover.list', component: { template: '<div />' } },
      { path: '/discover/:ulid', name: 'discover.detail', component: { template: '<div />' } },
    ],
  })
  for (const step of path) {
    await router.push(step)
  }
  await router.isReady()

  let captured: (() => void) | null = null
  const Host = defineComponent({
    setup() {
      captured = useBackToList('discover.list')
      return () => null
    },
  })

  const wrapper = mount(Host, { global: { plugins: [router] } })
  await flushPromises()

  if (captured === null) throw new Error('composable did not run')
  return { back: captured, router, unmount: () => wrapper.unmount() }
}

describe('useBackToList', () => {
  beforeEach(() => {
    window.history.replaceState(null, '', '/')
  })

  it('unwinds history when the previous entry IS the list, restoring the URL that held its page and filters', async () => {
    const h = await mountHarness('/discover?page=3&country=PT', '/discover/01CREATOR')
    const back = vi.spyOn(h.router, 'back')
    const push = vi.spyOn(h.router, 'push')

    h.back()
    await flushPromises()

    expect(back).toHaveBeenCalledTimes(1)
    expect(push).not.toHaveBeenCalled()
    // The entry it is unwinding to is the filtered list, not a bare /discover.
    expect(window.history.state.back).toBe('/discover?page=3&country=PT')

    h.unmount()
  })

  it('pushes the bare list when the detail page was deep-linked (nothing to unwind)', async () => {
    const h = await mountHarness('/discover/01CREATOR')
    const back = vi.spyOn(h.router, 'back')
    const push = vi.spyOn(h.router, 'push')

    h.back()
    await flushPromises()

    expect(push).toHaveBeenCalledWith({ name: 'discover.list' })
    expect(back).not.toHaveBeenCalled()
    expect(h.router.currentRoute.value.fullPath).toBe('/discover')

    h.unmount()
  })

  it('pushes the bare list when the previous entry is some other page', async () => {
    const h = await mountHarness('/', '/discover/01CREATOR')
    const back = vi.spyOn(h.router, 'back')
    const push = vi.spyOn(h.router, 'push')

    h.back()
    await flushPromises()

    expect(push).toHaveBeenCalledWith({ name: 'discover.list' })
    expect(back).not.toHaveBeenCalled()

    h.unmount()
  })
})
