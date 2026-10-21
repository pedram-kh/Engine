/**
 * Vitest coverage for `useShellBack` (AH-106, item 1).
 *
 * Two things are pinned: WHICH pages get the arrow (against the real creator
 * route records, so a rename or a new page cannot slip through unclassified),
 * and WHERE it goes (history only when the previous entry is another
 * creator-shell page; the dashboard otherwise).
 *
 * The `goBack` cases mount on `createWebHistory`, not memory history: the
 * helper reads `history.state.back`, which only the browser history records
 * (the `useBackToList` spec's reason, verbatim).
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import { createRouter, createWebHistory, type Router } from 'vue-router'

import { creatorsRoutes } from '@/modules/creators/routes'

import {
  SHELL_OWN_BACK_ROUTE_NAMES,
  SHELL_TAB_ROUTE_NAMES,
  showsShellBack,
  useShellBack,
  type ShellBack,
} from './useShellBack'

describe('showsShellBack — which pages get the arrow', () => {
  const creatorNames = creatorsRoutes
    .flatMap((r) => [r, ...(r.children ?? [])])
    .map((r) => r.name)
    .filter((n): n is string => typeof n === 'string')

  it('names only routes that exist in the creator route table', () => {
    for (const name of [...SHELL_TAB_ROUTE_NAMES, ...SHELL_OWN_BACK_ROUTE_NAMES]) {
      expect(creatorNames, `${name} is not a creator route`).toContain(name)
    }
  })

  it('shows on every creator page that is neither a tab nor already carries its own back', () => {
    const shown = creatorNames.filter((n) => showsShellBack(n)).sort()
    expect(shown).toEqual([
      'creator.availability',
      'creator.notifications',
      'creator.notifications.preferences',
      'creator.profile',
      // The Security page (AH-104b-del B4) draws no back control of its own, so the shell's arrow is the
      // way out of it, as for the profile and availability pages.
      'creator.security',
    ])
  })

  it('is hidden on the four tabs', () => {
    for (const name of SHELL_TAB_ROUTE_NAMES) {
      expect(showsShellBack(name)).toBe(false)
    }
  })

  it('is hidden on the pages that draw their own back control', () => {
    for (const name of SHELL_OWN_BACK_ROUTE_NAMES) {
      expect(showsShellBack(name)).toBe(false)
    }
  })

  it('is hidden when the route has no name', () => {
    expect(showsShellBack(undefined)).toBe(false)
  })
})

interface Harness {
  shell: ShellBack
  router: Router
  unmount: () => void
}

async function mountHarness(...path: string[]): Promise<Harness> {
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/sign-in', name: 'auth.sign-in', component: stub, meta: { layout: 'auth' } },
      {
        path: '/creator/dashboard',
        name: 'creator.dashboard',
        component: stub,
        meta: { layout: 'creator' },
      },
      {
        path: '/creator/notifications',
        name: 'creator.notifications',
        component: stub,
        meta: { layout: 'creator' },
      },
      {
        path: '/creator/profile',
        name: 'creator.profile',
        component: stub,
        meta: { layout: 'creator' },
      },
    ],
  })
  for (const step of path) {
    await router.push(step)
  }
  await router.isReady()

  let captured: ShellBack | null = null
  const Host = defineComponent({
    setup() {
      captured = useShellBack()
      return () => null
    },
  })
  const wrapper = mount(Host, { global: { plugins: [router] } })
  await flushPromises()

  if (captured === null) throw new Error('composable did not run')
  return { shell: captured, router, unmount: () => wrapper.unmount() }
}

describe('useShellBack — where the arrow goes', () => {
  beforeEach(() => {
    window.history.replaceState(null, '', '/')
  })

  it('is visible on a page that qualifies and tracks the route', async () => {
    const h = await mountHarness('/creator/dashboard')
    expect(h.shell.visible.value).toBe(false)

    await h.router.push('/creator/notifications')
    expect(h.shell.visible.value).toBe(true)
    h.unmount()
  })

  it('unwinds history when the previous entry is another creator page', async () => {
    const h = await mountHarness('/creator/profile', '/creator/notifications')
    const back = vi.spyOn(h.router, 'back')
    const push = vi.spyOn(h.router, 'push')

    h.shell.goBack()
    await flushPromises()

    expect(back).toHaveBeenCalledTimes(1)
    expect(push).not.toHaveBeenCalled()
    h.unmount()
  })

  it('goes to the dashboard when there is no previous entry (a cold push-tap landing)', async () => {
    const h = await mountHarness('/creator/notifications')
    const back = vi.spyOn(h.router, 'back')

    h.shell.goBack()
    await flushPromises()

    expect(back).not.toHaveBeenCalled()
    expect(h.router.currentRoute.value.name).toBe('creator.dashboard')
    h.unmount()
  })

  it('never unwinds into the sign-in screen: the entry behind a fresh login is not a creator page', async () => {
    const h = await mountHarness('/sign-in', '/creator/notifications')
    const back = vi.spyOn(h.router, 'back')

    h.shell.goBack()
    await flushPromises()

    expect(back).not.toHaveBeenCalled()
    expect(h.router.currentRoute.value.name).toBe('creator.dashboard')
    h.unmount()
  })
})
