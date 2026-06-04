/**
 * Wire-contract types for the agency-scoped API surfaces introduced in
 * Sprint 2 Chunk 2. These mirror the backend JSON:API-flavoured resource
 * shapes verbatim (snake_case, ISO 8601 dates, ULID identifiers).
 *
 * Conventions follow `packages/api-client/src/types/user.ts`:
 *   - All keys snake_case matching backend `toArray()` output.
 *   - No re-casing; drift between FE and BE is loud and immediate.
 *   - Timestamps are `string` (ISO 8601 from Carbon).
 */

import type { AvailabilityBlockType, AvailabilityKind } from './availability'
import type {
  CreatorApplicationStatus,
  CreatorPortfolioItemSummary,
  CreatorSocialAccountSummary,
} from './creator'

// ---------------------------------------------------------------------------
// Shared
// ---------------------------------------------------------------------------

/**
 * Standard Laravel pagination meta block included in every `::collection()`
 * response that is paginated.
 */
export interface PaginationMeta {
  current_page: number
  from: number | null
  last_page: number
  per_page: number
  to: number | null
  total: number
}

/**
 * Standard pagination links block.
 */
export interface PaginationLinks {
  first: string | null
  last: string | null
  prev: string | null
  next: string | null
}

/**
 * Generic paginated collection envelope returned by
 * `JsonResource::collection()` with a paginator.
 */
export interface PaginatedCollection<T> {
  data: T[]
  links: PaginationLinks
  meta: PaginationMeta
}

// ---------------------------------------------------------------------------
// Brands
// ---------------------------------------------------------------------------

export type BrandStatus = 'active' | 'archived'

export interface BrandAttributes {
  name: string
  slug: string | null
  description: string | null
  industry: string | null
  website_url: string | null
  logo_path: string | null
  default_currency: string | null
  default_language: string | null
  status: BrandStatus
  brand_safety_rules: unknown | null
  client_portal_enabled: boolean
  created_at: string
  updated_at: string
}

export interface BrandResource {
  id: string
  type: 'brands'
  attributes: BrandAttributes
  relationships: {
    agency: {
      data: {
        id: string
        type: 'agencies'
      }
    }
  }
}

export interface CreateBrandPayload {
  name: string
  slug?: string
  description?: string
  industry?: string
  website_url?: string
  default_currency?: string
  default_language?: string
}

export type UpdateBrandPayload = Partial<CreateBrandPayload>

// ---------------------------------------------------------------------------
// Talent pools — Sprint 6 Chunk 2b
// ---------------------------------------------------------------------------

export interface TalentPoolAttributes {
  name: string
  description: string | null
  /** Brand-scope LABEL (D-2b-4) — the brand's ULID + name, or null (agency-wide). */
  brand_id: string | null
  brand_name: string | null
  /** Derived from deleted_at — pools have no status column (D-2b-1). */
  is_archived: boolean
  /** withCount('creators') — the list shows COUNTS, not member previews (D-2b-7). */
  creators_count: number
  created_at: string
  updated_at: string
}

export interface TalentPoolResource {
  id: string
  type: 'talent_pools'
  attributes: TalentPoolAttributes
}

export interface CreateTalentPoolPayload {
  name: string
  description?: string | null
  /** Optional brand ULID to label the pool (D-2b-4). */
  brand_id?: string | null
}

export type UpdateTalentPoolPayload = Partial<CreateTalentPoolPayload>

/**
 * One row in the add-to-pool picker dialog (D-2b-9): the pool + an `is_member`
 * flag for the creator the dialog was opened for. Computed server-side in one
 * query (no N+1 across pools).
 */
export interface TalentPoolPickerItem {
  id: string
  type: 'talent_pools'
  attributes: {
    name: string
    brand_name: string | null
    is_member: boolean
  }
}

export interface TalentPoolPickerResponse {
  data: TalentPoolPickerItem[]
}

/**
 * One member row on the pool DETAIL page. A slim creator shape + the signed
 * avatar URL + the `added_at` pivot timestamp. Paginated server-side so the
 * signed-avatar minting is bounded (the D-2b-7 list/detail boundary).
 */
