/**
 * AH-121 C2: Sentry client wiring shared by both browser SPAs.
 *
 * MIRRORED BYTE-FOR-BYTE between `apps/main` and `apps/admin` (parity-tested, see
 * `sentryScrubber.ts`). Everything that differs per SPA (DSN variable, surface name,
 * auth store) lives in that SPA's `initSentry.ts`. No `@/` imports here.
 *
 * Errors only (sentry-plan.md B2): no replay, no tracing, no feedback widget,
 * `sendDefaultPii` false, console and DOM breadcrumbs off, Vue component props not
 * attached. An empty DSN means `Sentry.init` is never called, so no client exists and
 * nothing is sent.
 */

import * as Sentry from '@sentry/vue'
import type { BrowserOptions } from '@sentry/vue'
import { watch, type App } from 'vue'
import type { Router } from 'vue-router'

import { scrubBreadcrumb, scrubEvent } from './sentryScrubber'

export type SentryUserType = 'creator' | 'agency_user' | 'brand_user' | 'platform_admin'

export interface SentryUserState {
  /** `users.ulid`, the id the auth store holds. */
  readonly id: string | null
  readonly type: SentryUserType | null
}

export interface SentryStartOptions {
  readonly app: App
  readonly router: Router
  readonly dsn: string | undefined
  /** Empty string means "no release" (the build warned already). */
  readonly release: string
  readonly environment: string
  /** Read reactively; a change sets or clears the Sentry user. */
  readonly user: () => SentryUserState
  /** Test seam only (a recording transport). Production passes nothing. */
  readonly overrides?: Partial<BrowserOptions>
}

/**
 * The ONLY integrations a client may run. The SDK's defaults are filtered through this
 * list, so a default added by a future SDK version is dropped, not silently enabled.
 * Left out on purpose: `CultureContext` (timezone is coarse location, the same reason
 * the org rule removes geo) and `BrowserSession` (session tracking is ruled off).
 * Pinned by `sentry-source-inspection.spec.ts` and by a runtime check.
 */
export const ALLOWED_INTEGRATIONS = [
  'InboundFilters',
  'FunctionToString',
  'ConversationId',
  'BrowserApiErrors',
  'Breadcrumbs',
  'GlobalHandlers',
  'LinkedErrors',
  'Dedupe',
  'HttpContext',
  'Vue',
] as const

/** `user_type` tag values (B1/B2): a role class, never personal data. */
const USER_TYPE_TAG: Record<SentryUserType, string | undefined> = {
  creator: 'creator',
  agency_user: 'agency',
  // Phase 2: the API sets no tag for a brand user either (SentryUserContext::tagFor).
  brand_user: undefined,
  platform_admin: 'admin',
}

const CHUNK_LOAD_PATTERN =
  /Failed to fetch dynamically imported module|error loading dynamically imported module|Importing a module script failed|ChunkLoadError|Loading chunk .* failed/i

/** Every deploy produces some stale-chunk failures: send a few per page load, not a flood. */
const CHUNK_LOAD_MAX_PER_PAGE = 3

/**
 * The account the scope last belonged to. `undefined` until the first user is applied; `null`
 * is signed out. Module state on purpose: the SDK scope is process-global too.
 */
let lastAccountId: string | null | undefined

/**
 * Breadcrumbs must not cross an account change: an agency user's navigation and API paths must
 * not ride on the next creator's event in the same tab. Clear them when the user id changes or
 * becomes null. On the phone the scope sync forwards the clear to the native layer.
 */
export function clearBreadcrumbsOnAccountChange(id: string | null): void {
  if (lastAccountId !== undefined && lastAccountId !== id) {
    Sentry.getGlobalScope().clearBreadcrumbs()
    Sentry.getIsolationScope().clearBreadcrumbs()
    Sentry.getCurrentScope().clearBreadcrumbs()
  }
  lastAccountId = id
}

/** Set or clear the Sentry user. The scrubber enforces `{ id }` only on every event too. */
export function applySentryUser(state: SentryUserState): void {
  clearBreadcrumbsOnAccountChange(state.id)
  if (state.id === null) {
    Sentry.setUser(null)
    Sentry.setTag('user_type', undefined)
    return
  }
  Sentry.setUser({ id: state.id })
  Sentry.setTag('user_type', state.type === null ? undefined : USER_TYPE_TAG[state.type])
}

/** Returns true when Sentry was started, false when the DSN is empty. */
export function startSentry(options: SentryStartOptions): boolean {
  const dsn = (options.dsn ?? '').trim()
  if (dsn === '') {
    return false
  }

  lastAccountId = undefined
  Sentry.init({
    app: options.app,
    dsn,
    release: options.release === '' ? undefined : options.release,
    environment: options.environment,
    sendDefaultPii: false,
    attachProps: false,
    integrations: (defaults) => [
      ...defaults.filter(
        (integration) =>
          (ALLOWED_INTEGRATIONS as readonly string[]).includes(integration.name) &&
          integration.name !== 'Breadcrumbs' &&
          integration.name !== 'GlobalHandlers',
      ),
      Sentry.breadcrumbsIntegration({
        console: false,
        dom: false,
        fetch: true,
        history: true,
        xhr: true,
        sentry: true,
      }),
      Sentry.globalHandlersIntegration({ onerror: true, onunhandledrejection: true }),
    ],
    ignoreErrors: [/ResizeObserver loop/],
    denyUrls: [/^chrome-extension:\/\//i, /^moz-extension:\/\//i, /^safari-(web-)?extension:\/\//i],
    beforeSend: (event, hint) => scrubEvent(event, hint),
    beforeBreadcrumb: (breadcrumb) => scrubBreadcrumb(breadcrumb),
    ...options.overrides,
  })

  let chunkLoadSent = 0
  options.router.onError((error) => {
    const message = error instanceof Error ? error.message : String(error)
    const isChunkLoad = CHUNK_LOAD_PATTERN.test(message)
    if (isChunkLoad) {
      if (chunkLoadSent >= CHUNK_LOAD_MAX_PER_PAGE) {
        return
      }
      chunkLoadSent += 1
    }
    Sentry.withScope((scope) => {
      if (isChunkLoad) {
        scope.setTag('chunk_load', 'true')
      }
      Sentry.captureException(error)
    })
  })

  watch(
    () => {
      const state = options.user()
      return [state.id, state.type] as const
    },
    () => applySentryUser(options.user()),
    { immediate: true },
  )

  return true
}
