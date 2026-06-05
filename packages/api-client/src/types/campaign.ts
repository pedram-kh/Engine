/**
 * Wire-contract types for the campaigns surfaces (Sprint 8 Chunk 1). These
 * mirror the backend JSON:API-flavoured resource shapes verbatim (snake_case,
 * ISO 8601 dates, ULID identifiers, money as integer minor units).
 *
 * Conventions follow `packages/api-client/src/types/agency.ts`.
 */

// ---------------------------------------------------------------------------
// Enums (mirror App\Modules\Campaigns\Enums\*)
// ---------------------------------------------------------------------------

/** Mirror of `CampaignStatus` — a settable CRUD field (no state machine). */
export type CampaignStatus = 'draft' | 'active' | 'paused' | 'completed' | 'cancelled'

/** Mirror of `CampaignObjective`. */
export type CampaignObjective = 'awareness' | 'engagement' | 'conversion' | 'ugc' | 'launch'

/**
 * Mirror of `AssignmentStatus` — the 15-state campaign-assignment graph driven
 * by the backend `CampaignAssignmentStateMachine`. Terminal: declined,
 * rejected, payment_released, cancelled. Sprint 9 Chunk 2 adds the dedicated
 * `rejected` terminal (the agency rejects a submitted draft).
 */
export type AssignmentStatus =
  | 'invited'
  | 'declined'
  | 'countered'
  | 'accepted'
  | 'contracted'
  | 'producing'
  | 'draft_submitted'
  | 'revision_requested'
  | 'approved'
  | 'rejected'
  | 'posted'
  | 'live_verified'
  | 'payment_held'
  | 'payment_released'
  | 'cancelled'

// ---------------------------------------------------------------------------
// Campaign brief (structured jsonb blob — NOT normalized tables)
// ---------------------------------------------------------------------------

export interface CampaignBrief {
  deliverables?: string[]
  dos?: string[]
  donts?: string[]
  hashtags?: string[]
  mentions?: string[]
  links?: string[]
  usage_rights?: string | null
  attachments?: string[]
}

// ---------------------------------------------------------------------------
// Campaign resource
// ---------------------------------------------------------------------------

export interface CampaignAttributes {
  name: string
  description: string | null
  objective: CampaignObjective
  status: CampaignStatus
  /** Integer minor units (e.g. cents). The client formats with the currency. */
  budget_minor_units: number | null
  budget_currency: string | null
  starts_at: string | null
  ends_at: string | null
  posting_window_starts_at: string | null
  posting_window_ends_at: string | null
  brief: CampaignBrief | null
  target_creator_count: number | null
  requires_per_campaign_contract: boolean
  is_marketplace_visible: boolean
  published_at: string | null
  completed_at: string | null
  /** withCount('assignments') — number of creators engaged; null in some lists. */
  assignment_count: number | null
  created_at: string
  updated_at: string
}

export interface CampaignResource {
  id: string
  type: 'campaigns'
  attributes: CampaignAttributes
  relationships: {
    brand: {
      data: {
        id: string
        type: 'brands'
        name: string
      }
    }
    agency: {
      data: {
        id: string
        type: 'agencies'
      }
    }
  }
}

export interface CampaignEnvelope {
  data: CampaignResource
}

/**
 * Hand-rolled `{data, meta}` list envelope (mirrors the roster/discovery shape,
 * NOT the standard Laravel `PaginatedCollection`).
 */
export interface CampaignListResponse {
  data: CampaignResource[]
  meta: {
    total: number
    page: number
    per_page: number
    last_page: number
  }
}

export interface CampaignListParams {
  /** Brand ULID. */
  brand?: string
  status?: CampaignStatus | 'all'
  /** `'YYYY-MM-DD'` — campaigns whose starts_at is on/after this date. */
  starts_from?: string
  /** `'YYYY-MM-DD'` — campaigns whose starts_at is on/before this date. */
  starts_to?: string
  page?: number
  per_page?: number
}

export interface CreateCampaignPayload {
  /** Brand ULID — must belong to the path agency. */
  brand_id: string
  name: string
  description?: string | null
  objective: CampaignObjective
  budget_minor_units: number
  budget_currency: string
  starts_at?: string | null
  ends_at?: string | null
  posting_window_starts_at?: string | null
  posting_window_ends_at?: string | null
  target_creator_count?: number | null
  requires_per_campaign_contract?: boolean
  brief?: CampaignBrief | null
}

/** PATCH — the Settings edit. Partial; `brand_id` is NOT editable. */
export type UpdateCampaignPayload = Partial<Omit<CreateCampaignPayload, 'brand_id'>> & {
  status?: CampaignStatus
}

// ---------------------------------------------------------------------------
// Campaign assignment (read-only Creators tab — Chunk 1)
// ---------------------------------------------------------------------------