export interface TalentPoolMemberResource {
  id: string
  type: 'talent_pool_members'
  attributes: {
    display_name: string | null
    country_code: string | null
    primary_language: string | null
    categories: string[] | null
    avatar_url: string | null
    application_status: CreatorApplicationStatus
    /** This (pool-owning) agency's own blacklist of the member, scoped to the
     * pool's agency (D-3/D-4). Surfaces the warn-don't-remove footgun: a
     * blacklisted creator stays a member but is flagged. */
    is_blacklisted: boolean
    /** `hard` | `soft` when blacklisted; null otherwise — the roster-list
     * subset (status + type, NOT the reason). */
    blacklist_type: BlacklistType | null
    added_at: string | null
  }
}

// ---------------------------------------------------------------------------
// Agency creator roster ("my creators") — Sprint 4 Chunk 5
// ---------------------------------------------------------------------------

/**
 * Mirrors `App\Modules\Creators\Enums\RelationshipStatus` — the per-agency
 * view of a creator's status. This is the LOAD-BEARING FE consumer of the
 * enum: every status chip + the discovery annotation derives from it, so a
 * missing value silently mistypes a status (the 21-consumer ripple, D-3).
 *
 * Sprint 6.6b adds the two-sided lifecycle values:
 *   - `pending_request` — agency sent a discovery request, creator not yet
 *     accepted (no magic-link token; excluded from the default roster index
 *     but filterable by chip, D-6).
 *   - `declined`        — creator declined; row retained so the agency can
 *     re-request (D-1/D-4).
 */
export type RosterRelationshipStatus =
  | 'roster'
  | 'prospect'
  | 'external'
  | 'pending_request'
  | 'declined'

/**
 * A single row in the agency roster list
 * (GET /api/v1/agencies/{agency}/creators). Slim hand-rolled shape
 * (D-c5-5) — NOT the heavy `CreatorResource`. Carries `internal_rating`
 * read-only; deliberately omits `internal_notes` (GDPR-sensitive) and any
 * signed media URLs.
 */
export interface RosterCreatorListItem {
  id: string
  type: 'agency_creator_relations'
  attributes: {
    relationship_status: RosterRelationshipStatus
    is_blacklisted: boolean
    /** `hard` | `soft` when blacklisted; null otherwise — lets the list show a
     * hard exclusion distinctly from a soft warning (same axis as the detail). */
    blacklist_type: BlacklistType | null
    /** 1–5 stars from the agency's POV; null when unset. Read-only this chunk. */
    internal_rating: number | null
    total_campaigns_completed: number
    total_paid_minor_units: number
    last_engaged_at: string | null
    /** Creator ULID — reserved for Sprint 6 click-through; rows do NOT navigate yet. */
    creator_id: string | null
    display_name: string | null
    /**
     * Creator application lifecycle state (Chunk 5b). Display-only — a
     * distinct axis from `relationship_status` so the agency can tell an
     * approved/usable creator from one still pending/incomplete/rejected.
     * Not filterable this chunk.
     */
    application_status: CreatorApplicationStatus
    country_code: string | null
    primary_language: string | null
    categories: string[] | null
  }
}

/**
 * Hand-rolled `{data, meta}` envelope returned by the roster index. Mirrors
 * the admin review-queue shape (not the standard `PaginatedCollection`).
 */
export interface RosterListResponse {
  data: RosterCreatorListItem[]
  meta: {
    total: number
    page: number
    per_page: number
    last_page: number
  }
}

export interface RosterListParams {
  status?: RosterRelationshipStatus
  country?: string
  language?: string
  category?: string
  /**
   * Free-text name/bio search (Sprint 6 Chunk 1). Threaded to the backend's
   * `?q=` FTS filter (Postgres `tsvector`; SQLite `LIKE` fallback).
   */
  q?: string
  /**
   * Availability range filter (Sprint 6.5, D-6). A `'YYYY-MM-DD'` window;
   * creators with an overlapping HARD availability block in [from, to] are
   * excluded (soft blocks never exclude). Both bounds are required to
   * activate the filter — a one-sided range is ignored by the backend. The
   * window is day-granular + inclusive of the `to` day (normalized
   * server-side).
   */
  available_from?: string
  available_to?: string
  page?: number
  per_page?: number
}

// ---------------------------------------------------------------------------
// Agency creator DETAIL view — Sprint 6 Chunk 2a
// ---------------------------------------------------------------------------

