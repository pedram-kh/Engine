/**
 * Typed wrapper for the strategy endpoints (PL-2.1).
 *
 * Endpoint prefix: /api/v1/agencies/{agency}/brands/{brand}/waves/{wave}/strategy. Behind `waves_enabled` AND
 * `strategy_enabled` (404 `not_found` while either is off).
 */

import type {
  PersonaCopyPayload,
  StrategySummaryEnvelope,
  WaveAngleEnvelope,
  WavePersonaEnvelope,
  WavePersonaListResponse,
  WavePersonaPayload,
  WaveUspEnvelope,
  WaveUspListResponse,
  WaveUspPayload,
} from '@catalyst/api-client'
import { http } from '@/core/api'

export interface WaveScope {
  agencyId: string
  brandId: string
  waveId: string
}

function base(s: WaveScope): string {
  return `/agencies/${s.agencyId}/brands/${s.brandId}/waves/${s.waveId}/strategy`
}

export const strategyApi = {
  summary(s: WaveScope): Promise<StrategySummaryEnvelope> {
    return http.get<StrategySummaryEnvelope>(base(s))
  },

  /** `null` (or blank) clears the title. */
  setAudienceTitle(s: WaveScope, title: string | null): Promise<StrategySummaryEnvelope> {
    return http.patch<StrategySummaryEnvelope>(`${base(s)}/audience-title`, {
      usp_audience_title: title,
    })
  },

  listUsps(s: WaveScope): Promise<WaveUspListResponse> {
    return http.get<WaveUspListResponse>(`${base(s)}/usps`)
  },

  createUsp(s: WaveScope, payload: WaveUspPayload): Promise<WaveUspEnvelope> {
    return http.post<WaveUspEnvelope>(`${base(s)}/usps`, payload)
  },

  /** Only the fields that changed. */
  updateUsp(s: WaveScope, uspId: string, payload: WaveUspPayload): Promise<WaveUspEnvelope> {
    return http.patch<WaveUspEnvelope>(`${base(s)}/usps/${uspId}`, payload)
  },

  /** The FULL ordered list of this wave's USP ids; anything else is 422 `usp.order_mismatch`. */
  reorderUsps(s: WaveScope, order: string[]): Promise<WaveUspListResponse> {
    return http.put<WaveUspListResponse>(`${base(s)}/usps/order`, { order })
  },

  /**
   * A USP no angle uses is deleted straight away. For one that angles use the server answers 409 `usp.in_use`
   * (with the list) until the same call carries `confirm_count` equal to the number it counted (angles and matrix cells).
   */
  removeUsp(s: WaveScope, uspId: string, confirmCount?: number): Promise<void> {
    return http.delete<void>(
      `${base(s)}/usps/${uspId}`,
      confirmCount === undefined ? undefined : { confirm_count: confirmCount },
    )
  },

  listPersonas(s: WaveScope, market?: string): Promise<WavePersonaListResponse> {
    const qs = market === undefined ? '' : `?market=${encodeURIComponent(market)}`
    return http.get<WavePersonaListResponse>(`${base(s)}/personas${qs}`)
  },

  createPersona(s: WaveScope, payload: WavePersonaPayload): Promise<WavePersonaEnvelope> {
    return http.post<WavePersonaEnvelope>(`${base(s)}/personas`, payload)
  },

  updatePersona(
    s: WaveScope,
    personaId: string,
    payload: WavePersonaPayload,
  ): Promise<WavePersonaEnvelope> {
    return http.patch<WavePersonaEnvelope>(`${base(s)}/personas/${personaId}`, payload)
  },

  removePersona(s: WaveScope, personaId: string): Promise<void> {
    return http.delete<void>(`${base(s)}/personas/${personaId}`)
  },

  copyPersonas(s: WaveScope, payload: PersonaCopyPayload): Promise<WavePersonaListResponse> {
    return http.post<WavePersonaListResponse>(`${base(s)}/personas/copy`, payload)
  },

  addAngle(
    s: WaveScope,
    personaId: string,
    uspId: string,
    explanation: string,
  ): Promise<WaveAngleEnvelope> {
    return http.post<WaveAngleEnvelope>(`${base(s)}/personas/${personaId}/angles`, {
      usp: uspId,
      explanation,
    })
  },

  updateAngle(
    s: WaveScope,
    personaId: string,
    angleId: string,
    explanation: string,
  ): Promise<WaveAngleEnvelope> {
    return http.patch<WaveAngleEnvelope>(`${base(s)}/personas/${personaId}/angles/${angleId}`, {
      explanation,
    })
  },

  removeAngle(s: WaveScope, personaId: string, angleId: string): Promise<void> {
    return http.delete<void>(`${base(s)}/personas/${personaId}/angles/${angleId}`)
  },
}
