import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { DevicePermission, PushClient } from './pushClient'
import {
  ASK_COOLDOWN_MS,
  LOGOUT_CLEANUP_TIMEOUT_MS,
  STORAGE_KEYS,
  UPKEEP_INTERVAL_MS,
  createPushRegistration,
  type PushRegistrationDeps,
  type PushStorage,
} from './pushRegistration'

/**
 * AH-102 (C4.1) — the registration logic, every branch, against fakes. The
 * Capacitor bridge itself is c8-ignored and proven on the Android walk.
 */

function memoryStorage(): PushStorage & { data: Map<string, string> } {
  const data = new Map<string, string>()
  return {
    data,
    get: (key) => data.get(key) ?? null,
    set: (key, value) => void data.set(key, value),
    remove: (key) => void data.delete(key),
  }
}

interface Harness {
  deps: PushRegistrationDeps
  client: {
    [K in keyof PushClient]: ReturnType<typeof vi.fn>
  }
  tokens: { register: ReturnType<typeof vi.fn>; forget: ReturnType<typeof vi.fn> }
  storage: ReturnType<typeof memoryStorage>
  clock: { now: number }
  state: { available: boolean; signedIn: boolean; permission: DevicePermission }
  /** Fire the listeners the client was given. */
  emit: {
    token: (token: string) => void
    foreground: () => void
    tap: () => void
  }
  unsubscribes: Array<ReturnType<typeof vi.fn>>
}

function harness(overrides: Partial<PushRegistrationDeps> = {}): Harness {
  const state = { available: true, signedIn: true, permission: 'prompt' as DevicePermission }
  const clock = { now: 1_000_000 }
  const storage = memoryStorage()
  const unsubscribes: Array<ReturnType<typeof vi.fn>> = []
  const listeners: {
    token?: (token: string) => void
    foreground?: () => void
    tap?: () => void
  } = {}

  const subscribe =
    <T>(assign: (listener: (event: T) => void) => void) =>
    async (listener: (event: T) => void) => {
      assign(listener)
      const off = vi.fn()
      unsubscribes.push(off)
      return off
    }

  const client = {
    checkPermission: vi.fn(async () => state.permission),
    requestPermission: vi.fn(async () => state.permission),
    getToken: vi.fn(async () => 'fcm-token-1'),
    deleteToken: vi.fn(async () => undefined),
    createChannel: vi.fn(async () => undefined),
    onTokenReceived: vi.fn(
      subscribe<string>((l) => {
        listeners.token = l
      }),
    ),
    onNotificationReceived: vi.fn(
      subscribe<void>((l) => {
        listeners.foreground = () => l(undefined)
      }),
    ),
    onNotificationTapped: vi.fn(
      subscribe<void>((l) => {
        listeners.tap = () => l(undefined)
      }),
    ),
  }
  const tokens = {
    register: vi.fn(async () => undefined),
    forget: vi.fn(async () => undefined),
  }

  const deps: PushRegistrationDeps = {
    client: client as unknown as PushClient,
    tokens,
    storage,
    isAvailable: () => state.available,
    platform: () => (state.available ? 'android' : null),
    isSignedIn: () => state.signedIn,
    channel: vi.fn(() => ({ id: 'catalyst_default', name: 'Updates', description: 'd' })),
    appVersion: async () => '1.4.0',
    now: () => clock.now,
    ...overrides,
  }

  return {
    deps,
    client,
    tokens,
    storage,
    clock,
    state,
    unsubscribes,
    emit: {
      token: (t) => listeners.token?.(t),
      foreground: () => listeners.foreground?.(),
      tap: () => listeners.tap?.(),
    },
  }
}

async function settle(): Promise<void> {
  for (let i = 0; i < 6; i += 1) {
    await Promise.resolve()
  }
}

describe('unsupported builds (web, iOS before AH-102-ios)', () => {
  it('does nothing at all: no OS call, no HTTP, no listener, no storage write', async () => {
    const h = harness()
    h.state.available = false
    const registration = createPushRegistration(h.deps)

    expect(registration.permission.value).toBe('unsupported')
    expect(await registration.isSoftAskDue()).toBe(false)
    expect(await registration.accept()).toBe('unsupported')
    registration.decline()
    await registration.syncIfGranted()
    const stop = registration.start({ onForeground: vi.fn(), onTap: vi.fn() })
    stop()
    await registration.logoutCleanup()

    for (const fn of Object.values(h.client)) {
      expect(fn).not.toHaveBeenCalled()
    }
    expect(h.tokens.register).not.toHaveBeenCalled()
    expect(h.tokens.forget).not.toHaveBeenCalled()
    // `decline()` is the one thing that may write (it is a local note); nothing else.
    expect([...h.storage.data.keys()]).toEqual([STORAGE_KEYS.askedAt])
  })
})