/**
 * The nested creator-profile block on the detail resource. Composes the same
 * SPA-agnostic display fields the wizard/admin surfaces use (display_name,
 * bio, country, languages, categories, signed avatar/cover URLs, social
 * ACCOUNTS, portfolio) PLUS the contact `email` — surfaced here as a
 * deliberate privacy decision (D-2a-8): the agency holds a verified relation
 * with this creator, so the contact email belongs on the single-creator
 * detail view (the slim roster LIST omitted it for N+1 reasons).
 *
 * Social follower/engagement METRICS are NOT here (blocked-on-data — the page
 * renders an empty state, D-2a-10). Admin-only KYC PII is never present.
 */
export interface AgencyCreatorDetailProfile {
  id: string
  display_name: string | null
  bio: string | null
  /** Contact email (D-2a-8). Null only if the related user has none. */
  email: string | null
  country_code: string | null
  region: string | null
  primary_language: string | null
  secondary_languages: string[] | null
  categories: string[] | null
  /** Signed view URL for the private `media` disk; null when unset / non-S3. */
  avatar_url: string | null
  cover_url: string | null
  application_status: CreatorApplicationStatus
  social_accounts: CreatorSocialAccountSummary[]
  portfolio: CreatorPortfolioItemSummary[]
}

/**
 * `GET /api/v1/agencies/{agency}/creators/{creator}` (D-2a-2). A dedicated
 * shape composing the RELATION block (the agency's private view — rating,
 * notes, read-only blacklist STATUS, counters) + the nested creator profile.
 *
 * `blacklist_reason` is deliberately absent (free-text GDPR-sensitive, the
 * same data class as `internal_notes` which the backend redacts from the
 * audit log). Only the structured blacklist facts ship. Blacklist EDITING is
 * Sprint 7 — the flag/scope/type/date are display-only here.
 */
export interface AgencyCreatorDetailResource {
  id: string
  type: 'agency_creator_details'
  attributes: {
    relationship_status: RosterRelationshipStatus
    /** 1–5 from the agency's POV; null when unset. Editable (admin/manager). */
    internal_rating: number | null
    /** Free-text private notes. Editable (admin/manager); audit-redacted. */
    internal_notes: string | null
    total_campaigns_completed: number
    total_paid_minor_units: number
    last_engaged_at: string | null
    is_blacklisted: boolean
    blacklist_scope: string | null
    blacklist_type: BlacklistType | null
    blacklisted_at: string | null
    creator: AgencyCreatorDetailProfile | null
  }
}

export interface AgencyCreatorDetailEnvelope {
  data: AgencyCreatorDetailResource
}

/**
 * PATCH payload for the rating/notes edit (D-2a-3). ONLY these two fields are
 * editable; the backend ignores anything else. Both optional so a partial
 * edit (rating only / notes only) is valid. `internal_rating: null` clears.
 */
export interface UpdateAgencyCreatorRelationPayload {
  internal_rating?: number | null
  internal_notes?: string | null
}

// ---------------------------------------------------------------------------
// Creator blacklisting — Sprint 7
// ---------------------------------------------------------------------------

/** agency-wide (columns on the relation) | brand-scoped (its own table). */
export type BlacklistScope = 'agency' | 'brand'

/** hard = exclude (discovery + send gate) | soft = warn only. */
export type BlacklistType = 'hard' | 'soft'

/**
 * POST /api/v1/agencies/{agency}/creators/{creator}/blacklist.
 *
 * `reason` is mandatory (you only ever blacklist WITH a reason). `brand_id`
 * (the brand ULID) is required when `scope === 'brand'` and must be omitted
 * for `scope === 'agency'`.
 */
export interface BlacklistCreatorPayload {
  scope: BlacklistScope
  type: BlacklistType
  reason: string
  brand_id?: string
}

/** DELETE …/blacklist — lift an agency-wide or brand-scoped blacklist. */
export interface UnblacklistCreatorPayload {
  scope: BlacklistScope
  brand_id?: string
}

export interface CreatorBlacklistResource {
  type: 'creator_blacklist'
  attributes: {
    scope?: BlacklistScope
    type?: BlacklistType
    is_blacklisted?: boolean
    brand_id?: string
    id?: string
  }
}

export interface CreatorBlacklistEnvelope {
  data: CreatorBlacklistResource
  meta: { code: string }
}

// ---------------------------------------------------------------------------
// Creator DISCOVERY (the global pool) — Sprint 6.6a
// ---------------------------------------------------------------------------

