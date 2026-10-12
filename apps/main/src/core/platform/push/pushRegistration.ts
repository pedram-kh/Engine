import { ref, type Ref } from 'vue'

import type { NativePlatform } from '../isNativePlatform'
import type { DevicePermission, PushChannelSpec, PushClient } from './pushClient'
import type { PushTokensApi } from './pushTokens.api'

/**
 * AH-102 (C4.1) — the creator app's push registration: consent, token upkeep,
 * and the logout cleanup. Pure logic over injected seams (the Capacitor client,
 * the HTTP calls, storage, the clock), so every branch is specced without a
 * device; the bridge itself is exercised on the Android walk (C4.3).
 *
 * The rules, each load-bearing:
 *
 *  - **Consent first.** No token is minted and no OS prompt shown until the
 *    creator says yes to OUR sheet (the soft-ask). Auto-init is off natively.
 *  - **Ask rarely.** A declined soft-ask is not shown again for
 *    {@link ASK_COOLDOWN_MS}. An OS-level deny is never re-prompted — the
 *    settings row explains instead.
 *  - **Never while signed out.** The token endpoint is bearer-only; calling it
 *    anonymously would trip the session-expired policy for nothing.
 *  - **Upkeep is cheap.** A granted device re-POSTs at most once a day (it
 *    refreshes `last_seen_at`, which is what keeps the token off the stale list).
 *  - **Logout never blocks.** {@link PushRegistration.logoutCleanup} is
 *    best-effort under a short timeout and cannot throw.
 */

/** A declined soft-ask is not shown again for 30 days (P-2). */
export const ASK_COOLDOWN_MS = 30 * 24 * 60 * 60 * 1000

/** A granted device re-registers at most this often. */
export const UPKEEP_INTERVAL_MS = 24 * 60 * 60 * 1000

/** Sign-out waits this long, at most, for the push cleanup. */
export const LOGOUT_CLEANUP_TIMEOUT_MS = 3000

export const STORAGE_KEYS = {
  /** When the creator last declined (or answered) the soft-ask — epoch ms. */
  askedAt: 'catalyst.push.askedAt',
  /** When the current token was last registered with the API — epoch ms. */
  registeredAt: 'catalyst.push.registeredAt',
  /** The token last registered — so a rotation and a repeat are told apart. */
  token: 'catalyst.push.token',
} as const

/**
 * `unsupported` — push cannot work on this build (web, or iOS before AH-102-ios).
 * `unknown` — supported, not yet read from the OS.
 */
export type PushPermission = 'unsupported' | 'unknown' | DevicePermission

export interface PushStorage {
  get(key: string): string | null
  set(key: string, value: string): void
  remove(key: string): void
}

export interface PushRegistrationDeps {
  client: PushClient
  tokens: PushTokensApi
  storage: PushStorage
  /** `pushChannelAvailable()` — native AND Android-or-flag. */
  isAvailable: () => boolean
  platform: () => NativePlatform | null
  /** True only with a live session. */
  isSignedIn: () => boolean | Promise<boolean>
  /** The localised channel spec, read at call time so it follows the locale. */
  channel: () => PushChannelSpec
  appVersion?: () => Promise<string | undefined>
  now?: () => number
}

export interface PushHandlers {
  /** A push arrived while the app is in the foreground. */
  onForeground: () => void
  /**
   * The user tapped a delivered notification. `data` is the notification's `data`
   * object, passed through UNCHANGED and untrusted (AH-103) — the tap handler parses it.
   */
  onTap: (data: unknown) => void
}

export interface PushRegistration {
  /** Reactive: components bind to it for the sheet and the settings row. */
  permission: Ref<PushPermission>
  refreshPermission(): Promise<PushPermission>
  /** Should the soft-ask sheet show now? Reads the OS state and the cool-down. */
  isSoftAskDue(): Promise<boolean>
  /** The creator said yes: OS prompt → token → POST. Resolves to the OS answer. */
  accept(): Promise<PushPermission>
  /** The creator said not now: start the cool-down. */
  decline(): void
  /** Register if (and only if) permission is already granted and a day has passed. */
  syncIfGranted(): Promise<void>
  /**
   * A session just appeared: register if permission is granted, WITHOUT trusting the
   * stored `registeredAt`. That value says "this device registered", but a revoked
   * or lapsed login deletes the server row (it is tied to the login's token) and
   * nothing on the device learns of it, so across a session boundary it proves
   * nothing. The server upsert is idempotent, so a repeat POST is harmless.
   */
  registerAfterSignIn(): Promise<void>
  /** Subscribe to rotation / foreground / tap. Returns a stop that is always callable. */
  start(handlers: PushHandlers): () => void
  /** Sign-out: forget the token server-side and at FCM. Best-effort; never throws. */
  logoutCleanup(timeoutMs?: number): Promise<void>
}

