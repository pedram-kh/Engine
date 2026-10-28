/**
 * `useShellBack` — the creator shell's back arrow (AH-106, item 1).
 *
 * The creator shell had no back control of its own: every detail page drew its
 * own, and a page that did not (the notifications feed, the preferences page,
 * Profile, Availability) left the native app with an arrow-less screen and the
 * tab bar as the only way out. This is the one rule that answers it for every
 * page at once, web and native.
 *
 * WHICH PAGES. The arrow shows on every creator-shell page EXCEPT:
 *   - the four primary destinations (the bottom tabs): they are where "back"
 *     ends up, so an arrow on them would have nowhere to go;
 *   - the pages that already draw their own back control (job detail,
 *     invitation detail, the chat thread). Those keep their own, which names
 *     its destination ("Back to job posts"); a second arrow beside it would be
 *     two controls for one action.
 * Both lists are route NAMES, not route `meta`: the route table is untouched,
 * and `useShellBack.spec.ts` pins them against the route records so a rename
 * cannot quietly empty either list.
 *
 * WHERE IT GOES. History when there is some, otherwise a fallback.
 * "Some" means the entry we came from is another creator-shell page, or a wizard
 * step (`onboarding.*`, the account menu's Security entry, W8 2026-10-28) — the
 * same `history.state.back` read as `useBackToList`, but also checked against the
 * route, because a back from here must never unwind into the sign-in screen (a
 * fresh login lands on the dashboard with the sign-in page one entry behind it)
 * or out of the app.
 *
 * The fallback (no usable previous entry: a cold start from a push tap, a reload)
 * depends on who is asking. A creator whose application is still INCOMPLETE belongs
 * in the wizard, so they go to its current step, never to a dashboard that can only
 * tell them to go back to it. Everyone else goes to the dashboard.
 */

import { computed, type ComputedRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { useOnboardingStore } from '@/modules/onboarding/stores/useOnboardingStore'
import { isWizardRouteName, wizardCurrentStepLocation } from '@/modules/onboarding/wizardResume'

/** The bottom-tab destinations (`MOBILE_PRIMARY_KEYS` in the layout). */
export const SHELL_TAB_ROUTE_NAMES: readonly string[] = [
  'creator.dashboard',
  'creator.jobs',
  'creator.assignments',
  'creator.messages',
]

/** Pages that draw their own back control, and so skip the shell's. */
export const SHELL_OWN_BACK_ROUTE_NAMES: readonly string[] = [
  'creator.job.detail',
  'creator.assignment.detail',
  'creator.messages.thread',
]

export const SHELL_BACK_FALLBACK_ROUTE_NAME = 'creator.dashboard'

export function showsShellBack(routeName: unknown): boolean {
  return (
    typeof routeName === 'string' &&
    !SHELL_TAB_ROUTE_NAMES.includes(routeName) &&
    !SHELL_OWN_BACK_ROUTE_NAMES.includes(routeName)
  )
}

export interface ShellBack {
  visible: ComputedRef<boolean>
  goBack: () => void
}

export function useShellBack(): ShellBack {
  const route = useRoute()
  const router = useRouter()
  const onboarding = useOnboardingStore()

  const visible = computed(() => showsShellBack(route.name))

  function goBack(): void {
    const previous = router.options.history.state.back
    if (typeof previous === 'string') {
      const resolved = router.resolve(previous)
      if (resolved.meta.layout === 'creator' || isWizardRouteName(resolved.name)) {
        router.back()
        return
      }
    }
    if (onboarding.applicationStatus === 'incomplete') {
      void router.push(wizardCurrentStepLocation(onboarding.nextStep))
      return
    }
    void router.push({ name: SHELL_BACK_FALLBACK_ROUTE_NAME })
  }

  return { visible, goBack }
}