export interface CampaignAssignmentResource {
  id: string
  type: 'campaign_assignments'
  attributes: {
    status: AssignmentStatus
    agreed_fee_minor_units: number | null
    agreed_fee_currency: string | null
    countered_fee_minor_units: number | null
    countered_fee_currency: string | null
    invited_at: string | null
    responded_at: string | null
    posting_due_at: string | null
    creator: {
      id: string
      display_name: string | null
    } | null
  }
}

export interface CampaignAssignmentListResponse {
  data: CampaignAssignmentResource[]
  meta: {
    total: number
    page: number
    per_page: number
    last_page: number
  }
}

// ---------------------------------------------------------------------------
// Agency invite (Chunk 2) — the two-tier gate
// ---------------------------------------------------------------------------

/** POST a single invite under a campaign (D-3). */
export interface InviteAssignmentPayload {
  /** The creator's PUBLIC ULID. */
  creator_id: string
  /** Positive integer in minor units (D-8). */
  agreed_fee_minor_units: number
  /** ISO-4217; must match the campaign currency when set (D-8). */
  agreed_fee_currency: string
  deliverables?: string[] | null
  posting_due_at?: string | null
  /**
   * The soft-warn protocol flag (D-2): re-submit with `true` to proceed past a
   * hard AVAILABILITY conflict (a 409). No bearing on the blacklist 422.
   */
  acknowledged?: boolean
}

/** POST the agency re-offer after a counter (D-7) — verb on an existing row. */
export interface ReinviteAssignmentPayload {
  agreed_fee_minor_units: number
  agreed_fee_currency: string
}

/** One overlapping hard-availability occurrence in a 409 conflict payload. */
export interface AssignmentAvailabilityConflict {
  starts_at: string
  ends_at: string
  reason: string | null
}

/**
 * The 409 body returned when a hard availability conflict is detected (D-2 —
 * the SOFT WARN tier). Distinct from the blacklist 422 (the HARD BLOCK tier):
 * `meta.code === 'assignment.availability_conflict'`.
 */
export interface AssignmentAvailabilityConflictResponse {
  message: string
  meta: { code: 'assignment.availability_conflict' }
  conflict: {
    creator_id: string
    conflicts: AssignmentAvailabilityConflict[]
  }
}

// ---------------------------------------------------------------------------
// Creator-self assignments (Chunk 2, D-9) — creators/me/assignments
// ---------------------------------------------------------------------------

export interface CreatorAssignmentResource {
  id: string
  type: 'campaign_assignment'
  attributes: {
    status: AssignmentStatus
    agreed_fee_minor_units: number | null
    agreed_fee_currency: string | null
    countered_fee_minor_units: number | null
    countered_fee_currency: string | null
    deliverables: string[] | null
    posting_due_at: string | null
    invited_at: string | null
    campaign: {
      id: string
      name: string
      posting_window_starts_at: string | null
      posting_window_ends_at: string | null
      brand_name: string | null
    } | null
  }
}

export interface CreatorAssignmentListResponse {
  data: CreatorAssignmentResource[]
}

/** The `{data, meta:{code}}` envelope returned by accept/decline/counter. */
export interface CreatorAssignmentActionResponse {
  data: {
    type: 'campaign_assignment'
    id: string
    attributes: { status: AssignmentStatus }
  }
  meta: { code: string }
}

export interface CounterAssignmentPayload {
  countered_fee_minor_units: number
  countered_fee_currency: string
}

// ---------------------------------------------------------------------------
// Creator submission surface (Sprint 9 Chunk 1) — drafts + media + posted
// ---------------------------------------------------------------------------

/** Mirror of `DraftReviewStatus`. Chunk 1 only ever reads `pending`. */
export type DraftReviewStatus = 'pending' | 'approved' | 'rejected' | 'revision_requested'

/** Mirror of `PostedContentVerificationStatus`. Chunk 1 only reads `pending`. */
export type PostedContentVerificationStatus = 'pending' | 'verified' | 'not_found' | 'mismatch'

/** One media attachment on a draft, as returned by `CampaignDraftResource`. */
export interface DraftMediaAttachment {
  s3_path: string | null
  mime_type: string | null
  kind: string | null
  thumbnail_path: string | null
  duration_seconds: number | null
  /** Presigned GET URL (null in tests / non-S3 disks). */
  view_url: string | null
  thumbnail_view_url: string | null
}

/** One `campaign_drafts` row (a single submission version). */
export interface CampaignDraftResource {
  id: string
  type: 'campaign_draft'
  attributes: {
    version: number
    submitted_at: string | null
    caption: string | null
    hashtags: string[] | null
    mentions: string[] | null
    media: DraftMediaAttachment[]
    review_status: DraftReviewStatus
    reviewed_at: string | null
    review_feedback: string | null
  }
}

/** One `campaign_posted_content` row (the self-reported post). */
export interface CampaignPostedContentResource {
  id: string
  type: 'campaign_posted_content'
  attributes: {
    platform: string
    post_url: string
    platform_post_id: string | null
    posted_at: string | null
    verified_at: string | null
    verification_status: PostedContentVerificationStatus
  }
}

