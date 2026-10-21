import { describe, expect, it, vi } from 'vitest'

import {
  idsFromRow,
  isUlid,
  parsePushTapData,
  resolveNotificationDestination,
  type DestinationDeps,
} from './notificationDestination'

/**
 * AH-103 C2.2 — the parser and the resolver.
 *
 * The parser specs are fed the payload shapes OBSERVED on a real iPhone 16 Pro and
 * a Samsung SM-A515F in the device spike (inventory §9.3) — vendor extras, a nested
 * `aps` object, a stringified Bundle, numbers where strings were expected, `v` as
 * the STRING "1" — not a tidy `{type, assignment_ulid}` typed by hand. Only the ULID
 * values differ: the spike sent fixed fake strings (`01SPIKE…`), which this parser
 * deliberately rejects, so real-shaped ones stand in.
 */

const ASSIGNMENT = '01J9ZK3Q8M5T7WXYABCDEFGHJK'
const CAMPAIGN = '01J9ZK3Q8M5T7WXYCAMPAGNAAA'
const THREAD = '01J9ZK3Q8M5T7WXYTHREADAAAA'
const NOTIF = '01J9ZK3Q8M5T7WXYNTFAAAAAAA'
const AGENCY = '01J9ZK3Q8M5T7WXYAGENCYAAAA'
const CREATOR = '01J9ZK3Q8M5T7WXYCREATRAAAA'

/** `notification.data` as observed on iOS (background, `full` sender). */
function observedIos(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    type: 'assignment.draft_approved',
    v: '1',
    notification_ulid: NOTIF,
    assignment_ulid: ASSIGNMENT,
    aps: { alert: { title: 'Draft approved', body: 'x' }, sound: 'default' },
    'gcm.message_id': '1760000000000000',
    'google.c.a.e': '1',
    'google.c.fid': 'fid-abc',
    'google.c.sender.id': '1234567890',
    ...overrides,
  }
}

/** `notification.data` as observed on Android (killed → tap). */
function observedAndroid(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    type: 'assignment.draft_approved',
    v: '1',
    notification_ulid: NOTIF,
    assignment_ulid: ASSIGNMENT,
    from: '1234567890',
    collapse_key: 'com.catalystgrowth.platform',
    'gcm.n.analytics_data': 'Bundle[mParcelledData.dataSize=100]',
    'google.delivered_priority': 'high',
    'google.original_priority': 'high',
    'google.sent_time': 1760000000000,
    'google.ttl': 86400,
    'google.product_id': 111,
    ...overrides,
  }
}

