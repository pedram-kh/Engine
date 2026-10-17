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
 * WHERE IT GOES. History when there is some, the dashboard when there is not.
 * "Some" means the entry we came from is another creator-shell page — the same
 * `history.state.back` read as `useBackToList`, but also checked against the
 * route's layout, because a back from here must never unwind into the sign-in
 * screen (a fresh login lands on the dashboard with the sign-in page one entry
 * behind it) or out of the app. A cold start from a push tap has no previous
 * entry at all, which is the case the dashboard fallback exists for.
 */

import { computed, type ComputedRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'

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

  const visible = computed(() => showsShellBack(route.name))

  function goBack(): void {
    const previous = router.options.history.state.back
    if (typeof previous === 'string' && router.resolve(previous).meta.layout === 'creator') {
      router.back()
      return
    }
    void router.push({ name: SHELL_BACK_FALLBACK_ROUTE_NAME })
  }

  return { visible, goBack }
}
