/**
 * Typed wrappers for the AH-010 relationship-messaging surfaces — the 1:1
 * connected agency↔creator DM, parallel to the campaign messaging transports in
 * `messaging.api.ts`.
 *
 * Two surfaces onto the same per-pair thread (Q5, symmetric):
 *   - AGENCY:  /agencies/{agency}/creators/{creator}/relationship-messages
 *   - CREATOR: /creators/me/relationship-threads/{agency}/messages
 *
 * Mirrors the campaign `ChatTransport` shape so the same WhatsApp thread view
 * (driven by the generic {@link useMessageThread}) backs both mounts: a parent
 * builds a {@link RelationshipChatTransport} with its ids pre-bound, and the
 * view just calls the transport.
 */

import type {
  AgencyRelationshipInboxEnvelope,
  CreatorRelationshipInboxEnvelope,
  MessageAttachmentCompleteEnvelope,
  MessageAttachmentInitEnvelope,
  MessageAttachmentInitPayload,
  MessageableAgenciesEnvelope,
  MessageableCreatorsEnvelope,
  MessageMarkReadEnvelope,
  RelationshipMessageEnvelope,
  RelationshipMessageFeedEnvelope,
  SendRelationshipMessagePayload,
} from '@catalyst/api-client'

import { http } from '@/core/api'

/** The surface-agnostic contract the relationship thread view consumes. */
export interface RelationshipChatTransport {
  list(before?: string): Promise<RelationshipMessageFeedEnvelope>
  send(payload: SendRelationshipMessagePayload): Promise<RelationshipMessageEnvelope>
  markRead(): Promise<MessageMarkReadEnvelope>
  attachmentInit(payload: MessageAttachmentInitPayload): Promise<MessageAttachmentInitEnvelope>
  attachmentComplete(uploadId: string): Promise<MessageAttachmentCompleteEnvelope>
}

function listUrl(base: string, before?: string): string {
  if (before === undefined || before === '') {
    return base
  }
  return `${base}?before=${encodeURIComponent(before)}`
}

function agencyBase(agencyId: string, creatorUlid: string): string {
  return `/agencies/${agencyId}/creators/${creatorUlid}/relationship-messages`
}

function creatorBase(agencyUlid: string): string {
  return `/creators/me/relationship-threads/${agencyUlid}/messages`
}

function buildTransport(base: string): RelationshipChatTransport {
  return {
    list: (before) => http.get<RelationshipMessageFeedEnvelope>(listUrl(base, before)),
    send: (payload) => http.post<RelationshipMessageEnvelope>(base, payload),
    markRead: () => http.post<MessageMarkReadEnvelope>(`${base}/read`),
    attachmentInit: (payload) =>
      http.post<MessageAttachmentInitEnvelope>(`${base}/attachments/init`, payload),
    attachmentComplete: (uploadId) =>
      http.post<MessageAttachmentCompleteEnvelope>(`${base}/attachments/complete`, {
        upload_id: uploadId,
      }),
  }
}

/** Agency side: a thread keyed by the creator's ULID. */
export function agencyRelationshipTransport(
  agencyId: string,
  creatorUlid: string,
): RelationshipChatTransport {
  return buildTransport(agencyBase(agencyId, creatorUlid))
}

/** Creator side: a thread keyed by the agency's ULID. */
export function creatorRelationshipTransport(agencyUlid: string): RelationshipChatTransport {
  return buildTransport(creatorBase(agencyUlid))
}

export const relationshipMessagingApi = {
  /** GET /agencies/{agency}/relationship-threads — the agency conversations inbox. */
  agencyInbox(agencyId: string): Promise<AgencyRelationshipInboxEnvelope> {
    return http.get<AgencyRelationshipInboxEnvelope>(`/agencies/${agencyId}/relationship-threads`)
  },

  /** GET /creators/me/relationship-threads — the creator conversations inbox. */
  creatorInbox(): Promise<CreatorRelationshipInboxEnvelope> {
    return http.get<CreatorRelationshipInboxEnvelope>('/creators/me/relationship-threads')
  },

  /**
   * GET /agencies/{agency}/messageable-creators — the agency contact picker
   * (AH-012). Gate-filtered, paginated, optional name search (D6).
   */
  messageableCreators(
    agencyId: string,
    params: { search?: string; page?: number; perPage?: number } = {},
  ): Promise<MessageableCreatorsEnvelope> {
    const query = new URLSearchParams()
    if (params.search !== undefined && params.search !== '') {
      query.set('search', params.search)
    }
    if (params.page !== undefined) {
      query.set('page', String(params.page))
    }
    if (params.perPage !== undefined) {
      query.set('per_page', String(params.perPage))
    }
    const qs = query.toString()
    return http.get<MessageableCreatorsEnvelope>(
      `/agencies/${agencyId}/messageable-creators${qs === '' ? '' : `?${qs}`}`,
    )
  },

  /**
   * GET /creators/me/messageable-agencies — the creator contact picker
   * (AH-012). Gate-filtered, small + unpaginated (D6).
   */
  messageableAgencies(): Promise<MessageableAgenciesEnvelope> {
    return http.get<MessageableAgenciesEnvelope>('/creators/me/messageable-agencies')
  },
}
