/**
 * AH-121 C3: a capped queue for errors thrown while the native Sentry client starts.
 *
 * `@sentry/capacitor` starts the native SDK first and creates the JS client only when
 * that bridge call returns, so for a short window after `init()` there is no client and
 * anything thrown is dropped (the C0 spike lost its startup error this way). This
 * buffer listens from the first line of `initSentry`, keeps at most MAX errors, and
 * hands them to the client once it exists. It never touches Sentry itself.
 */

import type { App } from 'vue'

const MAX_EARLY_ERRORS = 10

let queue: unknown[] = []
let buffering = false
let detach: (() => void) | null = null

function push(error: unknown): void {
  if (buffering && queue.length < MAX_EARLY_ERRORS) {
    queue.push(error)
  }
}

/** Start buffering `window` errors, unhandled rejections and Vue render errors. */
export function startEarlyErrorBuffer(app: App): void {
  if (buffering) {
    return
  }
  buffering = true
  queue = []

  const onError = (event: ErrorEvent): void => {
    push(event.error ?? new Error(event.message))
  }
  const onRejection = (event: PromiseRejectionEvent): void => {
    push(event.reason)
  }
  window.addEventListener('error', onError)
  window.addEventListener('unhandledrejection', onRejection)

  // The Vue integration wraps whatever handler is installed when the client starts and
  // still calls it, so this one stays harmless after `drainEarlyErrors` (it stops queueing).
  const previous = app.config.errorHandler
  app.config.errorHandler = (error, instance, info) => {
    push(error)
    previous?.(error, instance, info)
  }

  detach = () => {
    window.removeEventListener('error', onError)
    window.removeEventListener('unhandledrejection', onRejection)
  }
}

/** Stop buffering and hand every queued error to `capture`, oldest first. */
export function drainEarlyErrors(capture: (error: unknown) => void): number {
  buffering = false
  detach?.()
  detach = null
  const pending = queue
  queue = []
  for (const error of pending) {
    capture(error)
  }
  return pending.length
}

/** Test seam: forget everything. */
export function resetEarlyErrors(): void {
  buffering = false
  detach?.()
  detach = null
  queue = []
}
