/**
 * Unit tests for useAgencyStore.
 *
 * Coverage requirement: 100% lines / branches / functions / statements
 * (new composables per docs/02-CONVENTIONS.md § 4.3).
 */

import type { AgencyMembershipData } from '@catalyst/api-client'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { setAuthRebootstrap, useAgencyStore } from '@/core/stores/useAgencyStore'

// ---------------------------------------------------------------------------
// localStorage stub
// ---------------------------------------------------------------------------
const store: Record<string, string> = {}
const localStorageMock = {
  getItem: vi.fn((key: string) => store[key] ?? null),
  setItem: vi.fn((key: string, value: string) => {
    store[key] = value
  }),
  removeItem: vi.fn((key: string) => {
    delete store[key]
  }),
}
Object.defineProperty(globalThis, 'localStorage', {
  value: localStorageMock,
  writable: true,
})

function makeMembership(overrides: Partial<AgencyMembershipData> = {}): AgencyMembershipData {
  return {
    agency_id: 'agency-ulid-1',
    agency_name: 'Acme Corp',
    role: 'agency_admin',
    ...overrides,
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  // Clear localStorage stub between tests
  Object.keys(store).forEach((k) => delete store[k])
  localStorageMock.getItem.mockClear()
  localStorageMock.setItem.mockClear()
  localStorageMock.removeItem.mockClear()
})

// ---------------------------------------------------------------------------
// Initial state
// ---------------------------------------------------------------------------
describe('initial state', () => {
  it('starts with empty memberships and null currentAgencyId', () => {
    const agency = useAgencyStore()
    expect(agency.memberships).toEqual([])
    expect(agency.currentAgencyId).toBeNull()
    expect(agency.currentMembership).toBeNull()
    expect(agency.currentAgencyName).toBe('')
    expect(agency.currentRole).toBeNull()
    expect(agency.isAdmin).toBe(false)
  })
})

// ---------------------------------------------------------------------------
// initFromUser
// ---------------------------------------------------------------------------
describe('initFromUser', () => {
  it('sets currentAgencyId to the first membership when none stored in localStorage', () => {
    const agency = useAgencyStore()
    const m = makeMembership({ agency_id: 'ulid-a', agency_name: 'Agency A' })
    agency.initFromUser([m])
    expect(agency.currentAgencyId).toBe('ulid-a')
    expect(agency.currentAgencyName).toBe('Agency A')
    expect(agency.currentRole).toBe('agency_admin')
    expect(agency.isAdmin).toBe(true)
  })

  it('re-hydrates from localStorage when the stored id is still in the membership list', () => {
    store['catalyst.agency.current'] = 'ulid-b'
    const agency = useAgencyStore()
    const memberships = [
      makeMembership({ agency_id: 'ulid-a', agency_name: 'Agency A' }),
      makeMembership({ agency_id: 'ulid-b', agency_name: 'Agency B', role: 'agency_manager' }),
    ]
    agency.initFromUser(memberships)
    expect(agency.currentAgencyId).toBe('ulid-b')
    expect(agency.currentAgencyName).toBe('Agency B')
    expect(agency.currentRole).toBe('agency_manager')
    expect(agency.isAdmin).toBe(false)
  })

  it('falls back to first membership when stored id is no longer valid', () => {
    store['catalyst.agency.current'] = 'ulid-stale'
    const agency = useAgencyStore()
    const m = makeMembership({ agency_id: 'ulid-a' })
    agency.initFromUser([m])
    expect(agency.currentAgencyId).toBe('ulid-a')
    expect(localStorageMock.setItem).toHaveBeenCalledWith('catalyst.agency.current', 'ulid-a')
  })

  it('sets currentAgencyId to null when membership list is empty', () => {
    const agency = useAgencyStore()
    agency.initFromUser([])
    expect(agency.currentAgencyId).toBeNull()
    expect(agency.memberships).toEqual([])
  })

  it('persists the selected agency when falling back to first', () => {
    const agency = useAgencyStore()
    const m = makeMembership({ agency_id: 'ulid-x' })
    agency.initFromUser([m])
    expect(localStorageMock.setItem).toHaveBeenCalledWith('catalyst.agency.current', 'ulid-x')
  })
})

