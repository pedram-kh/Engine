/**
 * Shared fixtures for the PL-2.1 specs: a strategy summary with the server's limits, USPs, personas and angles,
 * and a mount helper on top of the wave test kit (Pinia, router with the strategy routes, English i18n, Vuetify).
 */
import type {
  ConceptStatus,
  MatrixCell,
  MatrixLevel,
  MatrixPersona,
  MatrixManualAngle,
  MatrixResource,
  MatrixUsp,
  StrategyLimits,
  StrategySummaryResource,
  StrategyMarketCount,
  UspColorKey,
  WaveAngleResource,
  WaveConceptResource,
  WavePersonaResource,
  WaveUspResource,
} from '@catalyst/api-client'
import type { Component } from 'vue'

import { mountWithApp, type MountKitOptions } from '@/modules/waves/waveTestKit'

export { AGENCY_ULID, BRAND_ULID, makeWave, WAVE_ULID } from '@/modules/waves/waveTestKit'

export const LIMITS: StrategyLimits = {
  usp_max: 6,
  usp_warn_below: 4,
  persona_min: 2,
  persona_max: 8,
  audience_title: 120,
  usp_title: 120,
  usp_explainer: 1000,
  usp_creative_angle: 1000,
  usp_copy_seed: 1000,
  persona_code_min: 2,
  persona_code_max: 8,
  persona_title: 120,
  persona_name: 80,
  persona_summary: 500,
  persona_paragraph: 1000,
  list_items: 8,
  list_item: 200,
  angle_explanation: 500,
  matrix_line: 140,
  matrix_batch_max: 48,
  concept_max_per_market: 30,
  concept_min_recommended: 3,
  concept_title: 120,
  concept_funnel_note: 40,
  concept_product: 160,
  concept_angle: 300,
  concept_primary_message: 300,
  concept_hypothesis: 400,
  concept_hook: 300,
  concept_format_visual: 400,
  concept_payoff: 300,
  concept_cta: 200,
  concept_key_points: 8,
  concept_key_point: 160,
  client_name: 80,
  decision_note: 500,
  decision_comment: 1000,
  link_title: 120,
  links_per_concept: 10,
  images_per_concept: 10,
  image_total_bytes: 300 * 1024 * 1024,
}

/** A market of the summary. The id is opaque to the screens; the tests use the country code for it. */
export function mkt(
  code: string,
  personas = 0,
  name?: string,
  countries?: string[],
): StrategyMarketCount {
  return {
    market: code,
    name:
      name ??
      ({ SK: 'Slovakia', RO: 'Romania', CZ: 'Czechia', IE: 'Ireland' } as Record<string, string>)[
        code
      ] ??
      code,
    code,
    countries: countries ?? [code],
    persona_count: personas,
  }
}

export function makeSummary(
  over: Partial<StrategySummaryResource['attributes']> = {},
): StrategySummaryResource {
  return {
    id: '01WAVEULIDXXXXXXXXXXXXXXX',
    type: 'wave_strategy',
    attributes: {
      usp_count: 0,
      audience_title: null,
      persona_count: 0,
      markets: [mkt('SK', 0), mkt('RO', 0)],
      limits: LIMITS,
      matrix: { cells_set: 0, cells_total: 0, by_market: [] },
      concepts: {
        total: 0,
        approved: 0,
        awaiting: 0,
        changes_requested: 0,
        markets_complete: 0,
        by_market: [],
      },
      // PL-2.5 (contract only; the screens read these in Stage 2): an empty wave, nothing started, landing on USPs.
      steps: {
        usps: {
          state: 'not_started',
          count: 0,
          marked: false,
          warnings: [],
          completed_by: null,
          completed_at: null,
        },
        personas: { state: 'not_started', markets_done: 0, markets_total: 2, markets: [] },
        matrix: { state: 'not_started', markets_done: 0, markets_total: 2, markets: [] },
        concepts: { state: 'not_started', markets_done: 0, markets_total: 2, markets: [] },
      },
      landing: { step: 'usps', market: null, market_id: null },
      note_counts: { usps: 0, personas: {}, matrix: {}, concepts: {} },
      ...over,
    },
  }
}

const COLOURS: UspColorKey[] = ['violet', 'green', 'blue', 'amber', 'teal', 'pink']

export function makeUsp(
  n: number,
  over: Partial<WaveUspResource['attributes']> = {},
): WaveUspResource {
  return {
    id: `01USP${String(n).padStart(21, '0')}`,
    type: 'wave_usp',
    attributes: {
      color_key: COLOURS[(n - 1) % 6] ?? 'violet',
      position: n,
      title: `USP ${n}`,
      explainer: null,
      creative_angle: null,
      copy_seed: null,
      angle_count: 0,
      cell_count: 0,
      created_at: '2026-11-01T10:00:00.000000Z',
      updated_at: '2026-11-01T10:00:00.000000Z',
      ...over,
    },
  }
}

export function makeUsps(count: number): WaveUspResource[] {
  return Array.from({ length: count }, (_, i) => makeUsp(i + 1))
}

