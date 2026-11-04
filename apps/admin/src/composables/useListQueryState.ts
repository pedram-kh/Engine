/**
 * `useListQueryState` — holds an admin list page's browse context (page,
 * page size, a status chip, a search term) in the URL query string instead of
 * in component-local refs.
 *
 * Mirror of `apps/main/src/composables/useListQueryState.ts` (AH-076), which
 * is the reference for WHY the URL: a list unmounts the moment the operator
 * opens a row, so anything in a plain `ref` is gone when they come back. The
 * URL survives the round trip, a reload, and a pasted link, and makes browser
 * back work with no extra wiring. Every write is a `router.replace`, never a
 * push, so paging and typing leave no history to click back through; the
 * entry behind a detail page is therefore the list's own URL.
 *
 * Diverges from main's in four ways (AH-117):
 *
 *   1. TWO-WAY. Main seeds the refs once, at setup. This one also re-seeds
 *      them when `route.query` changes underneath the page (a sidebar click on
 *      the list you are already on goes to the bare URL; a pasted link). A
 *      re-seed compares SERIALIZED values, so a trailing space typed into a
 *      text ref is not eaten by its own write-back, and a route change that is
 *      only this page's own write landing sets nothing. (A newer `replace`
 *      supersedes an older one still in flight, so the route never lands on a
 *      state the refs have already moved past.)
 *   2. THE PAGE-1 RESET LIVES HERE. When the schema declares `page`, a change
 *      to any other param (except `per_page`) while `page` was not itself
 *      changed sets `page` to 1, in the same write. It is derived from what
 *      changed against what the URL last held, not from a flag, so a re-seed
 *      (refs equal the route by construction) can never trigger it: a link to
 *      `?status=approved&page=3` keeps page 3.
 *   3. A ROUTE-DRIVEN RE-SEED SCROLLS TO THE TOP. It is not a user edit of the
 *      list (a user edit writes the URL itself and never re-seeds), so the
 *      reset list starts at its top. Setup-time seeding does not scroll: back
 *      onto a list is restored by the router's `scrollBehavior`.
 *   4. FOUR CODECS ONLY. No `boundedIntParam`, `isoDateParam` or `oneOfParam`:
 *      no staged admin list uses them. The follow-up (Audit, Impersonation,
 *      Push deliveries) adds `isoDateParam`.
 *
 * The URL is user-editable and these values are threaded into API requests, so
 * every codec VALIDATES: a non-numeric page, a page size that is not one the
 * table offers, a status outside the chip vocabulary all read as the default.
 * A param at its default is omitted, so an untouched list stays on a clean
 * `/path`.
 *
 * The composable is bound to the route it was set up on: it neither re-seeds
 * from, nor writes to, any other path. (Navigating from the list to a detail
 * page changes `route.query` while the list is still mounted for one tick; it
 * must not read that as "reset" or write onto the detail page's URL.)
 */

