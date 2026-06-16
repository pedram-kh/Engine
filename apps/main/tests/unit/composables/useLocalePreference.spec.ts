/**
 * Unit tests for the main SPA's `useLocalePreference` — the client-side
 * locale-persistence SOT (S5).
 *
 * Contract:
 *   - `LOCALE_STORAGE_KEY === 'catalyst.main.locale'`.
 *   - `readStoredLocale()` returns a rendered UI locale, or `null` when
 *     unset / unrenderable / storage unavailable (passive-on-read: never
 *     rewrites a stale value).
 *   - `writeStoredLocale()` persists a UI locale and IGNORES a
 *     non-rendered one (defensive: a bad value can't poison the boot).
 *   - `clearStoredLocale()` removes the key.
 *   - `resolveBootLocale(fallback)` returns the stored choice if present,
 *     else the fallback.
 *   - Storage throwing never propagates (in-memory degradation).
 *
 * Mirror discipline: stays in lockstep with
 * `apps/admin/tests/unit/composables/useLocalePreference.spec.ts`; the
 * only difference is `LOCALE_STORAGE_KEY` (`catalyst.admin.locale`).
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  LOCALE_STORAGE_KEY,
  clearStoredLocale,
  readStoredLocale,
  resolveBootLocale,
  writeStoredLocale,
} from '@/composables/useLocalePreference'

beforeEach(() => {
  window.localStorage.clear()
})

afterEach(() => {
  window.localStorage.clear()
  vi.restoreAllMocks()
})

describe('useLocalePreference (main)', () => {
  it('uses the main SPA storage key', () => {
    expect(LOCALE_STORAGE_KEY).toBe('catalyst.main.locale')
  })

  it('reads back a persisted UI locale', () => {
    writeStoredLocale('pt')
    expect(window.localStorage.getItem(LOCALE_STORAGE_KEY)).toBe('pt')
    expect(readStoredLocale()).toBe('pt')
  })

  it('returns null when nothing is stored', () => {
    expect(readStoredLocale()).toBeNull()
  })

  it('treats an unrenderable / stale stored value as unset without rewriting it', () => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'ja')
    expect(readStoredLocale()).toBeNull()
    // Passive-on-read: the stale value is left in place, not cleared.
    expect(window.localStorage.getItem(LOCALE_STORAGE_KEY)).toBe('ja')
  })

  it('never persists a non-rendered locale', () => {
    writeStoredLocale('ja')
    expect(window.localStorage.getItem(LOCALE_STORAGE_KEY)).toBeNull()
  })

  it('clears the stored locale', () => {
    writeStoredLocale('it')
    clearStoredLocale()
    expect(window.localStorage.getItem(LOCALE_STORAGE_KEY)).toBeNull()
    expect(readStoredLocale()).toBeNull()
  })

  it('resolveBootLocale returns the stored choice when present', () => {
    writeStoredLocale('it')
    expect(resolveBootLocale('en')).toBe('it')
  })

  it('resolveBootLocale falls back when unset', () => {
    expect(resolveBootLocale('en')).toBe('en')
  })

  it('degrades gracefully when storage reads throw', () => {
    vi.spyOn(window.localStorage, 'getItem').mockImplementation(() => {
      throw new Error('denied')
    })
    expect(readStoredLocale()).toBeNull()
    expect(resolveBootLocale('en')).toBe('en')
  })

  it('degrades gracefully when storage writes throw', () => {
    vi.spyOn(window.localStorage, 'setItem').mockImplementation(() => {
      throw new Error('quota')
    })
    expect(() => writeStoredLocale('pt')).not.toThrow()
  })
})