/**
 * The "already-connected" annotation surfaced on every discovery shape (D-4).
 * It is the CALLING agency's OWN relationship status with the creator, or
 * `null` when there is no relation. ⚠ It is NEVER any OTHER agency's status —
 * the cross-agency isolation invariant (D-7) the backend enforces by scoping
 * the annotation subquery to the calling agency.
 */
export type DiscoveryRelationshipStatus = RosterRelationshipStatus

/**
 * One CARD in the discovery grid
 * (GET /api/v1/agencies/{agency}/creators/discover, D-5/D-8). The PUBLIC
 * creator facts + a single signed avatar (D-10: bounded per page) + the
 * calling-agency-only connection annotation. It carries NONE of the per-agency
 * relation block (no internal_notes/rating, no blacklist, no counters, no
 * email) — that is the privacy delta this shape exists to enforce (D-5/D-7).
 */
export interface DiscoveryCreatorListItem {
  id: string
  type: 'creator_discovery'
  attributes: {
    display_name: string | null
    country_code: string | null
    primary_language: string | null
    categories: string[] | null
    /** Single signed avatar URL; null when unset / non-S3. */
    avatar_url: string | null
    /**
     * The CALLING agency's own status (never another agency's), or null when
     * no relation exists. Sprint 6.6b (D-5): the boolean `is_connected` was
     * REMOVED — it conflated `roster` with `pending_request`/`declined`. The FE
     * derives the three annotation states (connected / pending / declined /
     * none) from this status alone via `deriveConnectionState`.
     */
    relationship_status: DiscoveryRelationshipStatus | null
  }
}

/**
 * Hand-rolled `{data, meta}` envelope from the discovery index — mirrors the
 * roster list shape (not the standard `PaginatedCollection`).
 */
export interface DiscoveryListResponse {
  data: DiscoveryCreatorListItem[]
  meta: {
    total: number
    page: number
    per_page: number
    last_page: number
  }
}

export interface DiscoveryListParams {
  country?: string
  language?: string
  category?: string
  /** Free-text name/bio search — the shared `?q=` FTS (Postgres tsvector; SQLite LIKE). */
  q?: string
  page?: number
  per_page?: number
}

/**
 * `GET /api/v1/agencies/{agency}/creators/discover/{creator}` (D-5/D-6). The
 * PUBLIC creator profile — a THIRD creator shape, distinct from the slim
 * roster row and the relation-gated 2a detail. It does NOT 404 on no-relation
 * (D-6). It carries the public profile (bio, region, languages, categories,
 * signed avatar/cover, social ACCOUNTS, portfolio, completeness) + the
 * calling-agency-only connection annotation, and WITHHOLDS the entire relation
 * block, the contact email, and admin KYC PII (D-5/D-7).
 */
export interface CreatorPublicProfile {
  id: string
  type: 'creator_public_profiles'
  attributes: {
    display_name: string | null
    bio: string | null
    country_code: string | null
    region: string | null
    primary_language: string | null
    secondary_languages: string[] | null
    categories: string[] | null
    avatar_url: string | null
    cover_url: string | null
    profile_completeness_score: number
    social_accounts: CreatorSocialAccountSummary[]
    portfolio: CreatorPortfolioItemSummary[]
    /**
     * The CALLING agency's own status (never another agency's), or null. The
     * boolean `is_connected` was removed in Sprint 6.6b (D-5); the FE derives
     * the status-driven send-request button + the three annotation states from
     * this alone.
     */
    relationship_status: DiscoveryRelationshipStatus | null
  }
}

export interface CreatorPublicProfileEnvelope {
  data: CreatorPublicProfile
}

/**
 * The three-state connection distinction the discovery surfaces render
 * (Sprint 6.6b, D-5/D-10/D-11), derived from `relationship_status`:
 *
 *   - `connected`  → `roster` (the "View in roster" affordance keys on THIS,
 *                    not "has any relation").
 *   - `pending`    → `pending_request` (request sent, awaiting the creator).
 *   - `declined`   → `declined` (creator declined; offer an explicit
 *                    "Request again", D-4).
 *   - `none`       → no relation / prospect / external — "Send request".
 *
 * ⚠ `prospect`/`external` fall through to `none` for the discovery SEND
 * affordance: discovery is the cold-outreach surface, and those statuses have
 * no send/accept semantics here.
 */
