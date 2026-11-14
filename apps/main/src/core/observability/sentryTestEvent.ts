/**
 * AH-121 C3: the deliberate test event. TEST BUILDS ONLY, NEVER SUBMITTED TO REVIEW.
 *
 * Reached only through `import.meta.env.VITE_SENTRY_TEST_EVENT` (a build-time constant),
 * so a build made without the flag contains neither this file nor its message
 * (`sentry-test-event-absent.spec.ts` builds both ways and checks). Fires at most once
 * per install: the marker is written BEFORE firing and lives in localStorage, which the
 * WKWebView drops on uninstall.
 *
 * Modes (the flag's value):
 *  - `1`      a JS error, once the user is known (or after WAIT_FOR_USER_MS signed out)
 *  - `crash`  a native crash, same timing; the report uploads on the next launch
 *  - `early`  a JS error thrown right after init, before the client exists
 */

import * as SentryCapacitor from '@sentry/capacitor'

import type { SentryUserState } from './sentryClient'

export const TEST_EVENT_MESSAGE = 'AH-121 test event'
export const TEST_EVENT_MARKER = 'ah121.sentry.testEvent'

const WAIT_FOR_USER_MS = 180_000

function alreadyFired(): boolean {
  try {
    if (window.localStorage.getItem(TEST_EVENT_MARKER) !== null) {
      return true
    }
    window.localStorage.setItem(TEST_EVENT_MARKER, new Date().toISOString())
    return false
  } catch {
    return true
  }
}

export function fireEarlyTestError(): void {
  if (alreadyFired()) {
    return
  }
  // Escapes as an uncaught error with no client yet; the early buffer must replay it.
  queueMicrotask(() => {
    throw new Error(`${TEST_EVENT_MESSAGE} (early)`)
  })
}

function waitForUser(user: () => SentryUserState): Promise<void> {
  return new Promise((resolve) => {
    const startedAt = Date.now()
    const poll = (): void => {
      if (user().id !== null || Date.now() - startedAt > WAIT_FOR_USER_MS) {
        // One more tick so the user watch has reached the native scope.
        setTimeout(resolve, 1500)
      } else {
        setTimeout(poll, 250)
      }
    }
    poll()
  })
}

export async function runTestEvent(mode: string, user: () => SentryUserState): Promise<void> {
  if (mode === 'early') {
    return
  }
  await waitForUser(user)
  if (alreadyFired()) {
    return
  }
  if (mode === 'crash') {
    SentryCapacitor.nativeCrash()
    return
  }
  SentryCapacitor.captureException(new Error(TEST_EVENT_MESSAGE))
  await SentryCapacitor.flush(4000)
}