describe('soft-ask: which permission states ask', () => {
  it.each([
    ['prompt', true],
    ['granted', false],
    ['denied', false],
  ] as const)('permission %s → due=%s', async (permission, due) => {
    const h = harness()
    h.state.permission = permission
    const registration = createPushRegistration(h.deps)

    expect(await registration.isSoftAskDue()).toBe(due)
    expect(registration.permission.value).toBe(permission)
  })

  it('a failed permission read leaves the state and does not ask', async () => {
    const h = harness()
    h.client.checkPermission.mockRejectedValue(new Error('bridge down'))
    const registration = createPushRegistration(h.deps)

    expect(await registration.isSoftAskDue()).toBe(false)
    expect(registration.permission.value).toBe('unknown')
  })
})

describe('soft-ask: the 30-day cool-down, both sides', () => {
  it('declining hides the ask until exactly 30 days have passed', async () => {
    const h = harness()
    const registration = createPushRegistration(h.deps)

    expect(await registration.isSoftAskDue()).toBe(true)
    registration.decline()
    expect(h.storage.data.get(STORAGE_KEYS.askedAt)).toBe(String(h.clock.now))

    h.clock.now += ASK_COOLDOWN_MS - 1
    expect(await registration.isSoftAskDue()).toBe(false)

    h.clock.now += 1
    expect(await registration.isSoftAskDue()).toBe(true)
  })

  it('an unreadable askedAt reads as "never asked"', async () => {
    const h = harness()
    h.storage.data.set(STORAGE_KEYS.askedAt, 'not-a-number')
    const registration = createPushRegistration(h.deps)

    expect(await registration.isSoftAskDue()).toBe(true)
  })

  it('does not call the OS permission prompt when the creator declines', async () => {
    const h = harness()
    createPushRegistration(h.deps).decline()

    expect(h.client.requestPermission).not.toHaveBeenCalled()
    expect(h.client.getToken).not.toHaveBeenCalled()
  })
})

describe('accept', () => {
  it('prompts, then creates the channel, mints ONE token and POSTs it once', async () => {
    const h = harness()
    const registration = createPushRegistration(h.deps)
    h.client.requestPermission.mockImplementation(async () => {
      h.state.permission = 'granted'
      return 'granted'
    })

    expect(await registration.accept()).toBe('granted')

    expect(h.client.requestPermission).toHaveBeenCalledTimes(1)
    expect(h.client.createChannel).toHaveBeenCalledWith({
      id: 'catalyst_default',
      name: 'Updates',
      description: 'd',
    })
    expect(h.client.getToken).toHaveBeenCalledTimes(1)
    expect(h.tokens.register).toHaveBeenCalledTimes(1)
    expect(h.tokens.register).toHaveBeenCalledWith('fcm-token-1', 'android', '1.4.0')
    expect(h.storage.data.get(STORAGE_KEYS.token)).toBe('fcm-token-1')
    expect(h.storage.data.get(STORAGE_KEYS.registeredAt)).toBe(String(h.clock.now))
    expect(registration.permission.value).toBe('granted')
  })

  it('an OS "don\'t allow" mints no token, POSTs nothing, and starts the cool-down', async () => {
    const h = harness()
    h.client.requestPermission.mockResolvedValue('denied')
    const registration = createPushRegistration(h.deps)

    expect(await registration.accept()).toBe('denied')

    expect(h.client.getToken).not.toHaveBeenCalled()
    expect(h.tokens.register).not.toHaveBeenCalled()
    expect(h.storage.data.get(STORAGE_KEYS.askedAt)).toBe(String(h.clock.now))
    expect(registration.permission.value).toBe('denied')
  })

  it('a permission request that throws resolves to the re-read state and registers nothing', async () => {
    const h = harness()
    h.client.requestPermission.mockRejectedValue(new Error('boom'))
    const registration = createPushRegistration(h.deps)

    expect(await registration.accept()).toBe('prompt')
    expect(h.tokens.register).not.toHaveBeenCalled()
  })

  it('omits app_version when the version cannot be read (the column is nullable)', async () => {
    const h = harness({ appVersion: undefined })
    h.client.requestPermission.mockResolvedValue('granted')
    await createPushRegistration(h.deps).accept()

    expect(h.tokens.register).toHaveBeenCalledWith('fcm-token-1', 'android', undefined)
  })
})

