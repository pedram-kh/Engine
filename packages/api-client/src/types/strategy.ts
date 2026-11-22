/**
 * PL-2.1 — the strategy workspace of a wave: USPs, personas and their angles. Mirrors `StrategyPresenter` in
 * `apps/api` (`MOD/Strategy`). Ulids only: no internal id, no `agency_id` and no `wave_id` is ever sent.
 */

/** The six USP colours, in the order a new USP takes them. Each is also a brand-palette key. */
export type UspColorKey = 'violet' | 'green' | 'blue' | 'amber' | 'teal' | 'pink'

export type PersonaPronoun = 'she' | 'he' | 'they'

/** The limits the server enforces, sent with the summary so the SPA holds no copy of them. */
export interface StrategyLimits {
  usp_max: number
  usp_warn_below: number
  persona_min: number
  persona_max: number
  audience_title: number
  usp_title: number
  usp_explainer: number
  usp_creative_angle: number
  usp_copy_seed: number
  persona_code_min: number
  persona_code_max: number
  persona_title: number
  persona_name: number
  persona_summary: number
  persona_paragraph: number
  list_items: number
  list_item: number
  angle_explanation: number
  /** The longest rationale and message line of a matrix cell. */
  matrix_line: number
  /** Cells in one write request. */
  matrix_batch_max: number
  /** PL-2.3: concepts. Each is a limit the server enforces; the per-field text limits are the PL-2.3 constants below. */
  concept_max_per_market: number
  concept_min_recommended: number
  concept_title: number
  concept_funnel_note: number
  concept_product: number
  concept_angle: number
  concept_primary_message: number
  concept_hypothesis: number
  concept_hook: number
  concept_format_visual: number
  concept_payoff: number
  concept_cta: number
  concept_key_points: number
  concept_key_point: number
  client_name: number
  decision_note: number
  decision_comment: number
  link_title: number
  links_per_concept: number
  images_per_concept: number
  image_total_bytes: number
}

export interface StrategyMarketCount {
  /** PL-2.4: the market's ULID. It is the market's identity everywhere: the address, request bodies and the lists. */
  market: string
  /** The market's name (free text typed by the agency; always shown as plain text). */
  name: string
  /** The short code: the prefix of its persona codes, never its identity. */
  code: string
  /** ISO 3166-1 alpha-2 codes, sorted. */
  countries: string[]
  persona_count: number
}

export interface StrategySummaryResource {
  id: string
  type: 'wave_strategy'
  attributes: {
    usp_count: number
    audience_title: string | null
    persona_count: number
    /** One entry per market of the wave, in the wave's order, with 0 for a market with no persona. */
    markets: StrategyMarketCount[]
    limits: StrategyLimits
    /** The wave-level progress of step 3 (PL-2.2): set cells over USPs times personas of every market. */
    matrix: MatrixProgress
    /** PL-2.3: step 4. Derived from the concept rows; nothing here ever locks a step. */
    concepts: ConceptsSummary
    /** PL-2.5: the real state of each step, its mark, its live warnings. */
    steps: StrategySteps
    /** PL-2.5: where "Strategy & Scripts" opens (the first step not done, the first market not done). */
    landing: StrategyLanding
    /** PL-2.5: internal note counts (never a body). */
    note_counts: StrategyNoteCounts
  }
}

export type StrategyStepState = 'not_started' | 'in_progress' | 'done'

export interface StrategyWarning {
  code: 'usps_under_recommended' | 'personas_under_minimum' | 'cells_not_set'
  [key: string]: unknown
}

export interface StrategyMark {
  /** Null when not marked; `name` is null and `former` true when the member has left. */
  completed_by: { name: string | null; former: boolean } | null
  completed_at: string | null
}

export interface StrategyMarketStep extends StrategyMark {
  market: string
  code: string
  state: StrategyStepState
  count: number
  marked: boolean
  warnings: StrategyWarning[]
}

