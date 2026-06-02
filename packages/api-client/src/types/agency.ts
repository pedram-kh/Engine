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
// Agency creator roster ("my creators") — Sprint 4 Chunk 5
// ---------------------------------------------------------------------------

/**
 * Mirrors `App\Modules\Creators\Enums\RelationshipStatus` — the per-agency
 * view of a creator's status. All three values appear in the roster list.
 */
export type RosterRelationshipStatus = 'roster' | 'prospect' | 'external'

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
    /** 1–5 stars from the agency's POV; null when unset. Read-only this chunk. */
    internal_rating: number | null
    total_campaigns_completed: number
    total_paid_minor_units: number
    last_engaged_at: string | null
    /** Creator ULID — reserved for Sprint 6 click-through; rows do NOT navigate yet. */
    creator_id: string | null
    display_name: string | null
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
  page?: number
  per_page?: number
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