describe('parsePushTapData — built on the observed device payloads', () => {
  it.each([
    ['iOS', observedIos],
    ['Android', observedAndroid],
  ])('%s: takes the named keys and ignores every vendor extra', (_os, make) => {
    expect(parsePushTapData(make())).toEqual({
      type: 'assignment.draft_approved',
      notificationUlid: NOTIF,
      ids: { assignment_ulid: ASSIGNMENT },
      version: 1,
    })
  })

  it('reads `v` as the string "1" FCM delivers, and as a number', () => {
    expect(parsePushTapData(observedIos({ v: '1' }))?.version).toBe(1)
    expect(parsePushTapData(observedIos({ v: 1 }))?.version).toBe(1)
  })

  it.each([
    ['absent', undefined],
    ['garbage', 'abc'],
    ['empty', ''],
    ['zero', '0'],
    ['negative', '-3'],
    ['fractional', '1.5'],
    ['object', { a: 1 }],
  ])('P-Q3: a `v` that is %s is treated as 1 (never a failure)', (_label, v) => {
    const parsed = parsePushTapData(observedIos({ v }))

    expect(parsed?.version).toBe(1)
    expect(parsed?.ids).toEqual({ assignment_ulid: ASSIGNMENT })
  })

  it('P-Q3: a HIGHER `v` still reads the route keys (an additive contract must not punish an old build)', () => {
    expect(parsePushTapData(observedIos({ v: '2' }))).toMatchObject({
      version: 2,
      ids: { assignment_ulid: ASSIGNMENT },
    })
  })

  it('a `bare` payload ({type, v} only) parses to a type with no ids', () => {
    expect(parsePushTapData({ type: 'creator.approved', v: '1', aps: {} })).toEqual({
      type: 'creator.approved',
      ids: {},
      version: 1,
    })
  })

  it('reads all three route keys and the notification ulid when present', () => {
    const parsed = parsePushTapData(
      observedAndroid({ campaign_ulid: CAMPAIGN, thread_ulid: THREAD }),
    )

    expect(parsed?.ids).toEqual({
      assignment_ulid: ASSIGNMENT,
      campaign_ulid: CAMPAIGN,
      thread_ulid: THREAD,
    })
  })

  it.each([
    ['the spike fixture strings', '01SPIKEASSIGN'],
    ['free text', 'SENTINEL-FEEDBACK-xyz'],
    ['25 characters', ASSIGNMENT.slice(0, 25)],
    ['27 characters', `${ASSIGNMENT}M`],
    ['a forbidden letter (U)', `${ASSIGNMENT.slice(0, 25)}U`],
    ['a trailing newline', `${ASSIGNMENT}\n`],
    ['empty', ''],
    ['a number', 12345],
    ['a nested object', { id: ASSIGNMENT }],
    ['an array', [ASSIGNMENT]],
    ['null', null],
  ])('drops a route key whose value is %s', (_label, value) => {
    const parsed = parsePushTapData(observedIos({ assignment_ulid: value }))

    expect(parsed?.ids).toEqual({})
    expect(parsed?.type).toBe('assignment.draft_approved')
  })

  it('drops a malformed notification_ulid but keeps the rest', () => {
    const parsed = parsePushTapData(observedIos({ notification_ulid: 'nope' }))

    expect(parsed?.notificationUlid).toBeUndefined()
    expect(parsed?.ids).toEqual({ assignment_ulid: ASSIGNMENT })
  })

  it('accepts a lowercase ULID (case-insensitive shape)', () => {
    expect(
      parsePushTapData(observedIos({ assignment_ulid: ASSIGNMENT.toLowerCase() }))?.ids,
    ).toEqual({
      assignment_ulid: ASSIGNMENT.toLowerCase(),
    })
  })

  it.each([
    ['null', null],
    ['undefined', undefined],
    ['a string', 'assignment.invited'],
    ['a number', 7],
    ['an array', [{ type: 'assignment.invited' }]],
    ['no type', { v: '1' }],
    ['an empty type', { type: '' }],
    ['a numeric type', { type: 3 }],
  ])('never throws: %s → null (the fallback)', (_label, input) => {
    expect(() => parsePushTapData(input)).not.toThrow()
    expect(parsePushTapData(input)).toBeNull()
  })

  it('does not read title/body/subtitle/id even if they were present on the data', () => {
    const parsed = parsePushTapData(
      observedIos({ title: 't', body: 'b', subtitle: 's', id: ASSIGNMENT }),
    )

    expect(parsed).toEqual({
      type: 'assignment.draft_approved',
      notificationUlid: NOTIF,
      ids: { assignment_ulid: ASSIGNMENT },
      version: 1,
    })
  })
})

describe('idsFromRow', () => {
  it('takes only ULID-shaped values of the three route keys from a row bag', () => {
    expect(
      idsFromRow({
        campaign_name: 'Summer Launch',
        feedback: 'looks great',
        assignment_ulid: ASSIGNMENT,
        campaign_ulid: 'not-a-ulid',
        thread_ulid: THREAD,
      }),
    ).toEqual({ assignment_ulid: ASSIGNMENT, thread_ulid: THREAD })
  })

  it('tolerates a missing bag', () => {
    expect(idsFromRow(null)).toEqual({})
    expect(idsFromRow(undefined)).toEqual({})
    expect(idsFromRow({})).toEqual({})
  })

  it('isUlid is the single shape rule', () => {
    expect(isUlid(ASSIGNMENT)).toBe(true)
    expect(isUlid(`${ASSIGNMENT}\n`)).toBe(false)
    expect(isUlid(null)).toBe(false)
  })
})

function deps(overrides: Partial<DestinationDeps> = {}): DestinationDeps {
  return {
    hasRoute: () => true,
    hasSession: () => true,
    lookupThread: vi.fn().mockResolvedValue(AGENCY),
    ...overrides,
  }
}

