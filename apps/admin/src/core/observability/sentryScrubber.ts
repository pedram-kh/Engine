/**
 * AH-121 C2: the Sentry scrubber for the browser SPAs.
 *
 * MIRRORED: `apps/main` and `apps/admin` each carry this file BYTE-FOR-BYTE
 * (sentry-plan.md B2, Q7). `tests/unit/architecture/sentry-scrubber-parity.spec.ts`
 * in `apps/main` fails if the two copies differ, so the rule tables cannot
 * drift. Edit both, or neither. No `@/` imports here for the same reason.
 *
 * Rules (the B1 rules minus the PHP ones):
 *   1. Every URL loses its query string and fragment (`?token=`, `?email=`,
 *      `/impersonation/claim#token=`, `/sign-in?redirect=/`), including breadcrumb
 *      `data.from` / `data.to`.
 *   2. Emails and bearer-shaped strings are redacted anywhere in the event.
 *   3. `request` keeps only `url` and `method`: headers, cookies, query string
 *      and body are dropped.
 *   4. `user` is rebuilt as `{ id }` (a ulid) and nothing else.
 *   5. Breadcrumb `data` keeps an allow-list of keys; console and UI breadcrumbs
 *      are dropped.
 *   6. Sentry's own identifiers (event id, trace id, span id, parent span id, debug
 *      id) are never touched by the opaque-token rule; other 32+ character runs are.
 *   7. Fails closed: if scrubbing throws, the event is dropped, never sent raw.
 *
 * Noise (B2): an `ApiError` below HTTP 500, a network error and a cancelled
 * request are not sent; `http.invalid_response_body` is always sent.
 */

import { ApiError } from '@catalyst/api-client'
import type { Breadcrumb, ErrorEvent, EventHint } from '@sentry/vue'

/** The rule table the parity test pins. Patterns are kept as source strings. */
export const SCRUB_RULES = {
  /** Exempt from the opaque-token rule only when the value has exactly this shape. */
  sentryIdShapes: {
    event_id: '^[0-9a-f]{32}$',
    trace_id: '^[0-9a-f]{32}$',
    span_id: '^[0-9a-f]{16}$',
    parent_span_id: '^[0-9a-f]{16}$',
    debug_id: '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$',
  },
  /** Keys whose string value is a URL or a route path. */
  urlKeys: ['url', 'from', 'to', 'transaction', 'filename', 'abs_path', 'referrer'],
  /** `request` survives only with these keys. */
  requestKeep: ['url', 'method'],
  /** Breadcrumb `data` survives only with these keys. */
  breadcrumbDataKeep: ['from', 'to', 'url', 'method', 'status_code'],
  /** Breadcrumb categories dropped outright. */
  breadcrumbCategoriesDropped: ['console', 'ui.click', 'ui.input'],
  /** The only shape a `user.id` may have: a ulid. */
  userIdShape: '^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$',
  /** Text patterns, applied in this order. */
  text: {
    urlQuery: '(https?:\\/\\/[^\\s?#"\'<>]+|\\/[A-Za-z0-9._~\\-\\/]+)[?#][^\\s"\'<>]*',
    bearer: 'Bearer\\s+[A-Za-z0-9._~+\\/=\\-]+',
    sanctum: '\\d+\\|[A-Za-z0-9]{40}',
    secretPair:
      '((?:access_|refresh_|api_|auth_|id_)?(?:token|signature|password|secret|credential|code|key))=[^\\s&"\']+',
    email: '[A-Za-z0-9._%+\\-]+@[A-Za-z0-9.\\-]+\\.[A-Za-z]{2,}',
    opaque: '(?<![A-Za-z0-9_\\-])[A-Za-z0-9_\\-]{32,}(?![A-Za-z0-9_\\-])',
  },
} as const

const T = SCRUB_RULES.text
const URL_QUERY = new RegExp(T.urlQuery, 'g')
const BEARER = new RegExp(T.bearer, 'gi')
const SANCTUM = new RegExp(T.sanctum, 'g')
const SECRET_PAIR = new RegExp(T.secretPair, 'gi')
const EMAIL = new RegExp(T.email, 'g')
const OPAQUE = new RegExp(T.opaque, 'g')
const ID_SHAPES: Record<string, RegExp> = Object.fromEntries(
  Object.entries(SCRUB_RULES.sentryIdShapes).map(([k, v]) => [k, new RegExp(v)]),
)
const USER_ID = new RegExp(SCRUB_RULES.userIdShape)

const BEARER_REPLACEMENT = 'Bearer [token]'