export type DiscoveryConnectionState = 'connected' | 'pending' | 'declined' | 'none'

export function deriveConnectionState(
  status: DiscoveryRelationshipStatus | null,
): DiscoveryConnectionState {
  switch (status) {
    case 'roster':
      return 'connected'
    case 'pending_request':
      return 'pending'
    case 'declined':
      return 'declined'
    default:
      return 'none'
  }
}

/**
 * Response envelope from the agency send-connection-request endpoint
 * (Sprint 6.6b, D-7):
 *   POST /agencies/{agency}/creators/discover/{creator}/connection-request
 *
 * Carries the resulting relationship_status (so the FE re-derives the button
 * state) + a `meta.code` describing the outcome (requested / re_requested /
 * already_requested / already_connected).
 */
export interface ConnectionRequestResponse {
  data: {
    id: string
    type: 'agency_connection_request'
    attributes: {
      relationship_status: DiscoveryRelationshipStatus
    }
  }
  meta: {
    code:
      | 'connection.requested'
      | 'connection.re_requested'
      | 'connection.already_requested'
      | 'connection.already_connected'
  }
}

// ---------------------------------------------------------------------------
// Creator-side connection requests (the inbox) — Sprint 6.6c
// ---------------------------------------------------------------------------

/**
 * One row in the creator's incoming-request inbox
 * (GET /api/v1/creators/me/connection-requests, Sprint 6.6c / D-d2). The
 * CREATOR side of the lifecycle — distinct from the agency send-side
 * `ConnectionRequestResponse` (`type: 'agency_connection_request'`), which it
 * MUST NOT be confused with (D-d5).
 *
 * ⚠ `id` is the RELATION ULID — the value POSTed back to accept/decline
 *   (`POST …/{relation}/accept|decline`), NOT the agency's id.
 * ⚠ `agency_id` is the agency's ULID despite the `_id` suffix — bind it as the
 *   agency identifier, never as a numeric key (D-d2 quirk). `agency_name` is
 *   the only human-readable label on the row.
 *
 * `relationship_status` is always `'pending_request'` here (the list filters
 * to pending), reusing the shared `DiscoveryRelationshipStatus` (D-d5).
 */
export interface ConnectionRequestListItem {
  id: string
  type: 'connection_request'
  attributes: {
    relationship_status: DiscoveryRelationshipStatus
    /** ISO 8601 instant the agency sent the request; nullable. */
    invitation_sent_at: string | null
    /** The agency's ULID (NOT a numeric id, despite the `_id` suffix — D-d2). */
    agency_id: string
    agency_name: string
  }
}

/**
 * The creator inbox list envelope (D-d2). A FLAT `data: [...]` — there is NO
 * `meta`/pagination here (the 6.6b list is un-paginated by design; do NOT
 * expect an availability-style `meta.window`).
 */
export interface ConnectionRequestListResponse {
  data: ConnectionRequestListItem[]
}

/**
 * Response from the creator accept/decline endpoints (D-d3):
 *   POST /api/v1/creators/me/connection-requests/{relation}/accept|decline
 *
 * Carries the resulting `relationship_status` (`roster` on accept, `declined`
 * on decline) + a `meta.code` the UI keys its snackbar on (D-d6). The code
 * union is the CREATOR side (`accepted`/`declined`) — NOT the agency send-side
 * union on `ConnectionRequestResponse` (D-d5).
 */
export interface ConnectionRequestActionResponse {
  data: {
    id: string
    type: 'connection_request'
    attributes: {
      relationship_status: DiscoveryRelationshipStatus
    }
  }
  meta: {
    code: 'connection.accepted' | 'connection.declined'
  }
}

// ---------------------------------------------------------------------------
// Agency creator AVAILABILITY read-view — Sprint 5 Chunk A backend,
// Sprint 6 Chunk 2a consumer
// ---------------------------------------------------------------------------

/**
 * One expanded availability occurrence as seen by an AGENCY. A DEDICATED type
 * mirroring the backend's dedicated `AgencyAvailabilityResource` — which omits
 * `reason` precisely so the creator-only note can never leak through a shared
 * shape. This is the FE counterpart of that discipline: we do NOT loosen the
 * creator-self `AvailabilityOccurrenceAttributes.reason` to optional (which
 * would weaken the creator path's guarantee), we mirror the backend's
 * separate-shape approach instead.
 *
 * `reason` is structurally ABSENT here — not optional, ABSENT.
 */