export interface StrategySteps {
  usps: StrategyMark & {
    state: StrategyStepState
    count: number
    marked: boolean
    warnings: StrategyWarning[]
  }
  personas: {
    state: StrategyStepState
    markets_done: number
    markets_total: number
    markets: StrategyMarketStep[]
  }
  matrix: {
    state: StrategyStepState
    markets_done: number
    markets_total: number
    markets: StrategyMarketStep[]
  }
  concepts: {
    state: StrategyStepState
    markets_done: number
    markets_total: number
    markets: StrategyMarketStep[]
  }
}

export interface StrategyLanding {
  step: 'usps' | 'personas' | 'matrix' | 'concepts'
  /** Short code of the market, null for USPs. */
  market: string | null
  /** The market's ulid, null for USPs. */
  market_id: string | null
}

export interface StrategyNoteResource {
  id: string
  type: 'wave_strategy_notes'
  attributes: {
    step: 'usps' | 'personas' | 'matrix' | 'concepts'
    /** The market's ulid; null for USPs. */
    market: string | null
    body: string
    /** Null when the author has left: `author_is_former` is then true and no name is sent. */
    author: { id: string; name: string } | null
    author_is_former: boolean
    created_at: string
    edited: boolean
    edited_at: string | null
    can_edit: boolean
    can_delete: boolean
  }
}

export interface StrategyNoteListResponse {
  data: StrategyNoteResource[]
}
export interface StrategyNoteEnvelope {
  data: StrategyNoteResource
}

/** The answer to a mark or a reopen: the new steps block (and landing, counts), the live warnings on a mark. */
export interface StrategyCompletionResponse {
  data: Pick<StrategySummaryResource['attributes'], 'steps' | 'landing' | 'note_counts'>
  meta?: { warnings: StrategyWarning[] }
}

export interface StrategyNoteCounts {
  usps: number
  personas: Record<string, number>
  matrix: Record<string, number>
  concepts: Record<string, number>
}

export interface ConceptMarketCounts {
  market: string
  total: number
  approved: number
  awaiting: number
  changes_requested: number
  /** At least one concept and every one approved. */
  complete: boolean
  /** Fewer concepts than the recommended three (a warning, never a block). */
  under_minimum: boolean
}

export interface ConceptsSummary {
  total: number
  approved: number
  awaiting: number
  changes_requested: number
  markets_complete: number
  by_market: ConceptMarketCounts[]
}

/** A cell with a row counts as set, whatever its level (a Low cell is a decision). */
export interface MatrixProgress {
  cells_set: number
  cells_total: number
  by_market: { market: string; set: number; total: number }[]
}

export interface WaveUspResource {
  id: string
  type: 'wave_usp'
  attributes: {
    color_key: UspColorKey
    /** 1 to n, dense. The card number. */
    position: number
    title: string
    explainer: string | null
    creative_angle: string | null
    copy_seed: string | null
    /** How many persona angles use this USP. */
    angle_count: number
    /** How many matrix cells use this USP. */
    cell_count: number
    created_at: string
    updated_at: string
  }
}

export interface WaveAngleResource {
  id: string
  type: 'wave_persona_angle'
  attributes: {
    /** The USP's ulid. */
    usp: string
    usp_title: string
    color_key: UspColorKey
    explanation: string
    /** `matrix`: synced from a Priority or Support cell; read only on the persona card. */
    origin: 'manual' | 'matrix'
    read_only: boolean
    market: string | null
  }
}

export interface WavePersonaResource {
  id: string
  type: 'wave_persona'
  attributes: {
    market: string
    slot: number
    color_key: UspColorKey
    code: string
    title: string
    name: string
    pronoun: PersonaPronoun
    summary: string | null
    core_job: string | null
    need: string | null
    drivers: string[]
    goals: string[]
    self_talk: string[]
    barriers: string[]
    angles: WaveAngleResource[]
    /** One entry per set cell of the persona: the USP ulid, the level and the message line (to compare with its hand-written angles). */
    matrix_cells: { usp: string; level: MatrixLevel; message_line: string | null }[]
    created_at: string
    updated_at: string
  }
}

