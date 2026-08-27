/**
 * Platform automation rules API (AH-090, D6).
 *
 *   GET    /api/v1/admin/automation-rules              — list (unpaginated)
 *   POST   /api/v1/admin/automation-rules              — create (reason req.)
 *   PATCH  /api/v1/admin/automation-rules/{ulid}       — edit (reason req.)
 *   DELETE /api/v1/admin/automation-rules/{ulid}       — hard delete (reason req.)
 *   POST   /api/v1/admin/automation-rules/{ulid}/toggle — enable/disable (reason req.)
 *
 *   GET    /api/v1/admin/agencies?for=select           — the target picker's feed
 *
 * Every write carries a mandatory reason: all four `automation_rule.*` audit
 * verbs requiresReason() on the backend, so a reasonless call is a 422, not a
 * silent success.
 *
 * Types stay LOCAL to the admin SPA rather than moving to
 * `packages/api-client` — the admin-agencies precedent
 * (docs/reviews/admin-agencies-2fa-inventory.md): shared types earn their place
 * when a second app consumes the same endpoint, and no main-SPA surface reads
 * platform automation rules.
 */

import { http } from '@/core/api'

/** The events a rule can watch. Mirrors the backend `AutomationEvent` enum. */
export type AutomationEvent = 'creator.approved'

/** What a rule does. Mirrors the backend `AutomationAction` enum. */
export type AutomationAction = 'connect_to_agency'

/**
 * Why a rule cannot currently act, or null when it can.
 *
 * The rule's target is a ULID inside a JSON config with no foreign key behind
 * it, so an agency can be deleted or suspended out from under a rule at any
 * time. The backend job refuses to fire in those states; this field is what lets
 * the list SAY so, instead of showing a rule that silently never fires.
 */
export type AutomationBrokenReason = 'misconfigured' | 'agency_missing' | 'agency_suspended'

export interface AdminAutomationRule {
  id: string
  type: 'automation_rules'
  attributes: {
    name: string
    event: AutomationEvent
    action: AutomationAction
    enabled: boolean
    target_agency_id: string | null
    target_agency_name: string | null
    target_agency_suspended: boolean
    broken_reason: AutomationBrokenReason | null
    last_fired_at: string | null
    fire_count: number
    created_at: string
    updated_at: string
  }
}

export interface AdminAutomationRuleListResponse {
  data: AdminAutomationRule[]
}

export interface AdminAutomationRuleEnvelope {
  data: AdminAutomationRule
}

export interface AutomationRulePayload {
  name: string
  event: AutomationEvent
  action: AutomationAction
  config: { agency_id: string }
  reason: string
  enabled?: boolean
}

/** The thin `{id, name}` agency projection the target picker reads. */
export interface AdminAgencyOption {
  id: string
  type: 'agencies'
  attributes: { name: string }
}

export interface AdminAgencyOptionListResponse {
  data: AdminAgencyOption[]
}

export const adminAutomationsApi = {
  list(): Promise<AdminAutomationRuleListResponse> {
    return http.get<AdminAutomationRuleListResponse>('/admin/automation-rules')
  },

  create(payload: AutomationRulePayload): Promise<AdminAutomationRuleEnvelope> {
    return http.post<AdminAutomationRuleEnvelope>('/admin/automation-rules', payload)
  },

  update(ulid: string, payload: AutomationRulePayload): Promise<AdminAutomationRuleEnvelope> {
    return http.patch<AdminAutomationRuleEnvelope>(`/admin/automation-rules/${ulid}`, payload)
  },

  toggle(ulid: string, enabled: boolean, reason: string): Promise<AdminAutomationRuleEnvelope> {
    return http.post<AdminAutomationRuleEnvelope>(`/admin/automation-rules/${ulid}/toggle`, {
      enabled,
      reason,
    })
  },

  remove(ulid: string, reason: string): Promise<void> {
    return http.delete<void>(`/admin/automation-rules/${ulid}`, { reason })
  },

  /**
   * Every non-suspended agency, unpaginated.
   *
   * `?for=select` deliberately, NOT the paginated index with a large per_page:
   * a picker reading the paginated branch would silently stop offering agencies
   * past its page boundary — the AH-066/AH-085 bug class, whose decision
   * procedure is now written down in docs/02-CONVENTIONS.md §3.5.
   */
  agencyOptions(): Promise<AdminAgencyOptionListResponse> {
    return http.get<AdminAgencyOptionListResponse>('/admin/agencies?for=select')
  },
}
