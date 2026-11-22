/**
 * Typed wrapper for the strategy endpoints (PL-2.1).
 *
 * Endpoint prefix: /api/v1/agencies/{agency}/brands/{brand}/waves/{wave}/strategy. Behind `waves_enabled` AND
 * `strategy_enabled` (404 `not_found` while either is off).
 */

import type {
  ConceptDecisionPayload,
  ConceptEnvelope,
  ConceptImageEnvelope,
  ConceptImageInitPayload,
  ConceptImageInitResponse,
  ConceptLinkEnvelope,
  ConceptLinkPayload,
  ConceptListResponse,
  ConceptPayload,
  ConceptResetResponse,
  MatrixCellChange,
  MatrixEnvelope,
  MatrixSaveEnvelope,
  PersonaCopyPayload,
  StrategyCompletionResponse,
  StrategyNoteEnvelope,
  StrategyNoteListResponse,
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

export type MarkableStep = 'usps' | 'personas' | 'matrix'
export type NoteStep = MarkableStep | 'concepts'

function marketQuery(market: string | null): string {
  return market === null ? '' : `?market=${encodeURIComponent(market)}`
}

export const strategyApi = {
  /** PL-2.5: "Mark as complete". `market` is the market's ulid (none for USPs). Idempotent. */
  markComplete(
    s: WaveScope,
    step: MarkableStep,
    market: string | null,
  ): Promise<StrategyCompletionResponse> {
    return http.put<StrategyCompletionResponse>(
      `${base(s)}/completions/${step}${marketQuery(market)}`,
    )
  },

  reopen(
    s: WaveScope,
    step: MarkableStep,
    market: string | null,
  ): Promise<StrategyCompletionResponse> {
    return http.delete<StrategyCompletionResponse>(
      `${base(s)}/completions/${step}${marketQuery(market)}`,
    )
  },

  listNotes(
    s: WaveScope,
    step: NoteStep,
    market: string | null,
  ): Promise<StrategyNoteListResponse> {
    return http.get<StrategyNoteListResponse>(
      `${base(s)}/notes?step=${step}${market === null ? '' : `&market=${encodeURIComponent(market)}`}`,
    )
  },

  addNote(
    s: WaveScope,
    step: NoteStep,
    market: string | null,
    body: string,
  ): Promise<StrategyNoteEnvelope> {
    return http.post<StrategyNoteEnvelope>(`${base(s)}/notes`, { step, market, body })
  },

  editNote(s: WaveScope, noteId: string, body: string): Promise<StrategyNoteEnvelope> {
    return http.patch<StrategyNoteEnvelope>(`${base(s)}/notes/${noteId}`, { body })
  },

  deleteNote(s: WaveScope, noteId: string): Promise<void> {
    return http.delete<void>(`${base(s)}/notes/${noteId}`)
  },

  summary(s: WaveScope): Promise<StrategySummaryEnvelope> {
    return http.get<StrategySummaryEnvelope>(base(s))
  },

  /** `null` (or blank) clears the title. */
  setAudienceTitle(s: WaveScope, title: string | null): Promise<StrategySummaryEnvelope> {
    return http.patch<StrategySummaryEnvelope>(`${base(s)}/audience-title`, {
      usp_audience_title: title,
    })
  },

  /** One market's matrix in one request: USPs, personas, set cells, progress. */
  matrix(s: WaveScope, market: string): Promise<MatrixEnvelope> {
    return http.get<MatrixEnvelope>(`${base(s)}/matrix?market=${encodeURIComponent(market)}`)
  },

  /** 1 to 48 cell changes in one transaction; only the fields that changed. */
  saveCells(s: WaveScope, market: string, cells: MatrixCellChange[]): Promise<MatrixSaveEnvelope> {
    return http.put<MatrixSaveEnvelope>(`${base(s)}/matrix/cells`, { market, cells })
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

  /**
   * PL-2.2 "Use the matrix line": a hand-written angle becomes the synced one of its Priority or Support cell (the same
   * row, the text of the message line). Idempotent on the server. Any role.
   */
  useMatrixLine(s: WaveScope, personaId: string, angleId: string): Promise<WaveAngleEnvelope> {
    return http.post<WaveAngleEnvelope>(
      `${base(s)}/personas/${personaId}/angles/${angleId}/use-matrix-line`,
    )
  },

  removeAngle(s: WaveScope, personaId: string, angleId: string): Promise<void> {
    return http.delete<void>(`${base(s)}/personas/${personaId}/angles/${angleId}`)
  },

  // --- Concepts (PL-2.3) -----------------------------------------------------------------------------------

  listConcepts(s: WaveScope, market: string): Promise<ConceptListResponse> {
    return http.get<ConceptListResponse>(`${base(s)}/concepts?market=${encodeURIComponent(market)}`)
  },

  createConcept(s: WaveScope, payload: ConceptPayload): Promise<ConceptEnvelope> {
    return http.post<ConceptEnvelope>(`${base(s)}/concepts`, payload)
  },

  /** Only the fields that changed. A new `persona` needs `usp_mode`. The answer says whether the concept went back to Awaiting. */
  updateConcept(
    s: WaveScope,
    conceptId: string,
    payload: ConceptPayload,
  ): Promise<ConceptEnvelope> {
    return http.patch<ConceptEnvelope>(`${base(s)}/concepts/${conceptId}`, payload)
  },

  duplicateConcept(s: WaveScope, conceptId: string): Promise<ConceptEnvelope> {
    return http.post<ConceptEnvelope>(`${base(s)}/concepts/${conceptId}/duplicate`)
  },

  removeConcept(s: WaveScope, conceptId: string): Promise<void> {
    return http.delete<void>(`${base(s)}/concepts/${conceptId}`)
  },

  /** Admin and manager only (403 `forbidden` for staff). */
  decideConcept(
    s: WaveScope,
    conceptId: string,
    payload: ConceptDecisionPayload,
  ): Promise<ConceptEnvelope> {
    return http.post<ConceptEnvelope>(`${base(s)}/concepts/${conceptId}/decision`, payload)
  },

  initImage(
    s: WaveScope,
    conceptId: string,
    payload: ConceptImageInitPayload,
  ): Promise<ConceptImageInitResponse> {
    return http.post<ConceptImageInitResponse>(
      `${base(s)}/concepts/${conceptId}/images/uploads/init`,
      payload,
    )
  },

  completeImage(s: WaveScope, conceptId: string, uploadId: string): Promise<ConceptImageEnvelope> {
    return http.post<ConceptImageEnvelope>(
      `${base(s)}/concepts/${conceptId}/images/uploads/complete`,
      { upload_id: uploadId },
    )
  },

  removeImage(s: WaveScope, conceptId: string, imageId: string): Promise<ConceptResetResponse> {
    return http.delete<ConceptResetResponse>(`${base(s)}/concepts/${conceptId}/images/${imageId}`)
  },

  /**
   * An image is read through the API only, with the app's own credentials (the session cookie; the creator app's
   * bearer token does not reach this screen). `path` is the `file_url` or `thumbnail_url` the server sent.
   */
  imageBlob(path: string): Promise<Blob> {
    return http.get<Blob>(path.replace(/^\/api\/v1/, ''), {
      responseType: 'blob',
      headers: { Accept: 'image/*' },
    })
  },

  addLink(
    s: WaveScope,
    conceptId: string,
    payload: Required<ConceptLinkPayload>,
  ): Promise<ConceptLinkEnvelope> {
    return http.post<ConceptLinkEnvelope>(`${base(s)}/concepts/${conceptId}/links`, payload)
  },

  updateLink(
    s: WaveScope,
    conceptId: string,
    linkId: string,
    payload: ConceptLinkPayload,
  ): Promise<ConceptLinkEnvelope> {
    return http.patch<ConceptLinkEnvelope>(
      `${base(s)}/concepts/${conceptId}/links/${linkId}`,
      payload,
    )
  },

  removeLink(s: WaveScope, conceptId: string, linkId: string): Promise<ConceptResetResponse> {
    return http.delete<ConceptResetResponse>(`${base(s)}/concepts/${conceptId}/links/${linkId}`)
  },
}
