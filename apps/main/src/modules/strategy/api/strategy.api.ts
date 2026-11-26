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
  ArchivedScript,
  ScriptBriefPayload,
  ScriptHookPayload,
  ScriptImage,
  ScriptImageInit,
  ScriptLanguagesResource,
  ScriptLinePayload,
  ScriptsOnRemove,
  ScriptsOnReplace,
  ScriptsOverview,
  WaveCreatorAddPayload,
  WaveScript,
  WaveCreatorCandidatesResponse,
  WaveCreatorDecideResponse,
  WaveCreatorDecisionHistoryResponse,
  WaveCreatorDecisionPayload,
  WaveCreatorUpdatePayload,
  WaveCreatorsResponse,
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
export type NoteStep = MarkableStep | 'concepts' | 'creators' | 'scripts'

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

  // PL-3.1 — the creators step. `creators_scripts_enabled` (404 while off).
  creators(s: WaveScope): Promise<WaveCreatorsResponse> {
    return http.get<WaveCreatorsResponse>(`${base(s)}/creators`)
  },

  /** The picker: the campaign's applicants and invitees, pre-filtered to the market's countries by the caller. */
  creatorCandidates(
    s: WaveScope,
    params: { market: string; q?: string; countries?: string[]; page?: number; per_page?: number },
  ): Promise<WaveCreatorCandidatesResponse> {
    const qs = new URLSearchParams()
    qs.set('market', params.market)
    if (params.q !== undefined && params.q.trim() !== '') qs.set('q', params.q.trim())
    for (const c of params.countries ?? []) qs.append('country[]', c)
    if (params.page !== undefined) qs.set('page', String(params.page))
    if (params.per_page !== undefined) qs.set('per_page', String(params.per_page))
    return http.get<WaveCreatorCandidatesResponse>(
      `${base(s)}/creators/candidates?${qs.toString()}`,
    )
  },

  addCreators(s: WaveScope, payload: WaveCreatorAddPayload): Promise<WaveCreatorsResponse> {
    return http.post<WaveCreatorsResponse>(`${base(s)}/creators`, payload)
  },

  updateCreator(
    s: WaveScope,
    slotId: string,
    payload: WaveCreatorUpdatePayload,
  ): Promise<WaveCreatorsResponse> {
    return http.patch<WaveCreatorsResponse>(`${base(s)}/creators/${slotId}`, payload)
  },

  /** `scripts` says what happens to the slot's scripts: carried to the new creator (the default) or archived. */
  replaceCreator(
    s: WaveScope,
    slotId: string,
    creator: string,
    scripts?: ScriptsOnReplace,
  ): Promise<WaveCreatorsResponse> {
    return http.post<WaveCreatorsResponse>(`${base(s)}/creators/${slotId}/replace`, {
      creator,
      ...(scripts === undefined ? {} : { scripts }),
    })
  },

  /** `scripts` is needed when the creator has scripts (422 `wave_creator.scripts_choice_required` without it). */
  removeCreator(
    s: WaveScope,
    slotId: string,
    scripts?: ScriptsOnRemove,
  ): Promise<WaveCreatorsResponse> {
    return http.delete<WaveCreatorsResponse>(
      `${base(s)}/creators/${slotId}`,
      scripts === undefined ? undefined : { scripts },
    )
  },

  creatorDecisions(s: WaveScope, slotId: string): Promise<WaveCreatorDecisionHistoryResponse> {
    return http.get<WaveCreatorDecisionHistoryResponse>(`${base(s)}/creators/${slotId}/decisions`)
  },

  decideCreator(
    s: WaveScope,
    slotId: string,
    payload: WaveCreatorDecisionPayload,
  ): Promise<WaveCreatorDecideResponse> {
    return http.post<WaveCreatorDecideResponse>(`${base(s)}/creators/${slotId}/decisions`, payload)
  },

  // PL-3.2a — the scripts step. `creators_scripts_enabled` (404 while off).
  /** The step's state for the wave and, with a market, that market's rail. */
  scripts(s: WaveScope, market: string | null): Promise<{ data: ScriptsOverview }> {
    return http.get<{ data: ScriptsOverview }>(`${base(s)}/scripts${marketQuery(market)}`)
  },

  archivedScripts(s: WaveScope, market: string): Promise<{ data: ArchivedScript[] }> {
    return http.get<{ data: ArchivedScript[] }>(`${base(s)}/scripts/archived${marketQuery(market)}`)
  },

  script(s: WaveScope, scriptId: string): Promise<{ data: WaveScript }> {
    return http.get<{ data: WaveScript }>(`${base(s)}/scripts/${scriptId}`)
  },

  createScript(s: WaveScope, slot: string): Promise<{ data: WaveScript }> {
    return http.post<{ data: WaveScript }>(`${base(s)}/scripts`, { slot })
  },

  saveBrief(
    s: WaveScope,
    scriptId: string,
    payload: ScriptBriefPayload,
  ): Promise<{ data: WaveScript }> {
    return http.put<{ data: WaveScript }>(`${base(s)}/scripts/${scriptId}/brief`, payload)
  },

  /** "Archive script": every agency role. Nothing is destroyed; the script moves to the archived list. */
  archiveScript(s: WaveScope, scriptId: string): Promise<{ data: ScriptsOverview }> {
    return http.post<{ data: ScriptsOverview }>(`${base(s)}/scripts/${scriptId}/archive`)
  },

  /** "Restore" in the archived list: every agency role (422 when the creator already has three, or has left the market). */
  restoreScript(s: WaveScope, scriptId: string): Promise<{ data: ScriptsOverview }> {
    return http.post<{ data: ScriptsOverview }>(`${base(s)}/scripts/archived/${scriptId}/restore`)
  },

  /** The only way to delete one script for good. Admin and manager only (403 `forbidden`). */
  deleteArchivedScript(s: WaveScope, scriptId: string): Promise<{ data: ScriptsOverview }> {
    return http.delete<{ data: ScriptsOverview }>(`${base(s)}/scripts/archived/${scriptId}`)
  },

  addHook(s: WaveScope, scriptId: string): Promise<{ data: WaveScript }> {
    return http.post<{ data: WaveScript }>(`${base(s)}/scripts/${scriptId}/hooks`)
  },

  saveHook(
    s: WaveScope,
    scriptId: string,
    hookId: string,
    payload: ScriptHookPayload,
  ): Promise<{ data: WaveScript }> {
    return http.put<{ data: WaveScript }>(`${base(s)}/scripts/${scriptId}/hooks/${hookId}`, payload)
  },

  duplicateHook(s: WaveScope, scriptId: string, hookId: string): Promise<{ data: WaveScript }> {
    return http.post<{ data: WaveScript }>(
      `${base(s)}/scripts/${scriptId}/hooks/${hookId}/duplicate`,
    )
  },

  deleteHook(s: WaveScope, scriptId: string, hookId: string): Promise<{ data: WaveScript }> {
    return http.delete<{ data: WaveScript }>(`${base(s)}/scripts/${scriptId}/hooks/${hookId}`)
  },

  // PL-3.2b — hooks order, lines, B-roll, reference images and the market's script languages.
  orderHooks(s: WaveScope, scriptId: string, ids: string[]): Promise<{ data: WaveScript }> {
    return http.put<{ data: WaveScript }>(`${base(s)}/scripts/${scriptId}/hooks/order`, { ids })
  },

  addLine(s: WaveScope, scriptId: string): Promise<{ data: WaveScript }> {
    return http.post<{ data: WaveScript }>(`${base(s)}/scripts/${scriptId}/lines`)
  },

  saveLine(
    s: WaveScope,
    scriptId: string,
    lineId: string,
    payload: ScriptLinePayload,
  ): Promise<{ data: WaveScript }> {
    return http.put<{ data: WaveScript }>(`${base(s)}/scripts/${scriptId}/lines/${lineId}`, payload)
  },

  duplicateLine(s: WaveScope, scriptId: string, lineId: string): Promise<{ data: WaveScript }> {
    return http.post<{ data: WaveScript }>(
      `${base(s)}/scripts/${scriptId}/lines/${lineId}/duplicate`,
    )
  },

  deleteLine(s: WaveScope, scriptId: string, lineId: string): Promise<{ data: WaveScript }> {
    return http.delete<{ data: WaveScript }>(`${base(s)}/scripts/${scriptId}/lines/${lineId}`)
  },

  /** The complete ordered list of the script's line ids (a stale list is 422 `wave_script.order_mismatch`). */
  orderLines(s: WaveScope, scriptId: string, ids: string[]): Promise<{ data: WaveScript }> {
    return http.put<{ data: WaveScript }>(`${base(s)}/scripts/${scriptId}/lines/order`, { ids })
  },

  addBroll(s: WaveScope, scriptId: string, lineId: string): Promise<{ data: WaveScript }> {
    return http.post<{ data: WaveScript }>(`${base(s)}/scripts/${scriptId}/lines/${lineId}/broll`)
  },

  deleteBroll(
    s: WaveScope,
    scriptId: string,
    lineId: string,
    brollId: string,
  ): Promise<{ data: WaveScript }> {
    return http.delete<{ data: WaveScript }>(
      `${base(s)}/scripts/${scriptId}/lines/${lineId}/broll/${brollId}`,
    )
  },

  orderBroll(
    s: WaveScope,
    scriptId: string,
    lineId: string,
    ids: string[],
  ): Promise<{ data: WaveScript }> {
    return http.put<{ data: WaveScript }>(
      `${base(s)}/scripts/${scriptId}/lines/${lineId}/broll/order`,
      { ids },
    )
  },

  scriptLanguages(s: WaveScope, market: string): Promise<{ data: ScriptLanguagesResource }> {
    return http.get<{ data: ScriptLanguagesResource }>(
      `${base(s)}/markets/${market}/script-languages`,
    )
  },

  /** The market's local languages, in order (English is always there and is not sent). */
  setScriptLanguages(
    s: WaveScope,
    market: string,
    languages: string[],
  ): Promise<{ data: ScriptLanguagesResource }> {
    return http.put<{ data: ScriptLanguagesResource }>(
      `${base(s)}/markets/${market}/script-languages`,
      { languages },
    )
  },

  initScriptImage(
    s: WaveScope,
    scriptId: string,
    payload: {
      block: 'hook' | 'line'
      id: string
      mime_type: string
      declared_bytes: number
      original_filename: string
    },
  ): Promise<{ data: ScriptImageInit }> {
    return http.post<{ data: ScriptImageInit }>(
      `${base(s)}/scripts/${scriptId}/images/uploads/init`,
      payload,
    )
  },

  completeScriptImage(
    s: WaveScope,
    scriptId: string,
    uploadId: string,
  ): Promise<{ data: ScriptImage }> {
    return http.post<{ data: ScriptImage }>(
      `${base(s)}/scripts/${scriptId}/images/uploads/complete`,
      { upload_id: uploadId },
    )
  },

  deleteScriptImage(s: WaveScope, scriptId: string, imageId: string): Promise<unknown> {
    return http.delete<unknown>(`${base(s)}/scripts/${scriptId}/images/${imageId}`)
  },
}