describe('registration guards', () => {
  it('never registers while signed out (the endpoint is bearer-only)', async () => {
    const h = harness()
    h.state.signedIn = false
    h.client.requestPermission.mockResolvedValue('granted')
    await createPushRegistration(h.deps).accept()

    expect(h.client.getToken).not.toHaveBeenCalled()
    expect(h.tokens.register).not.toHaveBeenCalled()
    expect(h.storage.data.has(STORAGE_KEYS.registeredAt)).toBe(false)
  })

  it('a failed POST is not recorded, so the next sync retries', async () => {
    const h = harness()
    h.state.permission = 'granted'
    h.tokens.register.mockRejectedValueOnce(new Error('offline'))
    const registration = createPushRegistration(h.deps)

    await registration.syncIfGranted()
    expect(h.storage.data.has(STORAGE_KEYS.registeredAt)).toBe(false)

    await registration.syncIfGranted()
    expect(h.tokens.register).toHaveBeenCalledTimes(2)
    expect(h.storage.data.has(STORAGE_KEYS.registeredAt)).toBe(true)
  })

  it('an empty token is never POSTed', async () => {
    const h = harness()
    h.state.permission = 'granted'
    h.client.getToken.mockResolvedValue('')
    await createPushRegistration(h.deps).syncIfGranted()

    expect(h.tokens.register).not.toHaveBeenCalled()
  })
})

describe('upkeep: at most once a day, only when granted', () => {
  it('registers a granted device, then skips until a day has passed', async () => {
    const h = harness()
    h.state.permission = 'granted'
    const registration = createPushRegistration(h.deps)

    await registration.syncIfGranted()
    expect(h.tokens.register).toHaveBeenCalledTimes(1)

    h.clock.now += UPKEEP_INTERVAL_MS - 1
    await registration.syncIfGranted()
    expect(h.tokens.register).toHaveBeenCalledTimes(1)

    h.clock.now += 1
    await registration.syncIfGranted()
    expect(h.tokens.register).toHaveBeenCalledTimes(2)
  })

  it.each(['prompt', 'denied'] as const)('does nothing when permission is %s', async (state) => {
    const h = harness()
    h.state.permission = state
    await createPushRegistration(h.deps).syncIfGranted()

    expect(h.client.getToken).not.toHaveBeenCalled()
    expect(h.tokens.register).not.toHaveBeenCalled()
  })
})

describe('token rotation', () => {
  async function started(h: Harness) {
    h.state.permission = 'granted'
    const registration = createPushRegistration(h.deps)
    await registration.refreshPermission()
    registration.start({ onForeground: vi.fn(), onTap: vi.fn() })
    await settle()
    h.tokens.register.mockClear()
    h.client.getToken.mockClear()
    return registration
  }

  it('a rotated token is POSTed once, without minting another', async () => {
    const h = harness()
    await started(h)

    h.emit.token('fcm-token-2')
    await settle()

    expect(h.tokens.register).toHaveBeenCalledTimes(1)
    expect(h.tokens.register).toHaveBeenCalledWith('fcm-token-2', 'android', '1.4.0')
    expect(h.client.getToken).not.toHaveBeenCalled()
  })

  it('the same token inside the upkeep window is a repeat, not a rotation', async () => {
    const h = harness()
    await started(h)
    h.emit.token('fcm-token-2')
    await settle()
    h.tokens.register.mockClear()

    h.emit.token('fcm-token-2')
    await settle()

    expect(h.tokens.register).not.toHaveBeenCalled()
  })

  it('ignores a token event when permission is not granted', async () => {
    const h = harness()
    h.state.permission = 'prompt'
    const registration = createPushRegistration(h.deps)
    await registration.refreshPermission()
    registration.start({ onForeground: vi.fn(), onTap: vi.fn() })
    await settle()

    h.emit.token('fcm-token-9')
    await settle()

    expect(h.tokens.register).not.toHaveBeenCalled()
  })
})

