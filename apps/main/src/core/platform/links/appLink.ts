/**
 * AH-103b — what an `https://platform.catalyst-growth.com/creator/…` link
 * handed to the app by the OS is allowed to do.
 *
 * The OS hands the app a URL; the URL is untrusted input. Two pure functions
 * turn it into one of three outcomes, and nothing else ever reaches the
 * router:
 *
 *   - `route`     — a real creator screen: push its `fullPath`.
 *   - `dashboard` — the link is IN SCOPE (ours: HTTPS, our host, under
 *                   `/creator/`) but unusable: an unknown route, or a path
 *                   with anything in it a creator route never carries. Open
 *                   the creator dashboard (D2, as cleared 2026-10-19).
 *   - `ignore`    — OUT OF SCOPE: another scheme, another host or a
 *                   look-alike, credentials, a port, or a path that is not
 *                   under `/creator/` once the URL parser has normalised it.
 *                   The OS should not have delivered it; do nothing.
 *
 * The raw URL, and any path that failed the allow-list below, is never given
 * to the router: the dashboard fallback is a fixed route NAME.
 *
 * The host is a constant, not an env var (D1): a dev build never receives a
 * production link, and a constant is one spec pin (`appLinkFiles.spec.ts`
 * keeps it equal to the iOS entitlement and the Android manifest filter)
 * instead of a setting that can drift from them.
 */

export const APP_LINK_HOST = 'platform.catalyst-growth.com'

/** Scope (R2): creator paths only. `/auth/*` and every agency path stay web. */
export const APP_LINK_PATH_PREFIX = '/creator/'

/** The in-scope-but-unusable fallback (D2). A route NAME, never a path. */
export const APP_LINK_DASHBOARD_ROUTE = 'creator.dashboard'

const MAX_URL_LENGTH = 2048
const MAX_PATH_LENGTH = 256

/**
 * Every creator route is slash-separated segments of ULID or slug characters.
 * An allow-list rather than a deny-list: percent-encoding (`%2f`, `%2e`, `%5c`,
 * `%00`, a double-encoded `%25…`), empty segments, unicode and anything else
 * fail it, whatever form they take.
 */
const SAFE_PATH = /^\/creator\/[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*\/?$/

/**
 * The query keys a creator route reads, with what each may carry. Today that
 * is `focus=messages` on the assignment detail; `?tab=` is agency-only, and
 * `redirect` is for the sign-in flow, never for a link.
 */
const QUERY_ALLOW_LIST: Readonly<Record<string, (value: string) => boolean>> = {
  focus: (value) => value === 'messages',
}

export type AppLinkParse =
  | { kind: 'out-of-scope' }
  | {
      kind: 'in-scope'
      /** The normalised path, or `null` when it failed the allow-list. */
      path: string | null
      /** Allow-listed query keys only (empty when `path` is `null`). */
      query: Record<string, string>
    }

const OUT_OF_SCOPE: AppLinkParse = { kind: 'out-of-scope' }

/** Classify a URL. Never throws. */
export function parseAppLink(rawUrl: unknown): AppLinkParse {
  if (typeof rawUrl !== 'string' || rawUrl.length === 0 || rawUrl.length > MAX_URL_LENGTH) {
    return OUT_OF_SCOPE
  }

  let url: URL
  try {
    url = new URL(rawUrl)
  } catch {
    return OUT_OF_SCOPE
  }

  // Exact comparison. Not `endsWith`, not `includes`: a look-alike such as
  // `platform.catalyst-growth.com.evil.test` or `evilplatform.catalyst-growth.com`
  // is another site.
  if (
    url.protocol !== 'https:' ||
    url.hostname.toLowerCase() !== APP_LINK_HOST ||
    url.username !== '' ||
    url.password !== '' ||
    url.port !== ''
  ) {
    return OUT_OF_SCOPE
  }

  // `pathname` is already normalised by the URL parser: `/creator/../auth/x`
  // and `/creator/%2e%2e/auth/x` both arrive here as `/auth/x`.
  if (!url.pathname.startsWith(APP_LINK_PATH_PREFIX)) {
    return OUT_OF_SCOPE
  }

  if (url.pathname.length > MAX_PATH_LENGTH || !SAFE_PATH.test(url.pathname)) {
    return { kind: 'in-scope', path: null, query: {} }
  }

  const query: Record<string, string> = {}
  for (const [key, accepts] of Object.entries(QUERY_ALLOW_LIST)) {
    const value = url.searchParams.get(key) // the first of a repeated key
    if (value !== null && accepts(value)) {
      query[key] = value
    }
  }

  return { kind: 'in-scope', path: url.pathname, query }
}

/** What `router.resolve` returns, as far as the policy reads it. */
export interface ResolvedLink {
  name?: string | symbol | null
  matched: readonly unknown[]
  fullPath: string
}

/** The one router method the policy needs (structural: only `main.ts` touches the singleton). */
export interface LinkResolver {
  resolve(to: { path: string; query: Record<string, string> }): ResolvedLink
}

export type AppLinkResolution =
  | { kind: 'route'; fullPath: string }
  | { kind: 'dashboard' }
  | { kind: 'ignore' }

const DASHBOARD: AppLinkResolution = { kind: 'dashboard' }
const IGNORE: AppLinkResolution = { kind: 'ignore' }

/**
 * Resolve a link against the router's own route table. A path is accepted
 * only when it matches a route AND that route is a creator screen
 * (`creator.*`): the catch-all and every other module's routes fall through
 * to the dashboard. Never throws.
 */
export function resolveAppLink(router: LinkResolver, rawUrl: unknown): AppLinkResolution {
  const parsed = parseAppLink(rawUrl)

  if (parsed.kind === 'out-of-scope') {
    return IGNORE
  }

  if (parsed.path === null) {
    return DASHBOARD
  }

  try {
    const resolved = router.resolve({ path: parsed.path, query: parsed.query })

    if (
      resolved.matched.length > 0 &&
      typeof resolved.name === 'string' &&
      resolved.name.startsWith('creator.')
    ) {
      return { kind: 'route', fullPath: resolved.fullPath }
    }
  } catch {
    // A router that cannot resolve is an unusable link, not a crash.
  }

  return DASHBOARD
}
