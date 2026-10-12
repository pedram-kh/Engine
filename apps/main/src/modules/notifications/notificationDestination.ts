/**
 * Where a notification takes you (AH-103) — two pure steps shared by the push tap
 * and the in-app row click, so the two entry points can never disagree.
 *
 *   1. {@link parsePushTapData}   — what the OS handed the app → `{type, ids}`.
 *   2. {@link resolveNotificationDestination} — `{type, ids}` → a route, or the
 *      fallback (the notification list).
 *
 * Neither ever throws and the resolver never rejects: a tap that cannot be
 * resolved lands on the list, which is the pre-AH-103 behaviour for EVERY tap.
 *
 * The parser is built on the payloads OBSERVED on a real iPhone and a real
 * Android phone in the AH-103 device spike (inventory §9), not on assumption.
 * What the spike showed, and what each rule below answers:
 *   - the tap event carries ONLY `notification.data` (no title/body on Android);
 *   - `data` is `unknown` and ALSO holds vendor keys we did not send — numbers
 *     (`google.ttl`, `google.sent_time`), a nested object (`aps`) and a stringified
 *     Bundle (`gcm.n.analytics_data`) — so the parser reads named keys only;
 *   - FCM stringifies every value, so `v` arrives as `"1"`, never `1`.
 */

import { notificationDestination, type NotificationDestination, type RouteIdKey } from './templates'

/** A ULID: 26 Crockford-base32 characters. Case-insensitive; the API writes uppercase. */
const ULID_SHAPE = /^[0-9A-HJKMNP-TV-Z]{26}$/i

/** The payload version this build understands. Higher is still read (additive contract). */
export const KNOWN_PUSH_PAYLOAD_VERSION = 1

const ROUTE_ID_KEYS: readonly RouteIdKey[] = ['assignment_ulid', 'campaign_ulid', 'thread_ulid']

export function isUlid(value: unknown): value is string {
  return typeof value === 'string' && ULID_SHAPE.test(value)
}

export interface ParsedPushTap {
  type: string
  /** The in-app row to mark read after landing; absent when the in-app row is muted. */
  notificationUlid?: string
  /** Only ULID-shaped values of the three route keys. */
  ids: Partial<Record<RouteIdKey, string>>
  /** The payload version (absent / unparseable → 1). Informational: it never blocks routing. */
  version: number
}

/**
 * Parse `event.notification.data` from a push tap. Returns `null` when there is
 * no usable `type` (the caller falls back to the list).
 */
export function parsePushTapData(data: unknown): ParsedPushTap | null {
  if (typeof data !== 'object' || data === null || Array.isArray(data)) {
    return null
  }

  const bag = data as Record<string, unknown>
  const type = bag.type

  if (typeof type !== 'string' || type === '') {
    return null
  }

  const ids: Partial<Record<RouteIdKey, string>> = {}
  for (const key of ROUTE_ID_KEYS) {
    const value = bag[key]
    if (isUlid(value)) {
      ids[key] = value
    }
  }

  const parsed: ParsedPushTap = { type, ids, version: parseVersion(bag.v) }

  if (isUlid(bag.notification_ulid)) {
    parsed.notificationUlid = bag.notification_ulid
  }

  return parsed
}

/** `"1"` (what FCM delivers), `1`, absent or garbage → a positive integer, default 1. */
function parseVersion(raw: unknown): number {
  const n = typeof raw === 'string' || typeof raw === 'number' ? Number(raw) : Number.NaN

  return Number.isInteger(n) && n >= 1 ? n : KNOWN_PUSH_PAYLOAD_VERSION
}

/** What a resolver is given — the same shape from a push tap and from a row. */
export interface DestinationInput {
  type: string
  ids: Partial<Record<RouteIdKey, string>>
  /** An in-app row's polymorphic subject (class basename + ULID); never on a push. */
  subject?: { type: string; ulid: string | null } | null
}

export interface DestinationRoute {
  name: string
  params?: Record<string, string>
  query?: Record<string, string>
}

export type Resolution = { kind: 'route'; to: DestinationRoute } | { kind: 'fallback' }

export interface DestinationDeps {
  /** `router.hasRoute` — a renamed/removed route resolves to the fallback, never a dead end. */
  hasRoute: (name: string) => boolean
  /** True when a user session exists (a thread lookup is an authenticated call). */
  hasSession: () => boolean
  /**
   * Resolve a relationship thread to the COUNTERPARTY's id, by lookup in the
   * recipient's inbox: creator side → the agency ULID, agency side → the creator
   * ULID. `null` when the thread is not there (gone / not theirs).
   */
  lookupThread: (side: 'creator' | 'agency', threadUlid: string) => Promise<string | null>
}

const FALLBACK: Resolution = { kind: 'fallback' }

export async function resolveNotificationDestination(
  input: DestinationInput,
  deps: DestinationDeps,
): Promise<Resolution> {
  try {
    const destination = notificationDestination(input.type)

    if (destination === null) {
      return FALLBACK
    }

    return await resolveDestination(destination, input, deps)
  } catch {
    // A throwing lookup, a bad router — whatever it is, the user still lands somewhere sane.
    return FALLBACK
  }
}

async function resolveDestination(
  destination: NotificationDestination,
  input: DestinationInput,
  deps: DestinationDeps,
): Promise<Resolution> {
  switch (destination.kind) {
    case 'list':
      return FALLBACK

    case 'thread': {
      const threadUlid = input.ids.thread_ulid

      // No session → no lookup (it would 401). `requireAuth` then sends the user to
      // sign in and back to the LIST — the accepted, named degradation (plan P-Q2).
      if (threadUlid === undefined || !deps.hasSession()) {
        return FALLBACK
      }

      const counterparty = await deps.lookupThread(destination.side, threadUlid)

      if (!isUlid(counterparty)) {
        return FALLBACK
      }

      return withRoute(
        destination.side === 'creator'
          ? { name: 'creator.messages.thread', params: { agencyUlid: counterparty } }
          : { name: 'messages.thread', params: { creatorUlid: counterparty } },
        deps,
      )
    }

    case 'route': {
      const params: Record<string, string> = {}

      for (const [param, idKey] of Object.entries(destination.params ?? {})) {
        const fromIds = input.ids[idKey]
        const fromSubject =
          destination.subjectFallback?.param === param &&
          input.subject?.type === destination.subjectFallback.type
            ? input.subject.ulid
            : null
        const value = fromIds ?? (isUlid(fromSubject) ? fromSubject : undefined)

        if (value === undefined) {
          return FALLBACK
        }

        params[param] = value
      }

      return withRoute(
        {
          name: destination.name,
          ...(Object.keys(params).length > 0 ? { params } : {}),
          ...(destination.query !== undefined ? { query: destination.query } : {}),
        },
        deps,
      )
    }
  }
}

function withRoute(to: DestinationRoute, deps: DestinationDeps): Resolution {
  return deps.hasRoute(to.name) ? { kind: 'route', to } : FALLBACK
}

/**
 * The route ids out of an in-app row's `data` bag (the row-click twin of
 * {@link parsePushTapData}). Same rule: only the three named keys, only
 * ULID-shaped values — the bag also carries free text (names, feedback) that
 * must never be mistaken for an id.
 */
export function idsFromRow(
  data: Record<string, unknown> | null | undefined,
): Partial<Record<RouteIdKey, string>> {
  const ids: Partial<Record<RouteIdKey, string>> = {}

  for (const key of ROUTE_ID_KEYS) {
    const value = data?.[key]
    if (isUlid(value)) {
      ids[key] = value
    }
  }

  return ids
}