describe('resolveNotificationDestination', () => {
  it('assignment types → the assignment detail with the id as the `ulid` param', async () => {
    expect(
      await resolveNotificationDestination(
        { type: 'assignment.revision_requested', ids: { assignment_ulid: ASSIGNMENT } },
        deps(),
      ),
    ).toEqual({
      kind: 'route',
      to: { name: 'creator.assignment.detail', params: { ulid: ASSIGNMENT } },
    })
  })

  it('message.received_by_creator → the assignment with the chat in view (?focus=messages)', async () => {
    expect(
      await resolveNotificationDestination(
        { type: 'message.received_by_creator', ids: { assignment_ulid: ASSIGNMENT } },
        deps(),
      ),
    ).toEqual({
      kind: 'route',
      to: {
        name: 'creator.assignment.detail',
        params: { ulid: ASSIGNMENT },
        query: { focus: 'messages' },
      },
    })
  })

  it.each([
    ['assignment.draft_submitted', 'drafts'],
    ['assignment.contracted', 'creators'],
    ['message.received_by_agency', 'messages'],
    ['campaign_application.submitted', 'applications'],
  ])('agency type %s → the campaign detail on the %s tab', async (type, tab) => {
    expect(
      await resolveNotificationDestination({ type, ids: { campaign_ulid: CAMPAIGN } }, deps()),
    ).toEqual({
      kind: 'route',
      to: { name: 'campaigns.detail', params: { ulid: CAMPAIGN }, query: { tab } },
    })
  })

  it('a type with no id (jobs board rejection) → its fixed route, no params', async () => {
    expect(
      await resolveNotificationDestination(
        { type: 'campaign_application.rejected', ids: {} },
        deps(),
      ),
    ).toEqual({ kind: 'route', to: { name: 'creator.jobs' } })
  })

  it('account verdicts → the dashboard; admin_connected → the messages inbox', async () => {
    for (const type of ['creator.approved', 'creator.rejected']) {
      expect(await resolveNotificationDestination({ type, ids: {} }, deps())).toEqual({
        kind: 'route',
        to: { name: 'creator.dashboard' },
      })
    }
    expect(
      await resolveNotificationDestination(
        { type: 'agency_creator_relation.admin_connected', ids: {} },
        deps(),
      ),
    ).toEqual({ kind: 'route', to: { name: 'creator.messages' } })
  })

  it('disconnected → the fallback BY DESIGN (the relationship is gone; there is nothing to open)', async () => {
    expect(
      await resolveNotificationDestination(
        { type: 'agency_creator_relation.disconnected', ids: { thread_ulid: THREAD } },
        deps(),
      ),
    ).toEqual({ kind: 'fallback' })
  })

  describe('campaign.job_posted (the only type with a subject fallback)', () => {
    it('uses its own campaign_ulid', async () => {
      expect(
        await resolveNotificationDestination(
          { type: 'campaign.job_posted', ids: { campaign_ulid: CAMPAIGN } },
          deps(),
        ),
      ).toEqual({ kind: 'route', to: { name: 'creator.job.detail', params: { ulid: CAMPAIGN } } })
    })

    it('an OLD row (no campaign_ulid in the bag) falls back to subject.ulid when the subject is a Campaign', async () => {
      expect(
        await resolveNotificationDestination(
          { type: 'campaign.job_posted', ids: {}, subject: { type: 'Campaign', ulid: CAMPAIGN } },
          deps(),
        ),
      ).toEqual({ kind: 'route', to: { name: 'creator.job.detail', params: { ulid: CAMPAIGN } } })
    })

    it.each([
      ['a different subject class', { type: 'CampaignAssignment', ulid: CAMPAIGN }],
      ['a null subject ulid', { type: 'Campaign', ulid: null }],
      ['a malformed subject ulid', { type: 'Campaign', ulid: 'x' }],
      ['no subject', null],
    ])('does NOT use %s', async (_label, subject) => {
      expect(
        await resolveNotificationDestination(
          { type: 'campaign.job_posted', ids: {}, subject },
          deps(),
        ),
      ).toEqual({ kind: 'fallback' })
    })

    it('a push (no subject) with no key falls back — it never guesses', async () => {
      expect(
        await resolveNotificationDestination({ type: 'campaign.job_posted', ids: {} }, deps()),
      ).toEqual({ kind: 'fallback' })
    })
  })

  it('OLD agency rows (message.received_by_agency / application.submitted written before AH-103 have no campaign_ulid) → the fallback, never a dead click', async () => {
    for (const type of ['message.received_by_agency', 'campaign_application.submitted']) {
      expect(
        await resolveNotificationDestination(
          { type, ids: {}, subject: { type: 'CampaignAssignment', ulid: ASSIGNMENT } },
          deps(),
        ),
      ).toEqual({ kind: 'fallback' })
    }
  })

  it('a missing required id → the fallback', async () => {
    expect(
      await resolveNotificationDestination({ type: 'assignment.invited', ids: {} }, deps()),
    ).toEqual({ kind: 'fallback' })
  })

  it('an unknown type → the fallback (AH-102 behaviour; never an error)', async () => {
    expect(
      await resolveNotificationDestination(
        { type: 'assignment.declined', ids: { assignment_ulid: ASSIGNMENT } },
        deps(),
      ),
    ).toEqual({ kind: 'fallback' })
    expect(await resolveNotificationDestination({ type: 'totally.new', ids: {} }, deps())).toEqual({
      kind: 'fallback',
    })
  })

  it('a route name the router no longer has → the fallback (defence against a rename)', async () => {
    expect(
      await resolveNotificationDestination(
        { type: 'assignment.invited', ids: { assignment_ulid: ASSIGNMENT } },
        deps({ hasRoute: () => false }),
      ),
    ).toEqual({ kind: 'fallback' })
  })

  describe('relationship threads — resolved by LOOKUP', () => {
    it('creator side: thread_ulid → the agency ULID → creator.messages.thread', async () => {
      const lookupThread = vi.fn().mockResolvedValue(AGENCY)

      expect(
        await resolveNotificationDestination(
          { type: 'message.relationship_received_by_creator', ids: { thread_ulid: THREAD } },
          deps({ lookupThread }),
        ),
      ).toEqual({
        kind: 'route',
        to: { name: 'creator.messages.thread', params: { agencyUlid: AGENCY } },
      })
      expect(lookupThread).toHaveBeenCalledWith('creator', THREAD)
    })

    it('agency side: thread_ulid → the creator ULID → messages.thread', async () => {
      const lookupThread = vi.fn().mockResolvedValue(CREATOR)

      expect(
        await resolveNotificationDestination(
          { type: 'message.relationship_received_by_agency', ids: { thread_ulid: THREAD } },
          deps({ lookupThread }),
        ),
      ).toEqual({
        kind: 'route',
        to: { name: 'messages.thread', params: { creatorUlid: CREATOR } },
      })
      expect(lookupThread).toHaveBeenCalledWith('agency', THREAD)
    })

    it('the thread is not in the inbox (gone / not theirs) → the fallback', async () => {
      expect(
        await resolveNotificationDestination(
          { type: 'message.relationship_received_by_creator', ids: { thread_ulid: THREAD } },
          deps({ lookupThread: vi.fn().mockResolvedValue(null) }),
        ),
      ).toEqual({ kind: 'fallback' })
    })

    it('a lookup that returns a non-ULID (a malformed counterparty) → the fallback', async () => {
      expect(
        await resolveNotificationDestination(
          { type: 'message.relationship_received_by_creator', ids: { thread_ulid: THREAD } },
          deps({ lookupThread: vi.fn().mockResolvedValue('not-a-ulid') }),
        ),
      ).toEqual({ kind: 'fallback' })
    })

    it('a lookup that REJECTS → the fallback; the resolver never rejects', async () => {
      await expect(
        resolveNotificationDestination(
          { type: 'message.relationship_received_by_creator', ids: { thread_ulid: THREAD } },
          deps({ lookupThread: vi.fn().mockRejectedValue(new Error('offline')) }),
        ),
      ).resolves.toEqual({ kind: 'fallback' })
    })

    it('P-Q2: NO session → no lookup is made, and the result is the fallback (the named degradation)', async () => {
      const lookupThread = vi.fn()

      expect(
        await resolveNotificationDestination(
          { type: 'message.relationship_received_by_creator', ids: { thread_ulid: THREAD } },
          deps({ hasSession: () => false, lookupThread }),
        ),
      ).toEqual({ kind: 'fallback' })
      expect(lookupThread).not.toHaveBeenCalled()
    })

    it('no thread_ulid on the tap → the fallback, no lookup', async () => {
      const lookupThread = vi.fn()

      expect(
        await resolveNotificationDestination(
          { type: 'message.relationship_received_by_creator', ids: {} },
          deps({ lookupThread }),
        ),
      ).toEqual({ kind: 'fallback' })
      expect(lookupThread).not.toHaveBeenCalled()
    })
  })

  it('a route destination makes no lookup and needs no session (requireAuth redirects back to it after sign-in)', async () => {
    const lookupThread = vi.fn()

    expect(
      await resolveNotificationDestination(
        { type: 'assignment.invited', ids: { assignment_ulid: ASSIGNMENT } },
        deps({ hasSession: () => false, lookupThread }),
      ),
    ).toMatchObject({ kind: 'route' })
    expect(lookupThread).not.toHaveBeenCalled()
  })

  it('end to end from an observed device payload', async () => {
    const parsed = parsePushTapData(observedAndroid({ type: 'assignment.invited' }))
    expect(parsed).not.toBeNull()

    expect(
      await resolveNotificationDestination({ type: parsed!.type, ids: parsed!.ids }, deps()),
    ).toEqual({
      kind: 'route',
      to: { name: 'creator.assignment.detail', params: { ulid: ASSIGNMENT } },
    })
  })
})