// ---------------------------------------------------------------------------
// switchAgency
// ---------------------------------------------------------------------------
describe('switchAgency', () => {
  beforeEach(() => {
    // Reset the rebootstrap hook so each test starts from a clean
    // slate. Specific tests install their own stub via
    // setAuthRebootstrap() to drive the re-bootstrap branch.
    setAuthRebootstrap(null)
  })

  it('switches to a valid agency and persists (no-rebootstrap branch)', async () => {
    const agency = useAgencyStore()
    const memberships = [
      makeMembership({ agency_id: 'ulid-a' }),
      makeMembership({ agency_id: 'ulid-b', agency_name: 'Agency B' }),
    ]
    agency.initFromUser(memberships)
    await agency.switchAgency('ulid-b')
    expect(agency.currentAgencyId).toBe('ulid-b')
    expect(localStorageMock.setItem).toHaveBeenLastCalledWith('catalyst.agency.current', 'ulid-b')
  })

  it('is a no-op when agency is not in membership list', async () => {
    const agency = useAgencyStore()
    const m = makeMembership({ agency_id: 'ulid-a' })
    agency.initFromUser([m])
    await agency.switchAgency('ulid-unknown')
    expect(agency.currentAgencyId).toBe('ulid-a')
  })

  it('is a no-op when target agency is already the current agency', async () => {
    const agency = useAgencyStore()
    const memberships = [
      makeMembership({ agency_id: 'ulid-a' }),
      makeMembership({ agency_id: 'ulid-b' }),
    ]
    agency.initFromUser(memberships)
    const reset = vi.fn()
    const bootstrap = vi.fn(async () => undefined)
    setAuthRebootstrap({
      resetBootstrapStatus: reset,
      bootstrap,
      isBootstrapping: () => false,
    })
    await agency.switchAgency('ulid-a')
    expect(reset).not.toHaveBeenCalled()
    expect(bootstrap).not.toHaveBeenCalled()
  })

  // Sprint 3 Chunk 4 sub-step 5 — workspace switching full UX.
  // Defense-in-depth (#40 / Sprint 2 § 5.17): break-revert validation
  // that the rebootstrap call is part of the switch path.

  it('resets bootstrapStatus then calls bootstrap() when the auth hook is wired', async () => {
    const agency = useAgencyStore()
    const memberships = [
      makeMembership({ agency_id: 'ulid-a' }),
      makeMembership({ agency_id: 'ulid-b' }),
    ]
    agency.initFromUser(memberships)
    const order: string[] = []
    setAuthRebootstrap({
      resetBootstrapStatus() {
        order.push('reset')
      },
      async bootstrap() {
        order.push('bootstrap')
      },
      isBootstrapping: () => false,
    })
    await agency.switchAgency('ulid-b')
    expect(order).toEqual(['reset', 'bootstrap'])
  })

  it('flips isSwitchingAgency true while bootstrap() is pending, then false after it resolves', async () => {
    const agency = useAgencyStore()
    const memberships = [
      makeMembership({ agency_id: 'ulid-a' }),
      makeMembership({ agency_id: 'ulid-b' }),
    ]
    agency.initFromUser(memberships)
    let resolveBootstrap: () => void = () => undefined
    const bootstrapPromise = new Promise<void>((resolve) => {
      resolveBootstrap = resolve
    })
    setAuthRebootstrap({
      resetBootstrapStatus: () => undefined,
      bootstrap: () => bootstrapPromise,
      isBootstrapping: () => false,
    })

    const switchPromise = agency.switchAgency('ulid-b')
    // Microtask flush — at this point bootstrap is pending and the flag
    // should be flipped on.
    await Promise.resolve()
    expect(agency.isSwitchingAgency).toBe(true)

    resolveBootstrap()
    await switchPromise
    expect(agency.isSwitchingAgency).toBe(false)
  })

  it('persists the new selection BEFORE awaiting bootstrap (so a refresh mid-bootstrap still lands on the new tenant)', async () => {
    const agency = useAgencyStore()
    const memberships = [
      makeMembership({ agency_id: 'ulid-a' }),
      makeMembership({ agency_id: 'ulid-b' }),
    ]
    agency.initFromUser(memberships)

    let storageValueAtBootstrap: string | null = null
    setAuthRebootstrap({
      resetBootstrapStatus: () => undefined,
      async bootstrap() {
        storageValueAtBootstrap = store['catalyst.agency.current'] ?? null
      },
      isBootstrapping: () => false,
    })
    await agency.switchAgency('ulid-b')
    expect(storageValueAtBootstrap).toBe('ulid-b')
  })

  it('resets isSwitchingAgency to false even when bootstrap rejects', async () => {
    const agency = useAgencyStore()
    const memberships = [
      makeMembership({ agency_id: 'ulid-a' }),
      makeMembership({ agency_id: 'ulid-b' }),
    ]
    agency.initFromUser(memberships)
    setAuthRebootstrap({
      resetBootstrapStatus: () => undefined,
      bootstrap: async () => {
        throw new Error('bootstrap failed')
      },
      isBootstrapping: () => false,
    })
    await expect(agency.switchAgency('ulid-b')).rejects.toThrow('bootstrap failed')
    expect(agency.isSwitchingAgency).toBe(false)
  })
})

// ---------------------------------------------------------------------------
// reset
// ---------------------------------------------------------------------------
describe('reset', () => {
  it('clears state and removes localStorage entry', () => {
    const agency = useAgencyStore()
    agency.initFromUser([makeMembership()])
    agency.reset()
    expect(agency.memberships).toEqual([])
    expect(agency.currentAgencyId).toBeNull()
    expect(localStorageMock.removeItem).toHaveBeenCalledWith('catalyst.agency.current')
  })
})
