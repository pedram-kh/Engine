import { isNativePlatform } from '@/core/platform/isNativePlatform'

/**
 * AH-100 D2 — where the creator app keeps its Sanctum personal access
 * token. Written at login, cleared at logout and on a terminal 401.
 *
 * `read()` is async on purpose: AH-103 wraps this interface in a
 * biometric gate (unlock before the token is released), and that
 * decorator only has to implement the same three methods.
 */
export interface TokenStore {
  read(): Promise<string | null>
  write(token: string, expiresAt: string | null): Promise<void>
  clear(): Promise<void>
}

export interface StoredToken {
  token: string
  expiresAt: string | null
}

/** The persistence a {@link TokenStore} sits on — secure storage in the app, memory in tests. */
export interface TokenBackend {
  get(): Promise<StoredToken | null>
  set(value: StoredToken): Promise<void>
  remove(): Promise<void>
}

/**
 * The backend is read once per app-open and then served from memory, so
 * the per-request `getToken()` never touches the Keychain / Keystore
 * after the first call. An unreadable backend (corrupt item, Keystore
 * key lost after a restore) reads as "no token": the user signs in
 * again. A token past its `expiresAt` is cleared and reads as `null`.
 */
export function createTokenStore(backend: TokenBackend, now: () => number = Date.now): TokenStore {
  let cached: Promise<StoredToken | null> | null = null

  function load(): Promise<StoredToken | null> {
    cached ??= backend.get().catch(() => null)
    return cached
  }

  async function clear(): Promise<void> {
    cached = Promise.resolve(null)
    await backend.remove()
  }

  return {
    async read() {
      const stored = await load()
      if (stored === null) {
        return null
      }
      if (stored.expiresAt !== null && Date.parse(stored.expiresAt) <= now()) {
        await clear()
        return null
      }
      return stored.token
    },
    async write(token, expiresAt) {
      const value: StoredToken = { token, expiresAt }
      await backend.set(value)
      cached = Promise.resolve(value)
    },
    clear,
  }
}

const SECURE_STORAGE_KEY = 'catalyst.auth.token'

export function isStoredToken(value: unknown): value is StoredToken {
  if (typeof value !== 'object' || value === null) {
    return false
  }
  const candidate = value as Record<string, unknown>
  return (
    typeof candidate.token === 'string' &&
    (candidate.expiresAt === null || typeof candidate.expiresAt === 'string')
  )
}

/* c8 ignore start -- @preserve: thin adapter over the native plugin; exercised on device, the store logic above is unit-tested against a memory backend. */
/**
 * iOS Keychain (`whenUnlockedThisDeviceOnly`, never iCloud-synced) /
 * Android Keystore-backed storage. Imported dynamically so the plugin
 * never enters the web bundle's eager graph.
 */
export function createSecureStorageBackend(): TokenBackend {
  const plugin = () => import('@aparajita/capacitor-secure-storage')
  return {
    async get() {
      const { SecureStorage } = await plugin()
      const raw = await SecureStorage.get(SECURE_STORAGE_KEY, false, false)
      return isStoredToken(raw) ? { token: raw.token, expiresAt: raw.expiresAt } : null
    },
    async set(value) {
      const { SecureStorage, KeychainAccess } = await plugin()
      await SecureStorage.set(
        SECURE_STORAGE_KEY,
        { token: value.token, expiresAt: value.expiresAt },
        false,
        false,
        KeychainAccess.whenUnlockedThisDeviceOnly,
      )
    },
    async remove() {
      const { SecureStorage } = await plugin()
      await SecureStorage.remove(SECURE_STORAGE_KEY, false)
    },
  }
}
/* c8 ignore stop */

let nativeStore: TokenStore | null = null

/**
 * The app's token store. Throws off-native: the plugin's web fallback
 * is `localStorage`, and a bearer token must never land there.
 */
export function nativeTokenStore(): TokenStore {
  if (!isNativePlatform()) {
    throw new Error('nativeTokenStore() is native-only; the web SPA authenticates by cookie.')
  }
  nativeStore ??= createTokenStore(createSecureStorageBackend())
  return nativeStore
}
