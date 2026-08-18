/**
 * Vitest coverage for `useCreatorProfile` (AH-080, D3) — the mode-resolution
 * contract `CreatorProfileContent` relies on.
 *
 * Pins the fallback sequence evidence the review package cites: a roster hit
 * calls ONLY roster; a roster 404 (no `assumeFull`) falls back to discover,
 * calling EACH endpoint exactly once and never re-attempting roster;
 * `assumeFull: true` skips the fallback branch entirely, surfacing a roster
 * 404 as a real error instead. Neither branch logs to the console.
 */

import type {
  AgencyCreatorDetailEnvelope,
  CreatorPublicProfileEnvelope,
} from '@catalyst/api-client'
import { ApiError } from '@catalyst/api-client'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/roster/api/roster.api', () => ({
  rosterApi: { show: vi.fn() },
}))
vi.mock('@/modules/discover/api/discovery.api', () => ({
  discoveryApi: { show: vi.fn() },
}))

import { discoveryApi } from '@/modules/discover/api/discovery.api'
import { rosterApi } from '@/modules/roster/api/roster.api'

import { useCreatorProfile } from './useCreatorProfile'

const AGENCY_ID = 'agency-ulid'
const CREATOR_ULID = '01CREATORULIDXXXXXXXXXXXXXX'

function fullEnvelope(): AgencyCreatorDetailEnvelope {
  return {
    data: {
      id: '01RELATIONULIDXXXXXXXXXXXXX',
      type: 'agency_creator_details',
      attributes: {
        relationship_status: 'roster',
        internal_rating: null,
        internal_notes: null,
        total_campaigns_completed: 0,
        total_paid_minor_units: 0,
        last_engaged_at: null,
        is_blacklisted: false,
        blacklist_scope: null,
        blacklist_type: null,
        blacklisted_at: null,
        creator: {
          id: CREATOR_ULID,
          display_name: 'Ada Lovelace',
          bio: null,
          email: 'ada@example.com',
          account_name: null,
          account_last_name: null,
          country_code: null,
          region: null,
          primary_language: null,
          secondary_languages: null,
          accent: null,
          content_companions: null,
          categories: [],
          avatar_url: null,
          cover_url: null,
          application_status: 'approved',
          social_accounts: [],
          portfolio: [],
        },
      },
    },
  }
}

function thinEnvelope(): CreatorPublicProfileEnvelope {
  return {
    data: {
      id: CREATOR_ULID,
      type: 'creator_public_profiles',
      attributes: {
        display_name: 'Ada Lovelace',
        bio: null,
        country_code: null,
        region: null,
        primary_language: null,
        secondary_languages: null,
        accent: null,
        content_companions: null,
        categories: [],
        avatar_url: null,
        cover_url: null,
        profile_completeness_score: 0,
        social_accounts: [],
        portfolio: [],
        relationship_status: null,
      },
    },
  }
}