export function createPushRegistration(deps: PushRegistrationDeps): PushRegistration {
  const { client, tokens, storage, isAvailable, platform, isSignedIn, channel } = deps
  const now = deps.now ?? Date.now

  const permission = ref<PushPermission>(isAvailable() ? 'unknown' : 'unsupported')

  function readNumber(key: string): number | null {
    const raw = storage.get(key)
    if (raw === null) {
      return null
    }
    const value = Number(raw)
    return Number.isFinite(value) ? value : null
  }

  async function refreshPermission(): Promise<PushPermission> {
    if (!isAvailable()) {
      permission.value = 'unsupported'
      return permission.value
    }
    try {
      permission.value = await client.checkPermission()
    } catch {
      // Leave the last known state: a failed read must not flip the UI.
    }
    return permission.value
  }

  /** Mint the token and tell the API. Resolves true when the API has it. */
  async function register(knownToken?: string): Promise<boolean> {
    const os = platform()
    if (!isAvailable() || os === null || !(await isSignedIn())) {
      return false
    }
    try {
      // Re-asserting the channel each time keeps its OS-visible name in step with
      // the current locale (the id, and the importance, never change). Android
      // only: the plugin answers `createChannel` on iOS with "Not implemented",
      // which would land in the catch below and silently end registration before
      // the token is ever minted (found on the first iPhone run).
      if (os === 'android') {
        await client.createChannel(channel())
      }
      const token = knownToken ?? (await client.getToken())
      if (token === '') {
        return false
      }
      const version = deps.appVersion === undefined ? undefined : await deps.appVersion()
      await tokens.register(token, os, version)
      storage.set(STORAGE_KEYS.token, token)
      storage.set(STORAGE_KEYS.registeredAt, String(now()))
      return true
    } catch {
      // Not registered: `registeredAt` stays stale, so the next sync retries.
      return false
    }
  }

  async function isSoftAskDue(): Promise<boolean> {
    if ((await refreshPermission()) !== 'prompt') {
      return false
    }
    const askedAt = readNumber(STORAGE_KEYS.askedAt)
    return askedAt === null || now() - askedAt >= ASK_COOLDOWN_MS
  }

  async function accept(): Promise<PushPermission> {
    if (!isAvailable()) {
      return 'unsupported'
    }
    // Answering at all starts the cool-down: an OS deny is final, and a grant
    // never reaches the sheet again because the permission is no longer `prompt`.
    storage.set(STORAGE_KEYS.askedAt, String(now()))
    try {
      permission.value = await client.requestPermission()
    } catch {
      return refreshPermission()
    }
    if (permission.value === 'granted') {
      await register()
    }
    return permission.value
  }

  function decline(): void {
    storage.set(STORAGE_KEYS.askedAt, String(now()))
  }

  async function syncIfGranted(): Promise<void> {
    if ((await refreshPermission()) !== 'granted') {
      return
    }
    const registeredAt = readNumber(STORAGE_KEYS.registeredAt)
    if (registeredAt !== null && now() - registeredAt < UPKEEP_INTERVAL_MS) {
      return
    }
    await register()
  }

  async function registerAfterSignIn(): Promise<void> {
    if ((await refreshPermission()) !== 'granted') {
      return
    }
    await register()
  }

  async function onTokenRotated(token: string): Promise<void> {
    const registeredAt = readNumber(STORAGE_KEYS.registeredAt)
    // `getToken()` fires `tokenReceived` too; the same token inside the upkeep
    // window is a repeat, not a rotation.
    if (
      storage.get(STORAGE_KEYS.token) === token &&
      registeredAt !== null &&
      now() - registeredAt < UPKEEP_INTERVAL_MS
    ) {
      return
    }
    if (permission.value !== 'granted') {
      return
    }
    await register(token)
  }

  function start(handlers: PushHandlers): () => void {
    if (!isAvailable()) {
      return () => undefined
    }

    let stopped = false
    const unsubscribers: Array<() => void> = []

    function hold(subscribed: Promise<() => void>): void {
      subscribed
        .then((off) => {
          if (stopped) {
            off()
            return
          }
          unsubscribers.push(off)
        })
        .catch(() => undefined)
    }

    hold(
      client.onTokenReceived((token) => {
        if (!stopped) {
          void onTokenRotated(token)
        }
      }),
    )
    hold(
      client.onNotificationReceived(() => {
        if (!stopped) {
          handlers.onForeground()
        }
      }),
    )
    hold(
      client.onNotificationTapped((data) => {
        if (!stopped) {
          handlers.onTap(data)
        }
      }),
    )

    void syncIfGranted().catch(() => undefined)

    return () => {
      stopped = true
      for (const off of unsubscribers.splice(0)) {
        off()
      }
    }
  }

  async function cleanup(): Promise<void> {
    if (!isAvailable() || (await refreshPermission()) !== 'granted') {
      return
    }
    // Wipe the local record first: whatever happens next, this session is over,
    // and the next sign-in must register straight away.
    const known = storage.get(STORAGE_KEYS.token)
    storage.remove(STORAGE_KEYS.token)
    storage.remove(STORAGE_KEYS.registeredAt)

    try {
      const token = known ?? (await client.getToken())
      if (token !== '') {
        await tokens.forget(token)
      }
    } catch {
      // The offline case (R-4): the server row lapses with the login.
    }
    try {
      await client.deleteToken()
    } catch {
      // Best-effort, same as above.
    }
  }

  async function logoutCleanup(timeoutMs: number = LOGOUT_CLEANUP_TIMEOUT_MS): Promise<void> {
    let timer: ReturnType<typeof setTimeout> | undefined
    const timeout = new Promise<void>((resolve) => {
      timer = setTimeout(resolve, timeoutMs)
    })
    try {
      await Promise.race([cleanup().catch(() => undefined), timeout])
    } finally {
      clearTimeout(timer)
    }
  }

  return {
    permission,
    refreshPermission,
    isSoftAskDue,
    accept,
    decline,
    syncIfGranted,
    registerAfterSignIn,
    start,
    logoutCleanup,
  }
}
