/**
 * AH-103d — the reinstall marker policy. Storage, the sign-out, the token store
 * and the clock are all injected, so every row of the plan's §2.2 table is a
 * direct assertion and the order of effects is observable.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  INSTALL_MARKER_KEY,
  INSTALL_WIPE_BOUND_MS,
  ensureInstallReconciled,
  localStorageMarker,
  reconcileInstall,
  resetInstallReconcileForTests,
  type MarkerRead,
  type ReconcileDeps,
} from './installMarker'

const FIXED = new Date('2026-10-20T12:00:00.000Z')

interface Harness {
  deps: ReconcileDeps
  log: string[]
  markerWrites: string[]
  tokenRead: ReturnType<typeof vi.fn>
  tokenClear: ReturnType<typeof vi.fn>
  wipe: ReturnType<typeof vi.fn>
  hasLapsed: ReturnType<typeof vi.fn>
}

function harness(opts: {
  native?: boolean
  marker?: MarkerRead
  token?: string | null | 'throws'
  lapsed?: boolean
  wipe?: () => Promise<void>
  writeOk?: boolean
}): Harness {
  const log: string[] = []
  const markerWrites: string[] = []
  const tokenRead = vi.fn(async () => {
    log.push('token.read')
    if (opts.token === 'throws') {
      throw new Error('keychain unreadable')
    }
    return opts.token === undefined ? null : opts.token
  })
  const tokenClear = vi.fn(async () => {
    log.push('token.clear')
  })
  const hasLapsed = vi.fn(() => opts.lapsed === true)
  const wipe = vi.fn(
    opts.wipe ??
      (async () => {
        log.push('wipe')
      }),
  )
  const deps: ReconcileDeps = {
    isNative: opts.native ?? true,
    marker: {
      read: () => {
        log.push('marker.read')
        return opts.marker ?? { ok: true, value: null }
      },
      write: (value) => {
        log.push('marker.write')
        markerWrites.push(value)
        return opts.writeOk ?? true
      },
    },
    tokens: { read: tokenRead, clear: tokenClear, hasLapsed },
    wipe,
    now: () => FIXED,
  }
  return { deps, log, markerWrites, tokenRead, tokenClear, wipe, hasLapsed }
}

describe('reconcileInstall — the §2.2 table', () => {
  it('web: touches nothing (the marker is never read or written off native)', async () => {
    const h = harness({ native: false, token: 'tok' })
    await expect(reconcileInstall(h.deps)).resolves.toBe('skipped-web')
    expect(h.log).toEqual([])
    expect(h.wipe).not.toHaveBeenCalled()
  })

  it('marker present: nothing runs, and the token is not even read', async () => {
    const h = harness({ marker: { ok: true, value: '2026-01-01T00:00:00.000Z' }, token: 'tok' })
    await expect(reconcileInstall(h.deps)).resolves.toBe('present')
    expect(h.log).toEqual(['marker.read'])
    expect(h.tokenRead).not.toHaveBeenCalled()
    expect(h.wipe).not.toHaveBeenCalled()
    expect(h.markerWrites).toEqual([])
  })

  it('marker absent + a session: signs out through the wipe, THEN writes the marker', async () => {
    const h = harness({ token: 'tok' })
    await expect(reconcileInstall(h.deps)).resolves.toBe('wiped')
    expect(h.wipe).toHaveBeenCalledTimes(1)
    expect(h.log).toEqual(['marker.read', 'token.read', 'wipe', 'marker.write'])
    expect(h.markerWrites).toEqual([FIXED.toISOString()])
    // The real sign-out already clears; the local fallback must NOT run on success.
    expect(h.tokenClear).not.toHaveBeenCalled()
  })

  it('marker absent + no session (a fresh install): writes the marker and makes no request', async () => {
    const h = harness({ token: null })
    await expect(reconcileInstall(h.deps)).resolves.toBe('marked')
    expect(h.wipe).not.toHaveBeenCalled()
    expect(h.log).toEqual(['marker.read', 'token.read', 'marker.write'])
    expect(h.markerWrites).toEqual([FIXED.toISOString()])
  })

  it('an unreadable Keychain item reads as no session: marked, not wiped', async () => {
    const h = harness({ token: 'throws' })
    await expect(reconcileInstall(h.deps)).resolves.toBe('marked')
    expect(h.wipe).not.toHaveBeenCalled()
    expect(h.markerWrites).toHaveLength(1)
  })

  it('an expired token (read() discarded it and flagged "lapsed"): no wipe, flag reset, marker written', async () => {
    const h = harness({ token: null, lapsed: true })
    await expect(reconcileInstall(h.deps)).resolves.toBe('marked')
    expect(h.wipe).not.toHaveBeenCalled()
    // clear() resets the lapsed flag, so a fresh install shows no "session expired".
    expect(h.tokenClear).toHaveBeenCalledTimes(1)
    expect(h.log).toEqual(['marker.read', 'token.read', 'token.clear', 'marker.write'])
  })

  it('a fresh install with no lapse does not call clear()', async () => {
    const h = harness({ token: null, lapsed: false })
    await reconcileInstall(h.deps)
    expect(h.tokenClear).not.toHaveBeenCalled()
  })

  it('the marker read THROWING is "unknown": fail open, nothing wiped, nothing written', async () => {
    const h = harness({ marker: { ok: false }, token: 'tok' })
    await expect(reconcileInstall(h.deps)).resolves.toBe('read-error')
    expect(h.wipe).not.toHaveBeenCalled()
    expect(h.tokenRead).not.toHaveBeenCalled()
    expect(h.markerWrites).toEqual([])
  })

  it('the marker WRITE failing does not stop boot, and the wipe still ran once', async () => {
    const h = harness({ token: 'tok', writeOk: false })
    await expect(reconcileInstall(h.deps)).resolves.toBe('wiped')
    expect(h.wipe).toHaveBeenCalledTimes(1)
  })
})

describe('reconcileInstall — a sign-out that fails or stalls', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('the wipe REJECTING still ends with a local clear and the marker written (after it)', async () => {
    const h = harness({
      token: 'tok',
      wipe: async () => {
        throw new Error('network down')
      },
    })
    await expect(reconcileInstall(h.deps)).resolves.toBe('wiped-local')
    expect(h.log).toEqual(['marker.read', 'token.read', 'token.clear', 'marker.write'])
  })

  it('the bound firing clears the token LOCALLY, writes the marker and lets boot proceed while the revoke is still pending', async () => {
    let finishWipe: () => void = () => undefined
    const h = harness({
      token: 'tok',
      wipe: () =>
        new Promise<void>((resolve) => {
          finishWipe = resolve
        }),
    })
    h.deps.boundMs = 5000

    let settled: string | null = null
    const run = reconcileInstall(h.deps).then((r) => {
      settled = r
      return r
    })

    await vi.advanceTimersByTimeAsync(4999)
    expect(settled).toBeNull() // still inside the bound: boot is held, not released early
    expect(h.tokenClear).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(1)
    await expect(run).resolves.toBe('wiped-local')
    expect(h.tokenClear).toHaveBeenCalledTimes(1)
    expect(h.markerWrites).toEqual([FIXED.toISOString()])

    // The slow revoke eventually finishes on its own; nothing throws.
    finishWipe()
    await vi.runAllTimersAsync()
  })

  it('a wipe that finishes inside the bound does not leave the bound timer running', async () => {
    const h = harness({ token: 'tok' })
    await reconcileInstall(h.deps)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('the default bound is 5 seconds', () => {
    expect(INSTALL_WIPE_BOUND_MS).toBe(5000)
  })
})

describe('ensureInstallReconciled — one run per app-open', () => {
  beforeEach(() => {
    resetInstallReconcileForTests()
  })

  it('concurrent callers share ONE run: the wipe happens once', async () => {
    const h = harness({ token: 'tok' })
    const makeDeps = vi.fn(() => h.deps)

    const results = await Promise.all([
      ensureInstallReconciled(makeDeps),
      ensureInstallReconciled(makeDeps),
      ensureInstallReconciled(makeDeps),
    ])

    expect(results).toEqual(['wiped', 'wiped', 'wiped'])
    expect(h.wipe).toHaveBeenCalledTimes(1)
    expect(makeDeps).toHaveBeenCalledTimes(1)
  })

  it('a later caller after completion gets the same result without running again', async () => {
    const h = harness({ token: 'tok' })
    await ensureInstallReconciled(() => h.deps)
    await ensureInstallReconciled(() => h.deps)
    expect(h.wipe).toHaveBeenCalledTimes(1)
  })

  it('never rejects, even when building the dependencies throws', async () => {
    await expect(
      ensureInstallReconciled(() => {
        throw new Error('pinia not active')
      }),
    ).resolves.toBe('read-error')
  })
})

describe('localStorageMarker (the WebView adapter)', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    window.localStorage.clear()
  })

  it('reads absent as { ok: true, value: null } and present as the stored value', () => {
    window.localStorage.clear() // the global setup starts every test as "marker present"
    expect(localStorageMarker.read()).toEqual({ ok: true, value: null })
    expect(localStorageMarker.write('2026-10-20T00:00:00.000Z')).toBe(true)
    expect(localStorageMarker.read()).toEqual({ ok: true, value: '2026-10-20T00:00:00.000Z' })
  })

  it('a throwing store reads as { ok: false } (unknown), never as absent', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    expect(localStorageMarker.read()).toEqual({ ok: false })
  })

  it('a throwing write returns false instead of throwing', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('quota')
    })
    expect(localStorageMarker.write('x')).toBe(false)
  })

  it('persists under the documented key', () => {
    const setItem = vi.spyOn(Storage.prototype, 'setItem')
    localStorageMarker.write('v')
    expect(setItem).toHaveBeenCalledWith(INSTALL_MARKER_KEY, 'v')
  })

  it('uses the documented key', () => {
    expect(INSTALL_MARKER_KEY).toBe('catalyst.install.marker')
  })
})