/** Cut a URL or route path at its first `?` or `#`. */
export function stripQueryAndFragment(value: string): string {
  const cut = value.search(/[?#]/)
  return cut === -1 ? value : value.slice(0, cut)
}

function scrubString(text: string): string {
  return text
    .replace(URL_QUERY, '$1')
    .replace(BEARER, BEARER_REPLACEMENT)
    .replace(SANCTUM, '[token]')
    .replace(SECRET_PAIR, '$1=[redacted]')
    .replace(EMAIL, '[email]')
    .replace(OPAQUE, '[token]')
}

function scrubValue(key: string, value: unknown): unknown {
  if (typeof value === 'string') {
    const shape = ID_SHAPES[key]
    if (shape !== undefined && shape.test(value)) {
      return value
    }
    const base = (SCRUB_RULES.urlKeys as readonly string[]).includes(key)
      ? stripQueryAndFragment(value)
      : value
    return scrubString(base)
  }
  if (Array.isArray(value)) {
    return value.map((item) => scrubValue(key, item))
  }
  if (value !== null && typeof value === 'object') {
    return scrubObject(value as Record<string, unknown>)
  }
  return value
}

function scrubObject(input: Record<string, unknown>): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(input)) {
    out[key] = scrubValue(key, value)
  }
  return out
}

function scrubBreadcrumbData(data: unknown): Record<string, unknown> | undefined {
  if (data === null || typeof data !== 'object') {
    return undefined
  }
  const kept: Record<string, unknown> = {}
  for (const key of SCRUB_RULES.breadcrumbDataKeep) {
    const value = (data as Record<string, unknown>)[key]
    if (value !== undefined) {
      kept[key] = scrubValue(key, value)
    }
  }
  return kept
}

/** `beforeBreadcrumb`: drops console/UI crumbs, keeps an allow-list of data keys. */
export function scrubBreadcrumb(breadcrumb: Breadcrumb): Breadcrumb | null {
  if (
    (SCRUB_RULES.breadcrumbCategoriesDropped as readonly string[]).includes(
      breadcrumb.category ?? '',
    )
  ) {
    return null
  }
  const out: Breadcrumb = { ...breadcrumb }
  if (typeof out.message === 'string') {
    out.message = scrubString(out.message)
  }
  const data = scrubBreadcrumbData(out.data)
  if (data === undefined) {
    delete out.data
  } else {
    out.data = data
  }
  return out
}

/** True for the failures the plan calls normal flows (B2 noise list). */
export function isNoise(hint: EventHint | undefined): boolean {
  const original = hint?.originalException
  if (original instanceof ApiError) {
    if (original.code === 'http.invalid_response_body') {
      return false
    }
    return original.status < 500
  }
  if (
    original !== null &&
    typeof original === 'object' &&
    'name' in original &&
    (original.name === 'AbortError' || original.name === 'CanceledError')
  ) {
    return true
  }
  return false
}

function scrubRequest(request: unknown): Record<string, unknown> | undefined {
  if (request === null || typeof request !== 'object') {
    return undefined
  }
  const kept: Record<string, unknown> = {}
  for (const key of SCRUB_RULES.requestKeep) {
    const value = (request as Record<string, unknown>)[key]
    if (typeof value === 'string') {
      kept[key] = scrubString(stripQueryAndFragment(value))
    }
  }
  return kept
}

function scrubUser(user: unknown): { id: string } | undefined {
  if (user === null || typeof user !== 'object') {
    return undefined
  }
  const id = (user as Record<string, unknown>).id
  return typeof id === 'string' && USER_ID.test(id) ? { id } : undefined
}

/** `beforeSend`: noise filter, then the scrub. Returns null to drop the event. */
export function scrubEvent(event: ErrorEvent, hint?: EventHint): ErrorEvent | null {
  try {
    if (isNoise(hint)) {
      return null
    }
    const { request, user, breadcrumbs, ...rest } = event
    const out = scrubObject(rest as unknown as Record<string, unknown>) as unknown as ErrorEvent

    const scrubbedRequest = scrubRequest(request)
    if (scrubbedRequest !== undefined) {
      out.request = scrubbedRequest
    }
    const scrubbedUser = scrubUser(user)
    if (scrubbedUser !== undefined) {
      out.user = scrubbedUser
    }
    if (Array.isArray(breadcrumbs)) {
      out.breadcrumbs = breadcrumbs
        .map((crumb) => scrubBreadcrumb(crumb))
        .filter((crumb): crumb is Breadcrumb => crumb !== null)
    }
    return out
  } catch {
    // Fail closed: an event that could not be scrubbed is never sent raw.
    return null
  }
}