import { ref, watch, type Ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { LocationQueryRaw, LocationQueryValue } from 'vue-router'

/**
 * A single query param's codec.
 *
 * `parse` receives the raw URL value (`null` when the param is absent or
 * empty) and MUST return a usable value for any input: it is the validation
 * boundary for a string the operator can type by hand.
 *
 * `serialize` returns `null` to mean "leave this param out of the URL", which
 * is how a param at its default keeps the URL clean.
 */
export interface QueryParam<T> {
  parse(raw: string | null): T
  serialize(value: T): string | null
}

type QueryParamSchema = Record<string, QueryParam<unknown>>

export type ListQueryRefs<S extends QueryParamSchema> = {
  [K in keyof S]: S[K] extends QueryParam<infer T> ? Ref<T> : never
}

/** The first usable string for a param; `null` for absent, empty, or non-string. */
function firstValue(raw: LocationQueryValue | LocationQueryValue[] | undefined): string | null {
  const value = Array.isArray(raw) ? (raw[0] ?? null) : (raw ?? null)
  return typeof value === 'string' && value !== '' ? value : null
}

/** A 1-based page number. Anything that is not a positive integer reads as page 1. */
export const pageParam: QueryParam<number> = {
  parse(raw) {
    if (raw === null) return 1
    const parsed = Number(raw)
    return Number.isInteger(parsed) && parsed >= 1 ? parsed : 1
  },
  serialize(value) {
    return value > 1 ? String(value) : null
  },
}

/**
 * A page size, bounded to the sizes the table actually offers: an arbitrary
 * `?per_page=100000` (or Vuetify's "All", -1) from the URL bar must not become
 * an API request.
 */
export function perPageParam(allowed: readonly number[], fallback: number): QueryParam<number> {
  return {
    parse(raw) {
      if (raw === null) return fallback
      const parsed = Number(raw)
      return allowed.includes(parsed) ? parsed : fallback
    },
    serialize(value) {
      return value === fallback ? null : String(value)
    },
  }
}

/**
 * Free text. Typed as nullable because Vuetify's `clearable` writes `null` on
 * clear; readers should trim through a computed rather than touch `.value`
 * directly.
 */
export const textParam: QueryParam<string | null> = {
  parse(raw) {
    return raw
  },
  serialize(value) {
    const trimmed = (value ?? '').trim()
    return trimmed === '' ? null : trimmed
  },
}

/**
 * One of a bounded vocabulary, where "no filter" is itself one of the values
 * (a chip group with a default). The default is omitted from the URL.
 */
export function oneOfParamWithFallback<T extends string>(
  allowed: readonly T[],
  fallback: T,
): QueryParam<T> {
  return {
    parse(raw) {
      return raw !== null && (allowed as readonly string[]).includes(raw) ? (raw as T) : fallback
    },
    serialize(value) {
      return value === fallback ? null : value
    },
  }
}

/**
 * Is `page` past the last page the server reports? Used by a list's load: a
 * stale link (`?page=9` after the last approvals were cleared) gets an empty
 * response whose `meta.last_page` says where to go instead. `lastPage < 1` is
 * never "past" (an empty list reports 1, but be safe).
 */
export function exceedsLastPage(page: number, lastPage: number): boolean {
  return lastPage >= 1 && page > lastPage
}

export function useListQueryState<S extends QueryParamSchema>(schema: S): ListQueryRefs<S> {
  const route = useRoute()
  const router = useRouter()

  // Bound to the route this list lives on (see the header).
  const listPath = route.path

  const entries = Object.entries(schema).map(([key, param]) => ({
    key,
    param,
    model: ref(param.parse(firstValue(route.query[key]))),
  }))

  const pageEntry = entries.find((entry) => entry.key === 'page') ?? null

  // What the URL last held (or, while a write is in flight, will hold), per
  // param, in serialized form. The page-1 reset is "what changed against this".
  const synced: Record<string, string | null> = {}
  for (const { key, param, model } of entries) {
    synced[key] = param.serialize(model.value)
  }

  /** Bring the refs in line with the route, scrolling to the top if anything moved. */
  function reseedFromRoute(): void {
    let moved = false
    for (const entry of entries) {
      const parsed = entry.param.parse(firstValue(route.query[entry.key]))
      const serialized = entry.param.serialize(parsed)
      if (serialized !== entry.param.serialize(entry.model.value)) {
        entry.model.value = parsed
        moved = true
      }
      synced[entry.key] = serialized
    }
    if (moved) window.scrollTo({ top: 0 })
  }

  // Route → refs.
  watch(
    () => route.query,
    () => {
      if (route.path !== listPath) return
      reseedFromRoute()
    },
  )

  // Refs → route.
  watch(
    () => entries.map((entry) => entry.model.value),
    () => {
      if (route.path !== listPath) return

      const current: Record<string, string | null> = {}
      for (const { key, param, model } of entries) {
        current[key] = param.serialize(model.value)
      }
      const changed = entries.filter((entry) => current[entry.key] !== synced[entry.key])
      if (changed.length === 0) return

      // The page-1 reset: something other than the page (and the page size,
      // which resets its own page) changed while the page did not.
      if (
        pageEntry !== null &&
        !changed.includes(pageEntry) &&
        changed.some((entry) => entry !== pageEntry && entry.key !== 'per_page') &&
        pageEntry.model.value !== 1
      ) {
        pageEntry.model.value = 1
        current[pageEntry.key] = pageEntry.param.serialize(1)
      }

      // Foreign params (anything this list did not declare) are carried
      // through untouched rather than dropped.
      const next: LocationQueryRaw = { ...route.query }
      for (const { key } of entries) {
        const raw = current[key]
        if (raw === null) {
          delete next[key]
        } else {
          next[key] = raw
        }
      }
      for (const { key } of entries) synced[key] = current[key] ?? null

      void router.replace({ query: next })
    },
  )

  const refs: Record<string, Ref<unknown>> = {}
  for (const { key, model } of entries) {
    refs[key] = model
  }
  return refs as ListQueryRefs<S>
}