export interface StrategySummaryEnvelope {
  data: StrategySummaryResource
}
export interface WaveUspEnvelope {
  data: WaveUspResource
}
export interface WaveUspListResponse {
  data: WaveUspResource[]
}
export interface WavePersonaEnvelope {
  data: WavePersonaResource
}
export interface WavePersonaListResponse {
  data: WavePersonaResource[]
}
export interface WaveAngleEnvelope {
  data: WaveAngleResource
}

/** A partial body on PATCH: only the fields that changed. `null` clears a text field (never the title). */
export interface WaveUspPayload {
  title?: string
  explainer?: string | null
  creative_angle?: string | null
  copy_seed?: string | null
}

export interface WavePersonaPayload {
  /** Create only; a persona never changes market. */
  market?: string
  code?: string | null
  title?: string
  name?: string
  pronoun?: PersonaPronoun
  summary?: string | null
  core_job?: string | null
  need?: string | null
  drivers?: string[]
  goals?: string[]
  self_talk?: string[]
  barriers?: string[]
}

export interface PersonaCopyPayload {
  from_market: string
  to_market: string
  /** Omitted: every persona of the source market. */
  persona_ids?: string[]
}

/** What a 409 `usp.in_use` carries in `error.details[0].meta`, read through {@link uspInUseMeta}. */
export interface UspInUseAffected {
  /** A hand-written angle, a matrix cell (with its level), or (PL-2.3) a concept that carries the USP as a chip. */
  kind: 'angle' | 'cell' | 'concept'
  level: MatrixLevel | null
  persona: string
  market: string
  code: string
  title: string
  explanation: string
}

export interface UspInUseMeta {
  /** Angles plus matrix cells: the number `confirm_count` must carry. */
  count: number
  affected: UspInUseAffected[]
}

/** What a 422 `wave.market_has_personas` carries, read through {@link marketHasPersonasMeta}. */
export interface MarketHasPersonasMeta {
  /** `concept_count` (PL-2.3) is present only for a market that has concepts. */
  markets: {
    market: string
    name: string
    code: string
    persona_count: number
    concept_count?: number
  }[]
}

/** What a 422 `wave.market_has_notes` carries, read through {@link marketHasNotesMeta}. */
export interface MarketHasNotesMeta {
  markets: { market: string; name: string; code: string; note_count: number }[]
  /** The total to send back as `confirm_notes_deleted`. */
  note_count: number
}

/** What a 422 `persona.in_use_by_concepts` carries, read through {@link personaInUseByConceptsMeta}. */
export interface PersonaInUseByConceptsMeta {
  count: number
  affected: { concept: string; number: number; title: string; status: ConceptStatus }[]
}

/** What a 422 `persona.copy_exceeds_cap` carries. */
export interface CopyExceedsCapMeta {
  targetCount: number
  copyCount: number
  max: number
}

// --- The messaging matrix (PL-2.2) -------------------------------------------------------------------------

export type MatrixLevel = 'priority' | 'support' | 'low'

export interface MatrixUsp {
  id: string
  title: string
  color_key: UspColorKey
  position: number
}

export interface MatrixPersona {
  id: string
  slot: number
  color_key: UspColorKey
  code: string
  title: string
  name: string
  summary: string | null
}

/** One cell as the server returns it. `level` is null for a cell that is not set (after a clear). */
export interface MatrixCell {
  persona: string
  usp: string
  level: MatrixLevel | null
  rationale: string | null
  message_line: string | null
  source: string | null
  /** A hand-written angle already sits on this pair; the sync leaves it as written. */
  has_manual_angle: boolean
  /** Its id and its text (null when there is none), as the answer to a save returns them for the pair. */
  manual_angle: string | null
  manual_text: string | null
}

export interface MatrixResource {
  id: string
  type: 'wave_matrix'
  attributes: {
    market: string
    usps: MatrixUsp[]
    personas: MatrixPersona[]
    /** One entry per SET cell; "Not set" is absence. */
    cells: MatrixCell[]
    /** Every hand-written angle of the market's personas, set cell or not: the editor prefills from it, the note compares with it. */
    manual_angles: MatrixManualAngle[]
    progress: MatrixProgress
  }
}

