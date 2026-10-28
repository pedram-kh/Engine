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
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory, type RouteRecordRaw, type Router } from 'vue-router'

import { creatorsRoutes } from '@/modules/creators/routes'
import { useOnboardingStore } from '@/modules/onboarding/stores/useOnboardingStore'

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

interface WizardState {
  status: 'incomplete' | 'pending' | 'approved'
  nextStep: string
}

async function mountHarness(path: string[], wizard?: WizardState): Promise<Harness> {
  const pinia = createPinia()
  setActivePinia(pinia)
  if (wizard !== undefined) {
    useOnboardingStore().creator = {
      attributes: { application_status: wizard.status },
      wizard: { next_step: wizard.nextStep, is_submitted: wizard.status !== 'incomplete' },
    } as never
  }
  const stub = { template: '<div />' }
  const onboarding = (p: string, name: string): RouteRecordRaw => ({
    path: p,
    name,
    component: stub,
    meta: { layout: 'onboarding' },
  })
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
      {
        path: '/creator/security',
        name: 'creator.security',
        component: stub,
        meta: { layout: 'creator' },
      },
      onboarding('/onboarding', 'onboarding.welcome-back'),
      onboarding('/onboarding/profile', 'onboarding.profile'),
      onboarding('/onboarding/connections', 'onboarding.connections'),
      onboarding('/onboarding/kyc', 'onboarding.kyc'),
      onboarding('/onboarding/review', 'onboarding.review'),
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
  const wrapper = mount(Host, { global: { plugins: [pinia, router] } })
  await flushPromises()

  if (captured === null) throw new Error('composable did not run')
  return { shell: captured, router, unmount: () => wrapper.unmount() }
}

describe('useShellBack — where the arrow goes', () => {
  beforeEach(() => {
    window.history.replaceState(null, '', '/')
  })

  it('is visible on a page that qualifies and tracks the route', async () => {
    const h = await mountHarness(['/creator/dashboard'])
    expect(h.shell.visible.value).toBe(false)

    await h.router.push('/creator/notifications')
    expect(h.shell.visible.value).toBe(true)
    h.unmount()
  })

  it('unwinds history when the previous entry is another creator page', async () => {
    const h = await mountHarness(['/creator/profile', '/creator/notifications'])
    const back = vi.spyOn(h.router, 'back')
    const push = vi.spyOn(h.router, 'push')

    h.shell.goBack()
    await flushPromises()

    expect(back).toHaveBeenCalledTimes(1)
    expect(push).not.toHaveBeenCalled()
    h.unmount()
  })

  it('goes to the dashboard when there is no previous entry (a cold push-tap landing)', async () => {
    const h = await mountHarness(['/creator/notifications'])
    const back = vi.spyOn(h.router, 'back')

    h.shell.goBack()
    await flushPromises()

    expect(back).not.toHaveBeenCalled()
    expect(h.router.currentRoute.value.name).toBe('creator.dashboard')
    h.unmount()
  })

  it('never unwinds into the sign-in screen: the entry behind a fresh login is not a creator page', async () => {
    const h = await mountHarness(['/sign-in', '/creator/notifications'])
    const back = vi.spyOn(h.router, 'back')

    h.shell.goBack()
    await flushPromises()

    expect(back).not.toHaveBeenCalled()
    expect(h.router.currentRoute.value.name).toBe('creator.dashboard')
    h.unmount()
  })
})

describe('useShellBack — back from Security into the wizard (AH-104d W8, 2026-10-28)', () => {
  beforeEach(() => {
    window.history.replaceState(null, '', '/')
  })

  it('goes back to the wizard step the creator left, when the entry behind is onboarding.*', async () => {
    const h = await mountHarness(['/onboarding/connections', '/creator/security'], {
      status: 'incomplete',
      nextStep: 'profile',
    })
    const back = vi.spyOn(h.router, 'back')
    const push = vi.spyOn(h.router, 'push')

    h.shell.goBack()
    await flushPromises()

    expect(back).toHaveBeenCalledTimes(1)
    expect(push).not.toHaveBeenCalled()
    // History brings them to the step they LEFT (connections), not the creator's next_step (profile).
    await vi.waitFor(() => expect(h.router.currentRoute.value.name).toBe('onboarding.connections'))
    h.unmount()
  })

  it.each([
    ['profile', 'onboarding.profile'],
    ['social', 'onboarding.connections'],
    ['portfolio', 'onboarding.connections'],
    ['kyc', 'onboarding.kyc'],
    ['review', 'onboarding.review'],
  ])(
    'with no usable history, an incomplete creator whose next step is %s lands on %s, not the dashboard',
    async (nextStep, routeName) => {
      const h = await mountHarness(['/creator/security'], { status: 'incomplete', nextStep })
      const back = vi.spyOn(h.router, 'back')

      h.shell.goBack()
      await flushPromises()

      expect(back).not.toHaveBeenCalled()
      expect(h.router.currentRoute.value.name).toBe(routeName)
      h.unmount()
    },
  )

  it('after a reload (the entry behind is sign-in) an incomplete creator still lands in the wizard', async () => {
    const h = await mountHarness(['/sign-in', '/creator/security'], {
      status: 'incomplete',
      nextStep: 'kyc',
    })
    const back = vi.spyOn(h.router, 'back')

    h.shell.goBack()
    await flushPromises()

    expect(back).not.toHaveBeenCalled()
    expect(h.router.currentRoute.value.name).toBe('onboarding.kyc')
    h.unmount()
  })

  it.each(['pending', 'approved'] as const)(
    'a %s creator (not in the wizard) still falls back to the dashboard',
    async (status) => {
      const h = await mountHarness(['/creator/security'], { status, nextStep: 'review' })

      h.shell.goBack()
      await flushPromises()

      expect(h.router.currentRoute.value.name).toBe('creator.dashboard')
      h.unmount()
    },
  )

  it('with no creator loaded at all, falls back to the dashboard rather than guessing a step', async () => {
    const h = await mountHarness(['/creator/security'])

    h.shell.goBack()
    await flushPromises()

    expect(h.router.currentRoute.value.name).toBe('creator.dashboard')
    h.unmount()
  })

  it('still unwinds history between two creator pages for an incomplete creator', async () => {
    const h = await mountHarness(['/creator/profile', '/creator/security'], {
      status: 'incomplete',
      nextStep: 'profile',
    })
    const back = vi.spyOn(h.router, 'back')

    h.shell.goBack()
    await flushPromises()

    expect(back).toHaveBeenCalledTimes(1)
    h.unmount()
  })
})
