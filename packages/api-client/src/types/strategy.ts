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
}

export interface StrategyMarketCount {
  market: string
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
  }
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
    /** One entry per set cell of the persona: the USP ulid and the level. */
    matrix_cells: { usp: string; level: MatrixLevel }[]
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
  /** A hand-written angle, or a matrix cell (with its level). */
  kind: 'angle' | 'cell'
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
  markets: StrategyMarketCount[]
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
    progress: MatrixProgress
  }
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
