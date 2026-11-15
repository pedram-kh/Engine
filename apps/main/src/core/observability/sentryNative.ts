/**
 * AH-121 C3: Sentry inside the Capacitor shell (iOS and Android), main SPA only.
 *
 * Loaded by dynamic import from `initSentry` and only when `isNativePlatform()` is true,
 * so the web bundle never carries `@sentry/capacitor`. Same rules as the web client
 * (errors only, `sendDefaultPii` false, console and DOM breadcrumbs off, the same
 * scrubber and the same integration allow-list plus the plugin's own few), with these
 * native differences, each pinned by a test or proved on the simulator (sentry-plan.md D8):
 *
 *  - Session tracking, app-hang, watchdog and failed-request capture are
 *    switched off through the plugin's options (the plugin enables the first two by default).
 *  - The release is `<bundle id>@<version>+<build>` with `dist` = the build number, read
 *    from the app bundle at runtime (`App.getInfo()`), so it cannot drift from the binary.
 *    It is passed explicitly because the browser SDK otherwise defaults `release` to the
 *    build-time `__SENTRY_RELEASE__` constant, which is the web release (`catalyst-main@...`)
 *    and would override the native one on every JS event (seen on the simulator).
 *  - The user and tags are applied only once the JS client exists, i.e. after the native
 *    SDK has started (a scope change made before that is lost on the native side).
 *  - Errors thrown before the client exists are replayed by `earlyErrors.ts`.
 */

import { App as CapacitorApp } from '@capacitor/app'
import * as SentryCapacitor from '@sentry/capacitor'
import * as SentryVue from '@sentry/vue'
import { watch, type App } from 'vue'
import type { Router } from 'vue-router'

import { drainEarlyErrors } from './earlyErrors'
import {
  ALLOWED_INTEGRATIONS,
  applySentryUser,
  clearBreadcrumbsOnAccountChange,
  type SentryUserState,
} from './sentryClient'
import { scrubBreadcrumb, scrubEvent } from './sentryScrubber'

/**
 * What the `@sentry/capacitor` defaults add on top of the web allow-list. Anything else
 * the plugin adds in a later version is dropped, not silently enabled.
 */
export const NATIVE_ALLOWED_INTEGRATIONS = [
  'RewriteFrames',
  'Release',
  'EventOrigin',
  'SdkInfo',
  'DeviceContext',
] as const

/**
 * The native crash handler fills an install UUID into any report whose scope has no
 * user (proved on the simulator, sentry-plan.md D8), so signed out is a fixed string.
 * Never a per-device value.
 */
export const SIGNED_OUT_USER_ID = 'signed-out'

/** Signed in: `{ id: ulid }` and the `user_type` tag. Signed out: the fixed string. */
export function applyNativeUser(state: SentryUserState): void {
  if (state.id === null) {
    clearBreadcrumbsOnAccountChange(null)
    SentryCapacitor.setUser({ id: SIGNED_OUT_USER_ID })
    SentryCapacitor.setTag('user_type', undefined)
    return
  }
  applySentryUser(state)
}

export interface NativeStartOptions {
  readonly app: App
  readonly router: Router
  readonly dsn: string | undefined
  readonly environment: string
  readonly user: () => SentryUserState
  /** Test seam only (a recording transport and the like). Production passes nothing. */
  readonly overrides?: Record<string, unknown>
}

const CLIENT_WAIT_MS = 10_000
const CLIENT_POLL_MS = 25

function waitForClient(): Promise<boolean> {
  return new Promise((resolve) => {
    const startedAt = Date.now()
    const poll = (): void => {
      if (SentryCapacitor.getClient() !== undefined) {
        resolve(true)
      } else if (Date.now() - startedAt > CLIENT_WAIT_MS) {
        resolve(false)
      } else {
        setTimeout(poll, CLIENT_POLL_MS)
      }
    }
    poll()
  })
}

export interface NativeRelease {
  readonly release: string
  readonly dist: string
}

/** `com.catalystgrowth.platform@1.0.0+6` and `6`, from the installed bundle. */
export function nativeReleaseFrom(info: {
  readonly id: string
  readonly version: string
  readonly build: string
}): NativeRelease {
  return { release: `${info.id}@${info.version}+${info.build}`, dist: info.build }
}

async function readNativeRelease(): Promise<NativeRelease | null> {
  try {
    return nativeReleaseFrom(await CapacitorApp.getInfo())
  } catch {
    return null
  }
}

/** Resolves true once Sentry is running (the user is applied), false when it never started. */
export async function startNativeSentry(options: NativeStartOptions): Promise<boolean> {
  const dsn = (options.dsn ?? '').trim()
  if (dsn === '') {
    return false
  }

  const bundle = await readNativeRelease()
  const allowed: readonly string[] = [...ALLOWED_INTEGRATIONS, ...NATIVE_ALLOWED_INTEGRATIONS]

  SentryCapacitor.init(
    {
      dsn,
      environment: options.environment,
      ...(bundle === null ? {} : { release: bundle.release, dist: bundle.dist }),
      sendDefaultPii: false,
      enableAutoSessionTracking: false,
      enableWatchdogTerminationTracking: false,
      enableAppHangTracking: false,
      enableCaptureFailedRequests: false,
      integrations: (defaults) => [
        ...defaults.filter(
          (integration) =>
            allowed.includes(integration.name) &&
            integration.name !== 'Breadcrumbs' &&
            integration.name !== 'GlobalHandlers' &&
            integration.name !== 'Vue',
        ),
        SentryVue.breadcrumbsIntegration({
          console: false,
          dom: false,
          fetch: true,
          history: true,
          xhr: true,
          sentry: true,
        }),
        SentryVue.globalHandlersIntegration({ onerror: true, onunhandledrejection: true }),
        SentryVue.vueIntegration({ app: options.app, attachProps: false }),
      ],
      ignoreErrors: [/ResizeObserver loop/],
      beforeSend: (event, hint) => scrubEvent(event, hint),
      beforeBreadcrumb: (breadcrumb) => scrubBreadcrumb(breadcrumb),
      ...options.overrides,
    },
    SentryVue.init,
  )

  if (import.meta.env.VITE_SENTRY_TEST_EVENT === 'early') {
    // Test build only. Thrown now, with no client yet: proves the early buffer.
    const { fireEarlyTestError } = await import('./sentryTestEvent')
    fireEarlyTestError()
  }

  if (!(await waitForClient())) {
    drainEarlyErrors(() => undefined)
    return false
  }

  options.router.onError((error) => {
    SentryCapacitor.captureException(error)
  })

  watch(
    () => {
      const state = options.user()
      return [state.id, state.type] as const
    },
    () => applyNativeUser(options.user()),
    { immediate: true },
  )

  // After the user is applied, so replayed errors carry it.
  drainEarlyErrors((error) => {
    SentryCapacitor.captureException(error)
  })

  if (import.meta.env.VITE_SENTRY_TEST_EVENT) {
    // Test build only (never submitted to review). Tree-shaken when the flag is unset.
    const { runTestEvent } = await import('./sentryTestEvent')
    void runTestEvent(String(import.meta.env.VITE_SENTRY_TEST_EVENT), options.user)
  }

  return true
}
