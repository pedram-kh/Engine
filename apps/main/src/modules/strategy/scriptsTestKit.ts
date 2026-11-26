/**
 * PL-3.2a — fixtures for the scripts web specs: a hook, a script, a rail creator, the overview, an archived script and the 409.
 */
import type {
  ArchivedScript,
  ScriptHook,
  ScriptPersonaRef,
  ScriptRailCreator,
  ScriptsOverview,
  WaveScript,
} from '@catalyst/api-client'
import { ApiError } from '@catalyst/api-client'

export const SCRIPT_ID = (n: number): string => `01SCRP${String(n).padStart(20, '0')}`
export const HOOK_ID = (n: number): string => `01HOOK${String(n).padStart(20, '0')}`
export const RAIL_SLOT = (n: number): string => `01SLOT${String(n).padStart(20, '0')}`

export const PERSONA_REF: ScriptPersonaRef = {
  id: '01PERSONA0000000000000001',
  code: 'SK-P1',
  title: 'The Office Lunch Organiser',
  slot: 1,
  color_key: 'blue',
}

export const LIMITS = {
  scripts_per_creator: 3,
  hooks_per_script: 6,
  lines_per_script: 30,
  broll_per_script: 12,
  images_per_block: 10,
  image_total_bytes: 300 * 1024 * 1024,
  local_languages_per_market: 3,
}

export function makeHook(n: number, over: Partial<ScriptHook> = {}): ScriptHook {
  return {
    id: HOOK_ID(n),
    position: n,
    letter: String.fromCharCode(64 + n),
    shot_type: null,
    visual_description: null,
    texts: { audio: {}, overlay: {} },
    images: [],
    revision: 1,
    ...over,
  }
}

export function makeScript(n = 1, over: Partial<WaveScript> = {}): WaveScript {
  return {
    id: SCRIPT_ID(n),
    number: n,
    revision: 1,
    archived: false,
    market: '01MARKETSK0000000000000001',
    slot: { id: RAIL_SLOT(1), slot_code: 'SK-C1' },
    creator: {
      id: '01CREA00000000000000000001',
      is_erased: false,
      display_name: 'Lucia Horváthová',
      primary_language: 'sk',
      secondary_languages: [],
    },
    brief: {
      persona: PERSONA_REF,
      usps: [
        {
          id: '01USP0000000000000000001',
          title: 'Selection',
          color_key: 'green',
          level: 'priority',
        },
        { id: '01USP0000000000000000002', title: 'Speed', color_key: 'orange', level: 'support' },
      ],
      angle: { value: 'The persona angle', source: 'matrix', custom: false },
      duration_seconds: 30,
      revision: 1,
    },
    languages: ['en'],
    hooks: [makeHook(1), makeHook(2), makeHook(3)],
    lines: [],
    usage: { hooks: 3, lines: 0, broll: 0, image_bytes: 0 },
    limits: LIMITS,
    estimate: { words: 40, seconds: 16 },
    updated_at: '2026-11-24T09:00:00.000000Z',
    ...over,
  }
}

export function makeRailCreator(
  n: number,
  scripts: number[] = [],
  over: Partial<ScriptRailCreator> = {},
): ScriptRailCreator {
  return {
    id: RAIL_SLOT(n),
    slot: n,
    slot_code: `SK-C${n}`,
    creator: {
      id: `01CREA${String(n).padStart(20, '0')}`,
      is_erased: false,
      display_name: `Creator ${n}`,
      primary_language: 'sk',
      secondary_languages: [],
    },
    persona: PERSONA_REF,
    scripts: scripts.map((s, i) => ({
      id: SCRIPT_ID(s),
      number: i + 1,
      hooks: 3,
      duration_seconds: 30,
      revision: 1,
      updated_at: '2026-11-24T09:00:00.000000Z',
    })),
    can_add_script: scripts.length < 3,
    ...over,
  }
}

export function makeOverview(
  market: string,
  creators: ScriptRailCreator[],
  over: { archived?: number } = {},
): ScriptsOverview {
  const scripts = creators.reduce((n, c) => n + c.scripts.length, 0)
  return {
    step: {
      state: scripts > 0 ? 'in_progress' : 'not_started',
      markets_total: 2,
      markets_started: scripts > 0 ? 1 : 0,
    },
    markets: [
      {
        id: market,
        state: scripts > 0 ? 'in_progress' : 'not_started',
        scripts,
        archived_scripts: over.archived ?? 0,
      },
    ],
    rail: {
      market,
      state: scripts > 0 ? 'in_progress' : 'not_started',
      creators,
      notes: 0,
    },
    limits: LIMITS,
  }
}

export function makeArchived(n: number, over: Partial<ArchivedScript> = {}): ArchivedScript {
  return {
    id: SCRIPT_ID(900 + n),
    number: n,
    hooks: 3,
    lines: 2,
    images: 1,
    creator: {
      id: `01CREA${String(900 + n).padStart(20, '0')}`,
      is_erased: false,
      display_name: `Gone Creator ${n}`,
      primary_language: 'sk',
      secondary_languages: [],
    },
    archived_at: '2026-11-20T09:00:00.000000Z',
    creator_in_market: false,
    can_restore: false,
    ...over,
  }
}

/** A 409 `wave_script.conflict`: the block as it is now (built from the real envelope, so `details[0].meta` is what the screen reads). */
export function conflict(block: 'brief' | 'hook' | 'line', current: unknown): ApiError {
  return ApiError.fromEnvelope(409, {
    errors: [{ code: 'wave_script.conflict', title: 'conflict', meta: { block, current } }],
  })
}

/** The answer to a hook save: the script with this hook changed and its revision moved on. */
export function afterHookSave(
  script: WaveScript,
  hookId: string,
  change: Partial<ScriptHook>,
): WaveScript {
  return {
    ...script,
    revision: script.revision + 1,
    hooks: script.hooks.map((h) =>
      h.id === hookId ? { ...h, ...change, revision: h.revision + 1 } : h,
    ),
  }
}