/** The per-assignment detail payload the creator detail route consumes (D-9). */
export interface CreatorAssignmentDetailResource {
  id: string
  type: 'campaign_assignment'
  attributes: {
    status: AssignmentStatus
    agreed_fee_minor_units: number | null
    agreed_fee_currency: string | null
    countered_fee_minor_units: number | null
    countered_fee_currency: string | null
    deliverables: string[] | null
    posting_due_at: string | null
    invited_at: string | null
    submitted_draft_at: string | null
    approved_at: string | null
    posted_at: string | null
    campaign: {
      id: string
      name: string
      posting_window_starts_at: string | null
      posting_window_ends_at: string | null
      brand_name: string | null
    } | null
  }
  relationships: {
    drafts: CampaignDraftResource[]
    posted_content: CampaignPostedContentResource[]
    contract: ContractResource | null
  }
}

/** A per-campaign contract row (contract-bridge chunk). */
export interface ContractResource {
  id: string
  type: 'contract'
  attributes: {
    kind: string
    title: string
    body_markdown: string | null
    status: string
    sent_at: string | null
    signed_at: string | null
    view_url: string | null
  }
}

export interface CreatorAssignmentDetailResponse {
  data: CreatorAssignmentDetailResource
}

// ---------------------------------------------------------------------------
// Agency review surface (Sprint 9 Chunk 2) — the review drawer + 3 actions
// ---------------------------------------------------------------------------

/** The agency-side per-assignment detail the review drawer consumes (D-7). */
export interface AgencyAssignmentDetailResource {
  id: string
  type: 'campaign_assignment'
  attributes: {
    status: AssignmentStatus
    agreed_fee_minor_units: number | null
    agreed_fee_currency: string | null
    posting_due_at: string | null
    submitted_draft_at: string | null
    approved_at: string | null
    posted_at: string | null
    verified_live_at: string | null
    creator: {
      id: string
      display_name: string | null
    } | null
    campaign: {
      id: string
      name: string
      brand_name: string | null
    } | null
  }
  relationships: {
    drafts: CampaignDraftResource[]
    posted_content: CampaignPostedContentResource[]
  }
}

export interface AgencyAssignmentDetailResponse {
  data: AgencyAssignmentDetailResource
}

/** POST `…/approve` — no body (the approval carries no feedback, D-5). */
export type ApproveDraftPayload = Record<string, never>

/** POST `…/request-revision` — feedback REQUIRED (D-5). */
export interface RequestRevisionPayload {
  review_feedback: string
}

/** POST `…/reject` — the rejection reason REQUIRED (D-5); terminal (D-1). */
export interface RejectDraftPayload {
  review_feedback: string
}

/** The `{data, meta:{code}}` envelope returned by the three review actions. */
export interface ReviewActionResponse {
  data: CampaignDraftResource
  meta: { code: string }
}

/** One media item submitted with a draft (the SPA builds this post-upload). */
export interface DraftMediaInput {
  s3_path: string
  mime_type: string
  kind: 'image' | 'video'
  thumbnail_path?: string | null
  duration_seconds?: number | null
}

export interface SubmitDraftPayload {
  caption?: string | null
  hashtags?: string[] | null
  mentions?: string[] | null
  media: DraftMediaInput[]
}

export interface CreatorDraftSubmitResponse {
  data: CampaignDraftResource
  meta: { code: string }
}

export interface SubmitPostedContentPayload {
  platform: string
  post_url: string
}

export interface CreatorPostedContentResponse {
  data: CampaignPostedContentResource
  meta: { code: string }
}

export interface DraftMediaInitPayload {
  mime_type: string
  declared_bytes: number
}

export interface DraftMediaInitResponse {
  data: {
    upload_url: string
    upload_id: string
    storage_path: string
    expires_at: string
    max_bytes: number
  }
}

export interface DraftMediaCompletePayload {
  upload_id: string
}

export interface DraftMediaCompleteResponse {
  data: { storage_path: string }
}

// ---------------------------------------------------------------------------
// Contract bridge — per-campaign attach + accept
// ---------------------------------------------------------------------------

export interface AttachContractPayload {
  title: string
  body_markdown?: string | null
  body_pdf_path?: string | null
}

export interface AttachContractResponse {
  data: ContractResource
  meta: { code: string }
}

export interface ContractMediaInitPayload {
  mime_type: string
  declared_bytes: number
}

export interface ContractMediaInitResponse {
  data: {
    upload_url: string
    upload_id: string
    storage_path: string
    expires_at: string
    max_bytes: number
  }
}

export interface ContractMediaCompletePayload {
  upload_id: string
}

export interface ContractMediaCompleteResponse {
  data: { storage_path: string }
}

export interface CreatorContractAcceptResponse {
  data: {
    type: 'campaign_assignment'
    id: string
    attributes: { status: AssignmentStatus }
    relationships: { contract: ContractResource }
  }
  meta: { code: string }
}