export function makeAngle(
  usp: WaveUspResource,
  explanation = 'Because it is quick',
): WaveAngleResource {
  return {
    id: `01ANG${usp.id.slice(5, 25)}`,
    type: 'wave_persona_angle',
    attributes: {
      usp: usp.id,
      usp_title: usp.attributes.title,
      color_key: usp.attributes.color_key,
      explanation,
      origin: 'manual',
      read_only: false,
      market: null,
    },
  }
}

export function makePersona(
  n: number,
  market: string,
  over: Partial<WavePersonaResource['attributes']> = {},
): WavePersonaResource {
  return {
    id: `01PER${String(n).padStart(21, '0')}`,
    type: 'wave_persona',
    attributes: {
      market,
      slot: n,
      color_key: COLOURS[(n - 1) % 6] ?? 'violet',
      code: `${market}${String.fromCharCode(64 + n)}`,
      title: `Persona ${n}`,
      name: `Name ${n}`,
      pronoun: 'she',
      summary: null,
      core_job: null,
      need: null,
      drivers: [],
      goals: [],
      self_talk: [],
      barriers: [],
      angles: [],
      matrix_cells: [],
      created_at: '2026-11-01T10:00:00.000000Z',
      updated_at: '2026-11-01T10:00:00.000000Z',
      ...over,
    },
  }
}

/** An angle synced from a matrix cell: read only on the persona card. */
export function makeSyncedAngle(
  usp: WaveUspResource,
  explanation = 'Lead line',
  market = 'SK',
): WaveAngleResource {
  const a = makeAngle(usp, explanation)
  return {
    ...a,
    id: `01SYN${usp.id.slice(5, 25)}`,
    attributes: { ...a.attributes, origin: 'matrix', read_only: true, market },
  }
}

export function makeMatrixCell(
  persona: WavePersonaResource,
  usp: WaveUspResource,
  level: MatrixLevel | null,
  over: Partial<MatrixCell> = {},
): MatrixCell {
  return {
    persona: persona.id,
    usp: usp.id,
    level,
    rationale: level === null ? null : 'Because it matters',
    message_line: level === null || level === 'low' ? null : 'Lead line',
    source: 'manual',
    has_manual_angle: false,
    manual_angle: null,
    manual_text: null,
    ...over,
  }
}

/** One market's matrix as `GET matrix` returns it. */
export function makeMatrix(
  market: string,
  usps: WaveUspResource[],
  personas: WavePersonaResource[],
  cells: MatrixCell[] = [],
  manualAngles: MatrixManualAngle[] = [],
): MatrixResource {
  const u: MatrixUsp[] = usps.map((x) => ({
    id: x.id,
    title: x.attributes.title,
    color_key: x.attributes.color_key,
    position: x.attributes.position,
  }))
  const p: MatrixPersona[] = personas.map((x) => ({
    id: x.id,
    slot: x.attributes.slot,
    color_key: x.attributes.color_key,
    code: x.attributes.code,
    title: x.attributes.title,
    name: x.attributes.name,
    summary: x.attributes.summary,
  }))
  return {
    id: `01WAVEULIDXXXXXXXXXXXXXXX:${market}`,
    type: 'wave_matrix',
    attributes: {
      market,
      usps: u,
      personas: p,
      cells,
      manual_angles: manualAngles,
      progress: { cells_set: cells.length, cells_total: u.length * p.length, by_market: [] },
    },
  }
}

/** A concept as `GET concepts` returns it; the persona and chips are the fixtures' own. */
export function makeConcept(
  n: number,
  persona: WavePersonaResource,
  over: Partial<WaveConceptResource['attributes']> = {},
  status: ConceptStatus = 'awaiting',
): WaveConceptResource {
  return {
    id: `01CON${String(n).padStart(21, '0')}`,
    type: 'wave_concept',
    attributes: {
      market: persona.attributes.market,
      number: n,
      title: `Concept ${n}`,
      status,
      persona: {
        id: persona.id,
        code: persona.attributes.code,
        title: persona.attributes.title,
        name: persona.attributes.name,
        color_key: persona.attributes.color_key,
      },
      funnel_stage: null,
      funnel_note: null,
      product: null,
      angle: null,
      primary_message: null,
      hypothesis: null,
      hook: null,
      format_visual: null,
      key_points: [],
      payoff: null,
      cta: null,
      usps: [],
      images: [],
      links: [],
      decision: null,
      reset_to_awaiting: false,
      created_at: '2026-11-01T10:00:00.000000Z',
      updated_at: '2026-11-01T10:00:00.000000Z',
      ...over,
    },
  }
}

export async function mountStrategy(
  component: Component,
  props: Record<string, unknown>,
  options: MountKitOptions = {},
): Promise<ReturnType<typeof import('@vue/test-utils').mount>> {
  return mountWithApp(component, props, {
    strategy: true,
    route: '/brands/01BRANDULIDXXXXXXXXXXXXXX/waves/01WAVEULIDXXXXXXXXXXXXXXX/strategy/usps',
    ...options,
  })
}

/** What a Vuetify dialog teleports to the body: the text of the open overlays. */
export function overlayText(): string {
  return document.body.textContent ?? ''
}

export function overlay(selector: string): HTMLElement | null {
  return document.body.querySelector<HTMLElement>(selector)
}
