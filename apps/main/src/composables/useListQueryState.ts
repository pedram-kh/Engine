/**
 * `useListQueryState` — holds a list page's browse context (page number,
 * search term, filters) in the URL query string instead of in component-local
 * refs.
 *
 * Why the URL and not a store: a list page unmounts the moment the operator
 * opens a row, so anything held in a plain `ref` is gone by the time they come
 * back — they land on page 1 with every filter cleared. The URL is the one
 * place that state survives the round trip, AND survives a reload, AND can be
 * pasted to a colleague ("page 3 of my filtered search"), AND makes browser
 * back/forward work without any extra wiring.
 *
 * Shape: declare the params up front; get back one `Ref` per param, already
 * seeded from the current URL. Bind them exactly as you would a plain ref —
 * templates and `v-model` are unchanged. Every write is a `router.replace`,
 * never a push, so paging and typing do NOT pile up history entries the
 * operator has to click back through.
 *
 *   const { page, q, country } = useListQueryState({
 *     page: pageParam,
 *     q: textParam,
 *     country: oneOfParam(COUNTRY_CODES),
 *   })
 *
 * The URL is user-editable, and these values are threaded straight into API
 * requests, so every codec VALIDATES rather than trusts: a non-numeric page, a
 * country that is not in the bounded vocabulary, a malformed date all read as
 * "unset" and fall back to the default. A param sitting at its default is
 * omitted from the URL entirely, so an untouched list stays on a clean `/path`.
 *
 * Seeding is one-way (URL → refs, at setup). Nothing watches the route back
 * into the refs, so there is no write/read feedback loop; and because updates
 * `replace` rather than push, there is no same-route history to go back to.
 */

import { ref, watch, type Ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { LocationQuery, LocationQueryRaw, LocationQueryValue } from 'vue-router'

/**
 * A single query param's codec.
 *
 * `parse` receives the raw URL value (`null` when the param is absent or
 * empty) and MUST return a usable value for any input — it is the validation
 * boundary for a string the operator can type by hand.
 *
 * `serialize` returns `null` to mean "leave this param out of the URL", which
 * is how a param at its default keeps the URL clean.
 *
 * Both are declared as methods (not arrow properties) so a `QueryParam<number>`
 * stays assignable to the `QueryParam<unknown>` the schema is keyed on.
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
 * A page size, bounded to the sizes the list actually offers — an arbitrary
 * `?per_page=100000` from the URL bar must not become an API request.
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

/** One of a bounded vocabulary; anything else — including absent — reads as unset. */
export function oneOfParam<T extends string>(allowed: readonly T[]): QueryParam<T | null> {
  return {
    parse(raw) {
      return raw !== null && (allowed as readonly string[]).includes(raw) ? (raw as T) : null
    },
    serialize(value) {
      return value
    },
  }
}

/**
 * One of a bounded vocabulary, where "no filter" is itself one of the values
 * (a chip group with an "Active"/"All" default rather than a clearable select).
 * The default is omitted from the URL.
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

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/

/** A `YYYY-MM-DD` bound (a native date input); any other shape reads as unset. */
export const isoDateParam: QueryParam<string | null> = {
  parse(raw) {
    return raw !== null && ISO_DATE.test(raw) ? raw : null
  },
  serialize(value) {
    return value !== null && ISO_DATE.test(value) ? value : null
  },
}

/** Query equality over the raw shapes vue-router hands back (string | null | array). */
function sameQuery(a: LocationQuery, b: LocationQueryRaw): boolean {
  const aKeys = Object.keys(a)
  const bKeys = Object.keys(b)
  if (aKeys.length !== bKeys.length) return false
  return aKeys.every((key) => String(a[key]) === String(b[key]))
}

export function useListQueryState<S extends QueryParamSchema>(schema: S): ListQueryRefs<S> {
  const route = useRoute()
  const router = useRouter()

  const entries = Object.entries(schema).map(([key, param]) => ({
    key,
    param,
    model: ref(param.parse(firstValue(route.query[key]))),
  }))

  watch(
    // A fresh array each run, so the callback fires whenever ANY param moves —
    // and only then (the getter re-runs on a tracked change, not every tick).
    () => entries.map((entry) => entry.model.value),
    () => {
      // Foreign params (anything this list did not declare) are carried
      // through untouched rather than dropped.
      const next: LocationQueryRaw = { ...route.query }
      for (const { key, param, model } of entries) {
        const raw = param.serialize(model.value)
        if (raw === null) {
          delete next[key]
        } else {
          next[key] = raw
        }
      }
      if (!sameQuery(route.query, next)) {
        void router.replace({ query: next })
      }
    },
  )

  const refs: Record<string, Ref<unknown>> = {}
  for (const { key, model } of entries) {
    refs[key] = model
  }
  return refs as ListQueryRefs<S>
}