describe('start(): foreground, tap and the stop', () => {
  it('routes a foreground push and a tap to the injected handlers', async () => {
    const h = harness()
    const onForeground = vi.fn()
    const onTap = vi.fn()
    createPushRegistration(h.deps).start({ onForeground, onTap })
    await settle()

    h.emit.foreground()
    expect(onForeground).toHaveBeenCalledTimes(1)
    expect(onTap).not.toHaveBeenCalled()

    h.emit.tap()
    expect(onTap).toHaveBeenCalledTimes(1)
  })

  it('stop() unsubscribes all three and silences late events', async () => {
    const h = harness()
    const onForeground = vi.fn()
    const onTap = vi.fn()
    const stop = createPushRegistration(h.deps).start({ onForeground, onTap })
    await settle()

    stop()
    expect(h.unsubscribes).toHaveLength(3)
    for (const off of h.unsubscribes) {
      expect(off).toHaveBeenCalledTimes(1)
    }

    h.emit.foreground()
    h.emit.tap()
    h.emit.token('late')
    await settle()
    expect(onForeground).not.toHaveBeenCalled()
    expect(onTap).not.toHaveBeenCalled()
    expect(h.tokens.register).not.toHaveBeenCalled()
  })

  it('stopping before the subscriptions resolve still unsubscribes them', async () => {
    const h = harness()
    const stop = createPushRegistration(h.deps).start({ onForeground: vi.fn(), onTap: vi.fn() })
    stop()
    await settle()

    expect(h.unsubscribes).toHaveLength(3)
    for (const off of h.unsubscribes) {
      expect(off).toHaveBeenCalledTimes(1)
    }
  })

  it('a subscription that fails is swallowed (a broken bridge never crashes boot)', async () => {
    const h = harness()
    h.client.onTokenReceived.mockRejectedValue(new Error('no plugin'))

    expect(() =>
      createPushRegistration(h.deps).start({ onForeground: vi.fn(), onTap: vi.fn() }),
    ).not.toThrow()
    await settle()
  })

  it('syncs a granted device at start', async () => {
    const h = harness()
    h.state.permission = 'granted'
    createPushRegistration(h.deps).start({ onForeground: vi.fn(), onTap: vi.fn() })
    await settle()

    expect(h.tokens.register).toHaveBeenCalledTimes(1)
  })
})

describe('logoutCleanup (R-4)', () => {
  it('forgets the token server-side FIRST, then at FCM, and wipes the local record', async () => {
    const h = harness()
    h.state.permission = 'granted'
    h.storage.data.set(STORAGE_KEYS.token, 'fcm-token-1')
    h.storage.data.set(STORAGE_KEYS.registeredAt, '1')
    const order: string[] = []
    h.tokens.forget.mockImplementation(async () => void order.push('forget'))
    h.client.deleteToken.mockImplementation(async () => void order.push('deleteToken'))

    await createPushRegistration(h.deps).logoutCleanup()

    expect(order).toEqual(['forget', 'deleteToken'])
    expect(h.tokens.forget).toHaveBeenCalledWith('fcm-token-1')
    expect(h.storage.data.has(STORAGE_KEYS.token)).toBe(false)
    expect(h.storage.data.has(STORAGE_KEYS.registeredAt)).toBe(false)
  })

  it('falls back to asking the plugin for the token when none was recorded', async () => {
    const h = harness()
    h.state.permission = 'granted'
    await createPushRegistration(h.deps).logoutCleanup()

    expect(h.tokens.forget).toHaveBeenCalledWith('fcm-token-1')
  })

  it('still invalidates the token at FCM when the server call fails (offline)', async () => {
    const h = harness()
    h.state.permission = 'granted'
    h.tokens.forget.mockRejectedValue(new Error('offline'))

    await expect(createPushRegistration(h.deps).logoutCleanup()).resolves.toBeUndefined()
    expect(h.client.deleteToken).toHaveBeenCalledTimes(1)
  })

  it('never throws, even when both calls fail', async () => {
    const h = harness()
    h.state.permission = 'granted'
    h.tokens.forget.mockRejectedValue(new Error('a'))
    h.client.deleteToken.mockRejectedValue(new Error('b'))

    await expect(createPushRegistration(h.deps).logoutCleanup()).resolves.toBeUndefined()
  })

  it('does nothing when permission was never granted (there is no token to forget)', async () => {
    const h = harness()
    h.state.permission = 'prompt'
    await createPushRegistration(h.deps).logoutCleanup()

    expect(h.tokens.forget).not.toHaveBeenCalled()
    expect(h.client.deleteToken).not.toHaveBeenCalled()
  })

  describe('bounded by a timeout', () => {
    beforeEach(() => {
      vi.useFakeTimers()
    })
    afterEach(() => {
      vi.useRealTimers()
    })

    it('a hung server call cannot hold sign-out past the timeout', async () => {
      const h = harness()
      h.state.permission = 'granted'
      h.tokens.forget.mockReturnValue(new Promise(() => undefined))
      let finished = false

      const done = createPushRegistration(h.deps)
        .logoutCleanup()
        .then(() => {
          finished = true
        })

      await vi.advanceTimersByTimeAsync(LOGOUT_CLEANUP_TIMEOUT_MS - 1)
      expect(finished).toBe(false)

      await vi.advanceTimersByTimeAsync(1)
      await done
      expect(finished).toBe(true)
    })

    it('honours an explicit timeout', async () => {
      const h = harness()
      h.state.permission = 'granted'
      h.tokens.forget.mockReturnValue(new Promise(() => undefined))
      let finished = false

      const done = createPushRegistration(h.deps)
        .logoutCleanup(50)
        .then(() => {
          finished = true
        })

      await vi.advanceTimersByTimeAsync(50)
      await done
      expect(finished).toBe(true)
    })
  })
})
