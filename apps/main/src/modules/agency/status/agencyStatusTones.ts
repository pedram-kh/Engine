/**
 * AH-114 (S2.1) — the one table that gives every agency status a tone and a label.
 *
 * Figma rule R9: a status is a pill (dot plus a word on a 15 % tint), never a bare
 * coloured chip, and its colour and its word come from the same place. This is that
 * place. Each status union the agency app renders (inventory §6.2, confirmed by
 * Pedram, plan §9 ruling 3) maps every value to:
 *
 *   - a tone: `success`, `info`, `warning`, `danger`, `neutral`, or `ai` (kept in the
 *     theme, unused today), or `null` for a value that deliberately shows no pill;
 *   - an EXISTING i18n key for the word. No label is added here; spec 6
 *     (`agency-status-tone`) checks that every key resolves in all 24 locales.
 *
 * The tables are `Record<Union, …>`, so adding a value to a union in
 * `@catalyst/api-client` fails typecheck here, and spec 6 reads the union text itself
 * so the same change fails the unit run too.
 *
 * Out of scope on purpose: `JobLifecycleState` (creator-facing) and the board column
 * statuses (`boardStatus`, a separate palette, plan ruling 7).
 *
 * The assignment table covers all 17 values of `AssignmentStatus`. The inventory
 * listed 13; the four added by the spec's union read (`manually_verified`,
 * `payment_held`, `payment_released`, `cancelled`) have tones chosen here by the same
 * analogy and are flagged in the plan's S2.1 build note.
 */

import type {
  AgencyInvitationStatus,
  AgencyMembershipStatus,
  AssignmentStatus,
  BrandStatus,
  CampaignApplicationStatus,
  CampaignStatus,
  DiscoveryConnectionState,
  DraftReviewStatus,
  PostedContentVerificationStatus,
  RosterRelationshipStatus,
} from '@catalyst/api-client'

export type AgencyStatusTone = 'success' | 'info' | 'warning' | 'danger' | 'neutral' | 'ai'

export interface AgencyStatusEntry {
  /** `null` = this value shows no pill (the caller renders nothing). */
  readonly tone: AgencyStatusTone | null
  /** An existing i18n key (the `app` namespace). `null` only when `tone` is `null`. */
  readonly labelKey: string | null
}

function entries<K extends string>(
  prefix: string,
  tones: Record<K, AgencyStatusTone>,
): Record<K, AgencyStatusEntry> {
  const out = {} as Record<K, AgencyStatusEntry>
  for (const value of Object.keys(tones) as K[]) {
    out[value] = { tone: tones[value], labelKey: `${prefix}.${value}` }
  }
  return out
}

export const AGENCY_STATUS_TONES = {
  campaign: entries<CampaignStatus>('app.campaigns.status', {
    draft: 'neutral',
    active: 'success',
    paused: 'warning',
    completed: 'info',
    cancelled: 'danger',
  }),
  assignment: entries<AssignmentStatus>('app.campaigns.assignmentStatus', {
    invited: 'info',
    declined: 'danger',
    countered: 'warning',
    accepted: 'success',
    contracted: 'success',
    producing: 'info',
    draft_submitted: 'warning',
    revision_requested: 'danger',
    approved: 'success',
    rejected: 'danger',
    completed_on_approval: 'success',
    posted: 'info',
    live_verified: 'success',
    manually_verified: 'success',
    payment_held: 'warning',
    payment_released: 'success',
    cancelled: 'danger',
  }),
  draftReview: entries<DraftReviewStatus>('app.campaigns.review.draftStatus', {
    pending: 'warning',
    approved: 'success',
    rejected: 'danger',
    revision_requested: 'danger',
  }),
  postedContent: entries<PostedContentVerificationStatus>('app.campaigns.review.verification', {
    pending: 'warning',
    verified: 'success',
    not_found: 'danger',
    mismatch: 'danger',
  }),
  application: entries<CampaignApplicationStatus>('app.campaigns.applications.status', {
    pending: 'warning',
    accepted: 'success',
    rejected: 'danger',
  }),
  roster: entries<RosterRelationshipStatus>('app.roster.status', {
    roster: 'success',
    prospect: 'neutral',
    external: 'neutral',
    pending_request: 'warning',
    declined: 'danger',
    ended: 'neutral',
  }),
  discovery: {
    ...entries<Exclude<DiscoveryConnectionState, 'none'>>('app.discover.connection', {
      connected: 'success',
      pending: 'warning',
      declined: 'danger',
      ended: 'neutral',
    }),
    // Not connected and never asked: there is nothing to show.
    none: { tone: null, labelKey: null },
  } satisfies Record<DiscoveryConnectionState, AgencyStatusEntry>,
  brand: entries<BrandStatus>('app.brands.status', {
    active: 'success',
    archived: 'neutral',
  }),
  invitation: entries<AgencyInvitationStatus>('app.agencyUsers.invitations.status', {
    pending: 'warning',
    accepted: 'success',
    expired: 'neutral',
  }),
  membership: entries<AgencyMembershipStatus>('app.agencyUsers.status', {
    active: 'success',
    pending: 'warning',
  }),
} as const

export type AgencyStatusKind = keyof typeof AGENCY_STATUS_TONES

/**
 * The union each `kind` covers: the type name and the file in `@catalyst/api-client`
 * that declares it. Read by spec 6 (it parses the union text, so a scratch edit to
 * the union fails the unit run), and the one place that lists what is in scope.
 */
export const AGENCY_STATUS_SOURCES: Readonly<
  Record<AgencyStatusKind, { readonly type: string; readonly file: string }>
> = {
  campaign: { type: 'CampaignStatus', file: 'types/campaign.ts' },
  assignment: { type: 'AssignmentStatus', file: 'types/campaign.ts' },
  draftReview: { type: 'DraftReviewStatus', file: 'types/campaign.ts' },
  postedContent: { type: 'PostedContentVerificationStatus', file: 'types/campaign.ts' },
  application: { type: 'CampaignApplicationStatus', file: 'types/campaign.ts' },
  roster: { type: 'RosterRelationshipStatus', file: 'types/agency.ts' },
  discovery: { type: 'DiscoveryConnectionState', file: 'types/agency.ts' },
  brand: { type: 'BrandStatus', file: 'types/agency.ts' },
  invitation: { type: 'AgencyInvitationStatus', file: 'types/agency.ts' },
  membership: { type: 'AgencyMembershipStatus', file: 'types/agency.ts' },
}

/** Resolve a status. An unknown value (a backend that is ahead of the table) is `undefined`. */
export function agencyStatusEntry(
  kind: AgencyStatusKind,
  status: string,
): AgencyStatusEntry | undefined {
  const table = AGENCY_STATUS_TONES[kind] as Readonly<Record<string, AgencyStatusEntry>>
  return Object.prototype.hasOwnProperty.call(table, status) ? table[status] : undefined
}
