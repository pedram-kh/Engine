/**
 * `useBackToList` — the "← Back to <list>" button on a detail page.
 *
 * The naive implementation (`router.push({ name: 'discover.list' })`) throws
 * away the browse context even when the list is holding it in its own URL: it
 * navigates to a BARE `/discover`, so the operator lands on page 1 with their
 * filters cleared, having just clicked a button that promised to take them
 * back. It also stacks a new history entry rather than unwinding one.
 *
 * So: if the entry we came from IS the list, go BACK to it — that restores its
 * full URL (page + filters, see `useListQueryState`) and lets the router's
 * `scrollBehavior` put the operator next to the row they opened. Only when we
 * arrived some other way (a deep link, a notification, another page) is there
 * no context to return to, and a plain push to the bare list is right.
 */

import { useRouter } from 'vue-router'

export function useBackToList(listRouteName: string): () => void {
  const router = useRouter()

  return function backToList(): void {
    const previous = router.options.history.state.back
    if (typeof previous === 'string' && router.resolve(previous).name === listRouteName) {
      router.back()
      return
    }
    void router.push({ name: listRouteName })
  }
}