describe('useCreatorProfile (AH-080, D3)', () => {
  let consoleErrorSpy: ReturnType<typeof vi.spyOn>
  let consoleWarnSpy: ReturnType<typeof vi.spyOn>

  beforeEach(() => {
    vi.clearAllMocks()
    consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
    consoleWarnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {})
  })

  it('resolves FULL on a roster hit — discover is never called', async () => {
    vi.mocked(rosterApi.show).mockResolvedValue(fullEnvelope())

    const { load, profile, error, loading } = useCreatorProfile()
    const promise = load(AGENCY_ID, CREATOR_ULID)
    expect(loading.value).toBe(true)
    await promise

    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
    expect(profile.value?.mode).toBe('full')
    expect(rosterApi.show).toHaveBeenCalledTimes(1)
    expect(rosterApi.show).toHaveBeenCalledWith(AGENCY_ID, CREATOR_ULID)
    expect(discoveryApi.show).not.toHaveBeenCalled()
    expect(consoleErrorSpy).not.toHaveBeenCalled()
    expect(consoleWarnSpy).not.toHaveBeenCalled()
  })

  it('falls back to THIN on a roster 404 — exactly one roster call, exactly one discover call', async () => {
    vi.mocked(rosterApi.show).mockRejectedValue(
      new ApiError({ status: 404, code: 'not_found', message: 'Not found' }),
    )
    vi.mocked(discoveryApi.show).mockResolvedValue(thinEnvelope())

    const { load, profile, error } = useCreatorProfile()
    await load(AGENCY_ID, CREATOR_ULID)

    expect(error.value).toBeNull()
    expect(profile.value?.mode).toBe('thin')
    expect(rosterApi.show).toHaveBeenCalledTimes(1)
    expect(discoveryApi.show).toHaveBeenCalledTimes(1)
    expect(discoveryApi.show).toHaveBeenCalledWith(AGENCY_ID, CREATOR_ULID)
    expect(consoleErrorSpy).not.toHaveBeenCalled()
    expect(consoleWarnSpy).not.toHaveBeenCalled()
  })

  it('never re-attempts roster after the fallback — one of each, never two roster calls', async () => {
    vi.mocked(rosterApi.show).mockRejectedValue(
      new ApiError({ status: 404, code: 'not_found', message: 'Not found' }),
    )
    vi.mocked(discoveryApi.show).mockRejectedValue(
      new ApiError({ status: 404, code: 'not_found', message: 'Not found' }),
    )

    const { load, error } = useCreatorProfile()
    await load(AGENCY_ID, CREATOR_ULID)

    expect(error.value).toBe('not-found')
    expect(rosterApi.show).toHaveBeenCalledTimes(1)
    expect(discoveryApi.show).toHaveBeenCalledTimes(1)
  })

  it('assumeFull skips the fallback — a roster 404 surfaces as a real error, discover never called', async () => {
    vi.mocked(rosterApi.show).mockRejectedValue(
      new ApiError({ status: 404, code: 'not_found', message: 'Not found' }),
    )

    const { load, profile, error } = useCreatorProfile()
    await load(AGENCY_ID, CREATOR_ULID, { assumeFull: true })

    expect(profile.value).toBeNull()
    expect(error.value).toBe('not-found')
    expect(rosterApi.show).toHaveBeenCalledTimes(1)
    expect(discoveryApi.show).not.toHaveBeenCalled()
  })

  it('a non-404 roster error never falls back and classifies as load-failed', async () => {
    vi.mocked(rosterApi.show).mockRejectedValue(
      new ApiError({ status: 500, code: 'server_error', message: 'boom' }),
    )

    const { load, error } = useCreatorProfile()
    await load(AGENCY_ID, CREATOR_ULID)

    expect(error.value).toBe('load-failed')
    expect(discoveryApi.show).not.toHaveBeenCalled()
  })

  it('a discover-side failure after a roster 404 classifies independently (a 500 there is load-failed, not not-found)', async () => {
    vi.mocked(rosterApi.show).mockRejectedValue(
      new ApiError({ status: 404, code: 'not_found', message: 'Not found' }),
    )
    vi.mocked(discoveryApi.show).mockRejectedValue(
      new ApiError({ status: 500, code: 'server_error', message: 'boom' }),
    )

    const { load, error } = useCreatorProfile()
    await load(AGENCY_ID, CREATOR_ULID)

    expect(error.value).toBe('load-failed')
  })

  it('resets state on each load — a stale profile/error never leaks into the next call', async () => {
    vi.mocked(rosterApi.show).mockRejectedValueOnce(
      new ApiError({ status: 500, code: 'server_error', message: 'boom' }),
    )
    const { load, profile, error } = useCreatorProfile()
    await load(AGENCY_ID, CREATOR_ULID)
    expect(error.value).toBe('load-failed')

    vi.mocked(rosterApi.show).mockResolvedValueOnce(fullEnvelope())
    await load(AGENCY_ID, 'a-different-creator')
    expect(error.value).toBeNull()
    expect(profile.value?.mode).toBe('full')
  })
})