/** A hand-written angle on a persona and USP of the market (its id and its text). */
export interface MatrixManualAngle {
  persona: string
  usp: string
  angle: string
  text: string
}

export interface MatrixEnvelope {
  data: MatrixResource
}

/** One change of `PUT matrix/cells`: only the fields that changed; `clear` carries nothing else. */
export interface MatrixCellChange {
  persona: string
  usp: string
  level?: MatrixLevel
  rationale?: string | null
  message_line?: string | null
  clear?: boolean
}

export interface MatrixSaveEnvelope {
  data: { cells: MatrixCell[]; progress: MatrixProgress }
}

// --- Concepts (PL-2.3) -------------------------------------------------------------------------------------

export type ConceptStatus = 'awaiting' | 'approved' | 'changes_requested'
export type FunnelStage = 'tofu' | 'mofu' | 'bofu'

export interface ConceptChip {
  id: string
  title: string
  color_key: UspColorKey
}

export interface ConceptImage {
  id: string
  original_filename: string
  mime_type: string
  size_bytes: number | null
  has_thumbnail: boolean
  /** API paths, fetched with the app's own authentication. Never a storage URL. */
  file_url: string
  thumbnail_url: string | null
}

export interface ConceptLink {
  id: string
  url: string
  title: string
  /** Derived by the server from the stored URL. */
  domain: string
}

export interface ConceptDecision {
  id: string
  status: 'approved' | 'changes_requested'
  client_name: string
  note: string | null
  comment: string | null
  source: string
  recorded_at: string
  recorded_by: { id: string; name: string } | null
}

export interface WaveConceptResource {
  id: string
  type: 'wave_concept'
  attributes: {
    market: string
    number: number
    title: string
    status: ConceptStatus
    persona: { id: string; code: string; title: string; name: string; color_key: UspColorKey }
    funnel_stage: FunnelStage | null
    funnel_note: string | null
    product: string | null
    angle: string | null
    primary_message: string | null
    hypothesis: string | null
    hook: string | null
    format_visual: string | null
    key_points: string[]
    payoff: string | null
    cta: string | null
    usps: ConceptChip[]
    images: ConceptImage[]
    links: ConceptLink[]
    decision: ConceptDecision | null
    reset_to_awaiting: boolean
    created_at: string
    updated_at: string
  }
}

export interface ConceptListResponse {
  data: WaveConceptResource[]
  meta: { market: ConceptMarketCounts | null }
}
export interface ConceptEnvelope {
  data: WaveConceptResource
}

/** A partial body on PATCH: only the fields that changed. `null` clears a text field. */
export interface ConceptPayload {
  market?: string
  persona?: string
  /** Required with a new `persona` on PATCH. */
  usp_mode?: 'keep' | 'refill'
  usps?: string[]
  title?: string
  funnel_stage?: FunnelStage | null
  funnel_note?: string | null
  product?: string | null
  angle?: string | null
  primary_message?: string | null
  hypothesis?: string | null
  hook?: string | null
  format_visual?: string | null
  key_points?: string[] | null
  payoff?: string | null
  cta?: string | null
}

export interface ConceptDecisionPayload {
  status: 'approved' | 'changes_requested'
  client_name: string
  note?: string | null
  comment?: string | null
}

export interface ConceptImageInitPayload {
  mime_type: string
  declared_bytes: number
  original_filename: string
}
export interface ConceptImageInitResponse {
  data: { upload_url: string; upload_id: string; expires_at: string; max_bytes: number }
}
export interface ConceptImageEnvelope {
  data: ConceptImage
  meta: { reset_to_awaiting: boolean }
}
export interface ConceptLinkPayload {
  url?: string
  title?: string
}
export interface ConceptLinkEnvelope {
  data: ConceptLink
  meta: { reset_to_awaiting: boolean }
}
/** What deleting an image or a link answers: whether the concept went back to Awaiting. */
export interface ConceptResetResponse {
  meta: { reset_to_awaiting: boolean }
}
