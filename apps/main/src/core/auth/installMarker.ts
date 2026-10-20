/**
 * AH-103d — the reinstall marker (tech-debt 17).
 *
 * iOS keeps Keychain items across "Delete App", and the creator's bearer token
 * lives in the Keychain, so a reinstalled, handed-on or sold phone used to come
 * back signed in as the previous creator, with their push-token row still live
 * on the server. WebView `localStorage` is deleted with the app, so a marker
 * kept there is present exactly when THIS install has run before:
 *
 *   marker absent + a session present  → this is a reinstall (or the one-time
 *                                         upgrade from a pre-marker build): sign
 *                                         out through the real sign-out path, then
 *                                         write the marker;
 *   marker absent + no session         → a fresh install: write the marker, make
 *                                         no request;
 *   marker present                     → nothing runs, not even a token read.
 *
 * Why `localStorage` and not a native preference: losing the marker fails CLOSED
 * (the user signs in once more); it can never keep anyone signed in. Nothing here
 * is a secret and nothing is sent anywhere.
 *
 * The decision is pure ({@link reconcileInstall}, injected storage, wipe and
 * timer); {@link ensureInstallReconciled} memoises it to ONE run per app-open so
 * the eager start in `main.ts` and the two gates in the auth store share it.
 */
import type { TokenStore } from '@/core/auth/tokenStore'

export const INSTALL_MARKER_KEY = 'catalyst.install.marker'

/**
 * How long the sign-out may hold the first launch. `authApi.logout()` has no
 * timeout of its own, so on a blackholed network it could hang until the
 * platform's fetch timeout. When the bound wins the token is cleared LOCALLY at
 * once (the safety property), and the slow revoke finishes on its own.
 */
export const INSTALL_WIPE_BOUND_MS = 5000

export type MarkerRead = { ok: true; value: string | null } | { ok: false }

export interface MarkerStorage {
  /** `{ ok: false }` means the read itself failed: unknown, NOT "absent". */
  read(): MarkerRead
  /** True when the marker was persisted. */
  write(value: string): boolean
}

export type ReconcileOutcome =
  /** Not the creator app: the marker is never touched. */
  | 'skipped-web'
  /** Marker present: nothing ran. */
  | 'present'
  /** The marker could not be read: fail open, nothing written, nothing wiped. */
  | 'read-error'
  /** No session: marker written, no request. */
  | 'marked'
  /** A session was signed out through the real sign-out path; marker written. */
  | 'wiped'
  /** The sign-out failed or hit the bound: the token was cleared locally; marker written. */
  | 'wiped-local'

export interface ReconcileDeps {
  isNative: boolean
  marker: MarkerStorage
  /** The un-gated token store: the read here decides, the clear is the local fallback. */
  tokens: Pick<TokenStore, 'read' | 'clear'> & Partial<Pick<TokenStore, 'hasLapsed'>>
  /** The real sign-out (the auth store's `logout()`, which runs `nativeLogout()` on native). */
  wipe: () => Promise<void>
  now?: () => Date
  boundMs?: number
}

export async function reconcileInstall(deps: ReconcileDeps): Promise<ReconcileOutcome> {
  if (!deps.isNative) {
    return 'skipped-web'
  }

  const current = deps.marker.read()
  if (!current.ok) {
    return 'read-error'
  }
  if (current.value !== null) {
    return 'present'
  }

  let token: string | null = null
  try {
    token = await deps.tokens.read()
  } catch {
    token = null
  }

  let outcome: ReconcileOutcome
  if (token === null) {
    // A fresh install, an unreadable Keychain item, or a token past its expiry
    // (read() already discarded it and flagged "lapsed"). A brand-new install
    // must not greet the user with "your session expired", so reset the flag.
    if (deps.tokens.hasLapsed?.() === true) {
      await deps.tokens.clear().catch(() => undefined)
    }
    outcome = 'marked'
  } else {
    outcome = await wipeBounded(deps)
  }

  // After the local clear in every branch: a crash mid-wipe retries next launch.
  deps.marker.write((deps.now ?? (() => new Date()))().toISOString())
  return outcome
}

async function wipeBounded(deps: ReconcileDeps): Promise<ReconcileOutcome> {
  let timer: ReturnType<typeof setTimeout> | undefined
  const bound = new Promise<'bound'>((resolve) => {
    timer = setTimeout(() => resolve('bound'), deps.boundMs ?? INSTALL_WIPE_BOUND_MS)
  })
  const run = deps.wipe().then(
    () => 'done' as const,
    () => 'failed' as const,
  )
  const result = await Promise.race([run, bound])
  clearTimeout(timer)

  if (result === 'done') {
    return 'wiped'
  }
  // The sign-out failed or is still waiting on the network. The previous owner's
  // session must not survive either way: clearing sets the in-memory cache to
  // null synchronously, so every later read is already null.
  await deps.tokens.clear().catch(() => undefined)
  return 'wiped-local'
}

let reconciled: Promise<ReconcileOutcome> | null = null

/**
 * One reconcile per app-open. Never rejects: the gates in the auth store await
 * it, and a failure here must never stop the app from booting.
 */
export function ensureInstallReconciled(makeDeps: () => ReconcileDeps): Promise<ReconcileOutcome> {
  reconciled ??= (async () => {
    try {
      return await reconcileInstall(makeDeps())
    } catch {
      return 'read-error' as const
    }
  })()
  return reconciled
}

/** Test seam: forget the memoised run. */
export function resetInstallReconcileForTests(): void {
  reconciled = null
}

/** The WebView `localStorage` adapter. A throwing store is "unknown", never "absent". */
export const localStorageMarker: MarkerStorage = {
  read() {
    try {
      return { ok: true, value: window.localStorage.getItem(INSTALL_MARKER_KEY) }
    } catch {
      return { ok: false }
    }
  },
  write(value) {
    try {
      window.localStorage.setItem(INSTALL_MARKER_KEY, value)
      return true
    } catch {
      return false
    }
  },
}