export interface AgencyAvailabilityOccurrenceAttributes {
  /** ISO 8601 with UTC offset. */
  starts_at: string
  /** ISO 8601 with UTC offset. */
  ends_at: string
  is_all_day: boolean
  block_type: AvailabilityBlockType
  kind: AvailabilityKind
  is_recurring: boolean
  /** RRULE body (weekly ceiling), or null for a one-off. */
  recurrence_rule: string | null
}

export interface AgencyAvailabilityOccurrenceResource {
  /** SOURCE BLOCK ULID — shared across every occurrence of a recurring block. */
  id: string
  type: 'availability_blocks'
  attributes: AgencyAvailabilityOccurrenceAttributes
}

/**
 * `GET /api/v1/agencies/{agency}/creators/{creator}/availability`. Mirrors the
 * creator-self list envelope (data + the effective `meta.window` after the
 * silent 366-day clamp), minus `reason` on each occurrence.
 */
export interface AgencyAvailabilityListResponse {
  data: AgencyAvailabilityOccurrenceResource[]
  meta: {
    window: {
      from: string
      to: string
    }
  }
}

// ---------------------------------------------------------------------------
// Agency invitations
// ---------------------------------------------------------------------------

export type AgencyRole = 'agency_admin' | 'agency_manager' | 'agency_staff'

export type AgencyInvitationStatus = 'pending' | 'accepted' | 'expired'

export interface AgencyInvitationAttributes {
  email: string
  role: AgencyRole
  expires_at: string
  accepted_at: string | null
  is_pending: boolean
  is_expired: boolean
  created_at: string
  /** Sprint 3 Chunk 4 — invitation history listing. */
  status: AgencyInvitationStatus
  /** Sprint 3 Chunk 4 — invitation history listing (alias of created_at). */
  invited_at: string
  /** Sprint 3 Chunk 4 — name of the agency_admin who created the invite. */
  invited_by_user_name: string | null
}

export interface AgencyInvitationResource {
  id: string
  type: 'agency_invitations'
  attributes: AgencyInvitationAttributes
  relationships: {
    agency: {
      data: {
        id: string
        type: 'agencies'
      }
    }
  }
}

export interface CreateInvitationPayload {
  email: string
  role: AgencyRole
}

// ---------------------------------------------------------------------------
// Agency memberships (Sprint 3 Chunk 4 — paginated members listing)
// ---------------------------------------------------------------------------

export type AgencyMembershipStatus = 'active' | 'pending'

export interface AgencyMembershipAttributes {
  user_id: string
  name: string
  email: string
  role: AgencyRole
  status: AgencyMembershipStatus
  created_at: string
  last_active_at: string | null
}

export interface AgencyMembershipResource {
  id: string
  type: 'agency_memberships'
  attributes: AgencyMembershipAttributes
}

/**
 * Response from the unauthenticated preview endpoint.
 * GET /api/v1/agencies/{agency}/invitations/preview?token=<unhashed>
 */
export interface InvitationPreview {
  agency_name: string
  role: AgencyRole
  is_expired: boolean
  is_accepted: boolean
  expires_at: string
}

export interface InvitationPreviewEnvelope {
  data: InvitationPreview
}

// ---------------------------------------------------------------------------
// Agency settings
// ---------------------------------------------------------------------------

export interface AgencySettingsAttributes {
  default_currency: string
  default_language: string
  /**
   * Whether creators are emailed when blacklisted (Sprint 7, D-4). The first
   * key surfaced from the `settings` jsonb. Default `false`.
   */
  blacklist_notification_policy: boolean
}

export interface AgencySettingsResource {
  id: string
  type: 'agency_settings'
  attributes: AgencySettingsAttributes
}

export interface AgencySettingsEnvelope {
  data: AgencySettingsResource
}

export interface UpdateAgencySettingsPayload {
  default_currency?: string
  default_language?: string
  blacklist_notification_policy?: boolean
}

// ---------------------------------------------------------------------------
// Agency members (for the agency users list page)
// ---------------------------------------------------------------------------

export interface AgencyMemberListItem {
  user_id: string
  name: string
  email: string
  role: AgencyRole
  accepted_at: string | null
  is_pending: boolean
}
