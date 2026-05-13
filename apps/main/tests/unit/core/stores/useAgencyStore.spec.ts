/**
 * Unit tests for useAgencyStore.
 *
 * Coverage requirement: 100% lines / branches / functions / statements
 * (new composables per docs/02-CONVENTIONS.md § 4.3).
 */

import type { AgencyMembershipData } from '@catalyst/api-client'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useAgencyStore } from '@/core/stores/useAgencyStore'

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
  it('switches to a valid agency and persists', () => {
    const agency = useAgencyStore()
    const memberships = [
      makeMembership({ agency_id: 'ulid-a' }),
      makeMembership({ agency_id: 'ulid-b', agency_name: 'Agency B' }),
    ]
    agency.initFromUser(memberships)
    agency.switchAgency('ulid-b')
    expect(agency.currentAgencyId).toBe('ulid-b')
    expect(localStorageMock.setItem).toHaveBeenLastCalledWith('catalyst.agency.current', 'ulid-b')
  })

  it('is a no-op when agency is not in membership list', () => {
    const agency = useAgencyStore()
    const m = makeMembership({ agency_id: 'ulid-a' })
    agency.initFromUser([m])
    agency.switchAgency('ulid-unknown')
    expect(agency.currentAgencyId).toBe('ulid-a')
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
